<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The missions of a driver: one row for every mission of a set that was drawn for them (and later for every main mission that opened), and one for every set,
 * which holds the bonus for having done all of it. A row keeps what the mission was when it was drawn (the goal and the reward), so that a change of the rules does
 * not change a mission that is under way. The progress is only ever raised; when a mission is done and when it was taken are the two timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_missions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // main, daily, weekly or monthly
            $table->string('kind', 8);
            // The day (2026-10-30), the ISO week (2026-W44) or the month (2026-10) of the set; the key of the chain for a main mission.
            $table->string('period_key', 32);
            // The place in the set, or in the chain.
            $table->unsignedSmallInteger('slot');
            $table->string('mission_key', 64);
            $table->string('objective', 24);
            $table->json('params')->nullable();
            $table->unsignedBigInteger('target');
            $table->unsignedBigInteger('progress')->default(0);
            // What the mission pays, as it was when it was drawn: cash_cents, xp, car_xp and items.
            $table->json('reward');
            // The window of the period in ms of the clock of the game (null for a main mission, which counts all the time).
            $table->bigInteger('window_from_ms')->nullable();
            $table->bigInteger('window_to_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            // Taken by the server after the period ended, not by the driver.
            $table->boolean('auto_claimed')->default(false);
            // What taking it gave: cash, XP and the parts (ids), for the screen that tells it.
            $table->json('claim_result')->nullable();
            // The period is over and the mission was brought up to date for the last time.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'period_key', 'slot']);
            $table->index(['user_id', 'claimed_at']);
            $table->index(['kind', 'period_key']);
        });

        Schema::create('user_mission_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('period_key', 32);
            $table->unsignedSmallInteger('missions');
            // The premium coins the whole set pays, as the rules said when it was drawn (0: none).
            $table->unsignedInteger('bonus_coins')->default(0);
            $table->bigInteger('window_from_ms');
            $table->bigInteger('window_to_ms');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('bonus_claimed_at')->nullable();
            $table->boolean('bonus_auto_claimed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mission_periods');
        Schema::dropIfExists('user_missions');
    }
};
