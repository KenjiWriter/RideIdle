<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a driver came to the game: one row for every visit (the first request after a break of ten minutes, see TouchLastSeen). It is all the game needs to
 * tell whether the drivers come back (the day after, a week after) and how long they stay; nothing else about what they do is written down for that, and
 * no third party sees any of it. It goes with the account when the account is erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('at');

            // Minutes since the visit before, null for the first one.
            $table->unsignedInteger('gap_minutes')->nullable();

            $table->index(['user_id', 'at']);
            $table->index('at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_visits');
    }
};
