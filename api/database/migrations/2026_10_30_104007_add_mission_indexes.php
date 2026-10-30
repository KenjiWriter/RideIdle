<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The missions count what a driver did in a window of time out of the facts the game keeps: the cash they spent on fuel and on parts (the ledger, by reason and
 * time) and the laps they drove (the settlements of their runs, by where they ended). The two indexes keep those questions from reading the whole history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->index(['user_id', 'reason', 'created_at'], 'ledger_entries_user_reason_time');
        });

        Schema::table('settlements', function (Blueprint $table): void {
            $table->index(['race_run_id', 'cursor_to_ms'], 'settlements_run_end');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropIndex('settlements_run_end');
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ledger_entries_user_reason_time');
        });
    }
};
