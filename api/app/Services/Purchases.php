<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\RuleRefusedException;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

/**
 * The book of the purchases of premium coins with real money. A payment provider tells the game that a payment was made (`fulfil`) or taken back (`refund`);
 * both are safe to be told twice, because providers retry. The coins move in the same transaction as the record, through the ledger.
 *
 * No provider is connected yet: the next steps add the one that takes the card. Until then this is called by tests and by the people who run the game.
 */
final class Purchases
{
    public function __construct(private readonly Ledger $ledger, private readonly Rules $rules) {}

    /**
     * Records a paid purchase of a pack and gives the driver its coins. The price that is recorded is the one of the catalog now, unless the provider says what
     * was charged (`$amountMinor`, `$currency`): the record is what was paid, and the catalog may change later.
     *
     * Told again with the same payment, it returns the purchase that is there and gives nothing more.
     *
     * @throws RuleRefusedException `product_not_found` when the rules do not sell the pack
     * @throws IdempotencyConflictException when the payment was recorded for another driver or another pack
     */
    public function fulfil(int $userId, string $product, string $provider, string $providerRef, ?int $amountMinor = null, ?string $currency = null): Purchase
    {
        $pack = $this->rules->premiumProduct($product) ?? throw new RuleRefusedException('product_not_found');

        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $product, $provider, $providerRef, $amountMinor, $currency, $pack): Purchase {
            // The same lock as every posting of the driver, so two reports of one payment cannot both get past the check below.
            $this->ledger->lockWallet($userId);

            $existing = Purchase::query()->where('provider', $provider)->where('provider_ref', $providerRef)->first();

            if ($existing !== null) {
                if ($existing->user_id !== $userId || $existing->product !== $product) {
                    throw new IdempotencyConflictException("{$provider}:{$providerRef}");
                }

                return $existing;
            }

            $purchase = Purchase::query()->create([
                'user_id' => $userId,
                'product' => $product,
                'coins' => $pack['coins'],
                'amount_minor' => $amountMinor ?? $pack['price_minor'],
                'currency' => $currency ?? (string) $this->rules->premiumPriceCurrency(),
                'provider' => $provider,
                'provider_ref' => $providerRef,
                'status' => Purchase::STATUS_PAID,
            ]);

            $this->ledger->postPremium($userId, $pack['coins'], 'purchase', self::key('purchase', $provider, $providerRef));

            return $purchase;
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Records that a payment was taken back (a refund, a chargeback) and takes its coins away. When the driver has spent them, the balance goes below zero:
     * they owe the difference, and the next purchase pays it off. Told again, it changes nothing.
     */
    public function refund(Purchase $purchase): Purchase
    {
        if ($purchase->user_id === null) {
            // The account was erased: the record stays, and there are no coins left to take.
            return $this->markRefunded($purchase);
        }

        $userId = (int) $purchase->user_id;
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($purchase, $userId): Purchase {
            $this->ledger->lockWallet($userId);
            $purchase->refresh();

            if ($purchase->status === Purchase::STATUS_REFUNDED) {
                return $purchase;
            }

            $this->ledger->postPremium($userId, -$purchase->coins, 'refund', self::key('refund', $purchase->provider, $purchase->provider_ref), allowDebt: true);

            return $this->markRefunded($purchase);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    private function markRefunded(Purchase $purchase): Purchase
    {
        if ($purchase->status !== Purchase::STATUS_REFUNDED) {
            $purchase->forceFill(['status' => Purchase::STATUS_REFUNDED, 'refunded_at' => now()])->save();
        }

        return $purchase;
    }

    /** The key of a posting for a payment: short enough for the ledger, and the same for the same payment. */
    private static function key(string $kind, string $provider, string $providerRef): string
    {
        $key = "{$kind}:{$provider}:{$providerRef}";

        return strlen($key) <= Ledger::MAX_KEY_LENGTH ? $key : "{$kind}:".hash('sha256', "{$provider}:{$providerRef}");
    }
}
