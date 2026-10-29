<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paid extras of a driver (G1 of the paid features), on the wallet next to the coins: the stock of charges of the auto-refill (the ledger keeps it as a third
 * currency, `refill`), whether the driver wants the tank filled for them, and until when the VIP account and the pass of the endless refill last.
 *
 * Plain columns with defaults, so a deploy needs nothing else: a wallet that was there before has no charges, the preference on, and neither of the two accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->unsignedInteger('auto_refills')->default(0)->after('premium_coins');
            $table->boolean('auto_refill_enabled')->default(true)->after('auto_refills');
            $table->timestamp('vip_until')->nullable()->after('auto_refill_enabled');
            $table->timestamp('infinity_until')->nullable()->after('vip_until');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn(['auto_refills', 'auto_refill_enabled', 'vip_until', 'infinity_until']);
        });
    }
};
