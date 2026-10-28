<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The content of the game in the database (F4, step S1): definitions with versions that are only added to, packs that name one version of each definition,
 * and the activations that say which pack the game reads. The documents are kept as the text of their JSON (a long text, not a JSON column), so that what is
 * read is what was written, key by key, on MariaDB and on PostgreSQL alike; nothing is ever looked up inside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_definitions', function (Blueprint $table): void {
            $table->id();
            $table->char('uid', 26)->unique();     // a ULID: the identity that never changes, whatever the slug is called in the pack
            $table->string('kind', 16);            // vehicle, part, rules or table
            $table->string('slug', 64);            // never renamed and never used again
            $table->timestamps();

            $table->unique(['kind', 'slug']);
        });

        Schema::create('content_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_definition_id')->constrained('content_definitions')->restrictOnDelete();
            $table->unsignedInteger('number');     // 1, 2, 3 ... of this definition
            $table->longText('document');          // the JSON as it was written
            $table->char('checksum', 64);          // sha256 of the document without its white space
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['content_definition_id', 'number']);
            $table->index(['content_definition_id', 'checksum']);
        });

        Schema::create('content_packs', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique(); // 2026.10.3: a pack is an immutable snapshot, so a number is never used for two different contents
            $table->char('checksum', 64);
            $table->longText('manifest');           // pack.json as it was written
            $table->longText('report')->nullable(); // the warnings of the validation that let it in
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('content_pack_items', function (Blueprint $table): void {
            $table->foreignId('content_pack_id')->constrained('content_packs')->restrictOnDelete();
            $table->foreignId('content_version_id')->constrained('content_versions')->restrictOnDelete();

            $table->primary(['content_pack_id', 'content_version_id']);
        });

        // Only added to: the pack that the game reads is the one of the last row, so going back is another row that points to an earlier pack.
        Schema::create('content_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_pack_id')->constrained('content_packs')->restrictOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_activations');
        Schema::dropIfExists('content_pack_items');
        Schema::dropIfExists('content_packs');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('content_definitions');
    }
};
