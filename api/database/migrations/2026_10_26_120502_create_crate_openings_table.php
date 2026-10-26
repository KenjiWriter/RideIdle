<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The crates a driver opened, for the audit of the draw and for the counters of the guarantees: what was drawn, and from what.
        Schema::create('crate_openings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('kind', 16);
            // The odds and the guarantee are those of a group (standard, premium), and the draw is made from the place among its openings.
            $table->string('pity_group', 16);
            $table->unsignedInteger('opening_index');
            $table->string('slot', 32);
            $table->unsignedTinyInteger('tier');
            $table->unsignedTinyInteger('rarity');
            $table->string('part_slug', 64);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('part_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'pity_group', 'opening_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crate_openings');
    }
};
