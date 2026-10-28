<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The break the driver has not been told about yet: it starts when they left (the last visit before the break) and ends when they came back, and it stays until
 * they say they have seen the summary of it (`AwaySummary`). A break that follows another one before the driver looked is joined to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('away_from')->nullable()->after('previous_seen_at');
            $table->timestamp('away_to')->nullable()->after('away_from');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['away_from', 'away_to']);
        });
    }
};
