<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the panel asks of the server about the domain of a game: to set it up (the site of nginx and a certificate) or to look at its certificate. The panel only writes a
 * row here; a worker on the server, which is the only thing that may touch nginx and the certificates, claims it, does it and reports back, so the web process never needs
 * a right that it should not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            // The host that was asked for, as it was then: the worker checks it against the game before it does anything.
            $table->string('domain', 253);
            // `provision` sets the domain up, `check` only reads the end date of its certificate.
            $table->string('action', 16);
            // `requested` waits for the worker, `running` was claimed by it, `done` and `failed` are its report.
            $table->string('status', 16)->default('requested');
            // The address that Let's Encrypt writes to, as it was when the job was asked for.
            $table->string('email', 190)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('exit_code')->nullable();
            // The end of what the worker said, kept for the person who asked.
            $table->text('log')->nullable();
            $table->date('certificate_expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['game_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_jobs');
    }
};
