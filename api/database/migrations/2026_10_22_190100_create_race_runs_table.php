<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A race run: a car driving laps on a track. It stores the state that settlement changes
 * (cursor, laps, fuel) and the configuration frozen when the run started or last changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Slugs of the definitions in the content pack. Owned cars and parts get their own tables later.
            $table->string('car_slug', 64);
            $table->string('track_slug', 64);

            $table->string('status', 16)->default('running'); // running or stopped
            $table->string('stop_reason', 16)->nullable();    // fuel_empty or breakdown

            // State changed by settlement (all integers).
            $table->bigInteger('cursor_ms');
            $table->bigInteger('laps_done')->default(0);
            $table->bigInteger('fuel_ml');
            $table->bigInteger('break_after_laps')->nullable();

            // Seed of this configuration (hex of 16 random bytes) and the frozen numbers.
            $table->char('seed', 32);
            $table->json('config');
            $table->string('ruleset_version', 32)->default('dev');

            // Counts the writes. Together with the run id it identifies one settlement exactly.
            $table->bigInteger('state_version')->default(0);

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_runs');
    }
};
