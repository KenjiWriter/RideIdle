<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\RuleRefusedException;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

/**
 * The book of the purchases with real money: packs of premium coins, and the pass of the endless refill. A payment provider tells the game that a payment was made (`fulfil`) or taken back (`refund`);
 * both are safe to be told twice, because providers retry. The coins move in the same transaction as the record, through the ledger.
 *
 * No provider is connected yet: the next steps add the one that takes the card. Until then this is called by tests and by the people who run the game.
 */
final class Purchases
{
    public function __construct(private readonly Ledger $ledger, private readonly Rules $rules, private readonly Entitlements $entitlements) {}

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
     * Records a pass of the endless refill that was paid for (or given by the people who run the game: then the amount is 0) and adds its days to the end of the
     * pass the driver has. There are no coins in it: the record has `coins` 0 and the product `infinity`. The cars of the driver are settled first, so the pass
     * counts from now on.
     *
     * Told again with the same payment, it returns the purchase that is there and gives nothing more.
     *
     * @param  int|null  $days  how long it lasts; the days of the rules when left out
     *
     * @throws RuleRefusedException `infinity_unavailable` when the rules do not sell the pass or have switched it off
     * @throws IdempotencyConflictException when the payment was recorded for another driver or another product
     */
    public function fulfilPass(int $userId, string $provider, string $providerRef, ?int $days = null, ?int $amountMinor = null, ?string $currency = null): Purchase
    {
        $pass = $this->rules->infinity();

        if (! $this->rules->infinityOn() || $pass === null) {
            throw new RuleRefusedException('infinity_unavailable');
        }

        $this->ledger->ensureWallet($userId);
        $this->entitlements->settleRuns($userId);

        return DB::transaction(function () use ($userId, $provider, $providerRef, $days, $amountMinor, $currency, $pass): Purchase {
            $wallet = $this->ledger->lockWallet($userId);

            $existing = Purchase::query()->where('provider', $provider)->where('provider_ref', $providerRef)->first();

            if ($existing !== null) {
                if ($existing->user_id !== $userId || $existing->product !== Entitlements::PASS_PRODUCT) {
                    throw new IdempotencyConflictException("{$provider}:{$providerRef}");
                }

                return $existing;
            }

            $purchase = Purchase::query()->create([
                'user_id' => $userId,
                'product' => Entitlements::PASS_PRODUCT,
                'coins' => 0,
                'amount_minor' => $amountMinor ?? $pass['price_minor'],
                'currency' => $currency ?? (string) $this->rules->premiumPriceCurrency(),
                'provider' => $provider,
                'provider_ref' => $providerRef,
                'status' => Purchase::STATUS_PAID,
            ]);

            $this->entitlements->extendInfinity($wallet, $days ?? $pass['days']);

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
            $wallet = $this->ledger->lockWallet($userId);
            $purchase->refresh();

            if ($purchase->status === Purchase::STATUS_REFUNDED) {
                return $purchase;
            }

            if ($purchase->product === Entitlements::PASS_PRODUCT) {
                // A pass has no coins to take back: it ends now.
                $this->entitlements->endInfinity($wallet);
            } else {
                $this->ledger->postPremium($userId, -$purchase->coins, 'refund', self::key('refund', $purchase->provider, $purchase->provider_ref), allowDebt: true);
            }

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
