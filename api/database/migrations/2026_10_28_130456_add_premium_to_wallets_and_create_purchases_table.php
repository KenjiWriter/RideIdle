<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The premium currency (P0 of the paid features): a balance next to the cash and the XP of the wallet, and the book of the purchases made with real money.
 *
 * The balance has no CHECK of being non-negative, on purpose: a payment that is taken back (a refund, a chargeback) after the coins were spent leaves a debt,
 * which the ledger writes as a negative balance, and the next purchase pays it off. Spending never takes the balance below zero; the code checks that.
 *
 * A purchase is a financial record the shop has to keep (accounting), so it is not erased with the account: the account is cut from it (user_id becomes null),
 * and what stays is what was bought, for how much and the reference of the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->bigInteger('premium_coins')->default(0)->after('xp');
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product', 64);
            $table->unsignedInteger('coins');
            // The price in the smallest unit of the currency (grosze), as it was charged: the catalog may change later.
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('provider', 32);
            // The id of the payment at the provider: the same payment can be reported twice (a webhook is retried), and it must be paid out once.
            $table->string('provider_ref', 128);
            $table->string('status', 16);
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn('premium_coins');
        });
    }
};
