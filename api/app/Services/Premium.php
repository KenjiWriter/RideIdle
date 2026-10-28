<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\RuleRefusedException;
use App\Models\LedgerEntry;
use App\Models\Purchase;

/**
 * The premium currency of a driver: what they have, what the shop sells, and the two ways the coins move that do not need a payment: a gift (support, a
 * promotion) and spending (what the coins buy comes in the next steps). A purchase with real money is `Purchases`.
 *
 * The coins move only through the ledger (`Ledger::postPremium`), under the lock of the wallet, so a repeated request moves them once.
 */
final class Premium
{
    /** How many of the last purchases the shop shows. */
    public const RECENT_PURCHASES = 10;

    public function __construct(private readonly Ledger $ledger, private readonly Rules $rules) {}

    public function balance(int $userId): int
    {
        return $this->ledger->premiumBalance($userId);
    }

    /**
     * What the shop of the premium currency shows a driver: the balance, the currency, the packs that are sold with their price and bonus, and the last purchases.
     * When the rules sell nothing, `currency` and `products` say so (null and empty), and the balance is still the driver's.
     *
     * @return array{balance: int, currency: array{code: string, name: string}|null, price_currency: string|null, products: list<array<string, mixed>>, purchases: list<array<string, mixed>>}
     */
    public function view(int $userId): array
    {
        $products = [];

        foreach ($this->rules->premiumProducts() as $slug => $product) {
            $products[] = ['slug' => $slug, ...$product];
        }

        $purchases = Purchase::query()->where('user_id', $userId)->orderByDesc('id')->limit(self::RECENT_PURCHASES)->get()
            ->map(fn (Purchase $purchase): array => [
                'product' => $purchase->product,
                'coins' => $purchase->coins,
                'amount_minor' => $purchase->amount_minor,
                'currency' => $purchase->currency,
                'status' => $purchase->status,
                'at' => $purchase->created_at?->toIso8601String(),
            ])->all();

        return [
            'balance' => $this->balance($userId),
            'currency' => $this->rules->premiumCurrency(),
            'price_currency' => $this->rules->premiumPriceCurrency(),
            'products' => $products,
            'purchases' => $purchases,
        ];
    }

    /**
     * Gives coins that were not paid for: support making up for a fault, a promotion. The key makes the gift happen once.
     *
     * @throws IdempotencyConflictException when the key was used for a different posting
     */
    public function grant(int $userId, int $coins, string $reason, string $idemKey): LedgerEntry
    {
        return $this->ledger->postPremium($userId, abs($coins), $reason, $idemKey);
    }

    /**
     * Takes coins for something the driver chose to buy with them. The caller names what for (`$reason`) and makes the key one intention.
     *
     * @throws RuleRefusedException `insufficient_premium` when the driver does not have them (nothing is changed)
     * @throws IdempotencyConflictException when the key was used for a different posting
     */
    public function spend(int $userId, int $coins, string $reason, string $idemKey): LedgerEntry
    {
        return $this->ledger->postPremium($userId, -abs($coins), $reason, $idemKey);
    }
}
