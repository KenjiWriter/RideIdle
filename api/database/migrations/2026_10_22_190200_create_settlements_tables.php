<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per settlement that changed something, and its log. The summary screen, notifications and
 * telemetry all read the same events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_run_id')->constrained()->cascadeOnDelete();

            // The version of the run before this settlement: (run, version) identifies it, so it cannot be recorded twice.
            $table->bigInteger('state_version');
            $table->bigInteger('cursor_from_ms');
            $table->bigInteger('cursor_to_ms');
            $table->bigInteger('now_ms');
            $table->bigInteger('laps');
            $table->bigInteger('cash_cents');
            $table->bigInteger('xp');
            $table->string('stop_reason', 16)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['race_run_id', 'state_version']);
        });

        Schema::create('settlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('type', 32);
            $table->bigInteger('at_ms');
            $table->json('payload');

            $table->unique(['settlement_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_events');
        Schema::dropIfExists('settlements');
    }
};
