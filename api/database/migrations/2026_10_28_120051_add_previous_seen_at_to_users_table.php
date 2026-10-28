<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visit before the last one: when a driver is seen again, the moment they were seen before is kept next to the new one, so that the step of the tutorial
 * that waits for the driver to come back after a break can tell how long the break was (a day of the game is no break: the last visit moves every ten minutes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('previous_seen_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('previous_seen_at');
        });
    }
};
