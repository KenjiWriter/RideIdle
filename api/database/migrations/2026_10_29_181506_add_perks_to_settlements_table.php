<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the paid extras did in a settlement (G2 of the paid features): how much of its cash and XP was the bonus of the VIP account (`cash_cents` and `xp` are what was
 * paid, the bonus included), and how many times the tank was filled for the driver and how many charges that took (the pass makes the rest free). The events of the
 * settlement say when; these columns are for the sums, and are zero for the settlements that were made before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->bigInteger('bonus_cash_cents')->default(0)->after('xp');
            $table->bigInteger('bonus_xp')->default(0)->after('bonus_cash_cents');
            $table->unsignedInteger('auto_refills')->default(0)->after('bonus_xp');
            $table->unsignedInteger('refill_charges')->default(0)->after('auto_refills');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn(['bonus_cash_cents', 'bonus_xp', 'auto_refills', 'refill_charges']);
        });
    }
};
