<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RuleRefusedException;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way to change a balance of the wallet: the cash, and the premium coins (the same rules, a column and a currency each).
 *
 * Every posting runs in a transaction that first locks the wallet row (SELECT ... FOR UPDATE), so postings
 * of one driver are processed one after another on every database. A posting carries an idempotency key:
 * repeating it (a retried request, a job run twice) returns the first entry and changes nothing.
 * The unique index on the key is the last line of defence if a caller ever skips the lock.
 */
final class Ledger
{
    public const MAX_KEY_LENGTH = 128;

    public const CASH = 'cash';

    public const PREMIUM = 'premium';

    /** The stock of charges of the auto-refill: a pack adds them, a refill of the tank done for the driver takes one. */
    public const REFILL = 'refill';

    /** The column of the wallet that holds the balance of each currency. */
    private const COLUMNS = [self::CASH => 'cash_cents', self::PREMIUM => 'premium_coins', self::REFILL => 'auto_refills'];

    /** A transaction that lost a deadlock is retried this many times in all (only the outermost one is retried). */
    public const DEADLOCK_ATTEMPTS = 3;

    /**
     * Posts a change of the cash balance. A positive delta is income, a negative one a cost.
     *
     * @param  string  $reason  what the money was for, for example settlement, refuel, purchase
     * @param  string  $idemKey  identifies this posting, for example settle:{run}:{version}
     *
     * @throws InsufficientFundsException when the balance would go below zero
     * @throws IdempotencyConflictException when the key was used for a different posting
     */
    public function post(int $userId, int $delta, string $reason, string $idemKey, ?int $settlementId = null): LedgerEntry
    {
        return $this->apply($userId, self::CASH, $delta, $reason, $idemKey, $settlementId);
    }

    /**
     * Posts a change of the premium coins: a purchase and a gift add them, spending takes them away. Spending never takes the balance below zero; only a
     * payment that is taken back after the coins were spent (`$allowDebt`) may, and then the driver owes the difference until the next purchase.
     *
     * @param  string  $reason  what the coins were for, for example purchase, gift, refund, speedup
     * @param  string  $idemKey  identifies this posting, for example purchase:{provider}:{payment}
     *
     * @throws RuleRefusedException `insufficient_premium` when the balance would go below zero (and a debt is not allowed)
     * @throws IdempotencyConflictException when the key was used for a different posting
     */
    public function postPremium(int $userId, int $delta, string $reason, string $idemKey, bool $allowDebt = false): LedgerEntry
    {
        return $this->apply($userId, self::PREMIUM, $delta, $reason, $idemKey, null, $allowDebt);
    }

    /**
     * Posts a change of the stock of charges of the auto-refill: a pack (or a gift) adds them, a refill of the tank takes them. The stock never goes below zero.
     *
     * @param  string  $reason  what the charges were for, for example purchase, gift, autorefill
     * @param  string  $idemKey  identifies this posting, for example autorefill:{run}:{version}
     *
     * @throws RuleRefusedException `insufficient_refills` when the stock would go below zero
     * @throws IdempotencyConflictException when the key was used for a different posting
     */
    public function postRefills(int $userId, int $delta, string $reason, string $idemKey, ?int $settlementId = null): LedgerEntry
    {
        return $this->apply($userId, self::REFILL, $delta, $reason, $idemKey, $settlementId);
    }

    private function apply(int $userId, string $currency, int $delta, string $reason, string $idemKey, ?int $settlementId, bool $allowDebt = false): LedgerEntry
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('A posting must change the balance.');
        }

        if ($reason === '' || $idemKey === '' || strlen($idemKey) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('A posting needs a reason and an idempotency key of at most '.self::MAX_KEY_LENGTH.' characters.');
        }

        $this->ensureWallet($userId);
        $column = self::COLUMNS[$currency];

        return DB::transaction(function () use ($userId, $currency, $column, $delta, $reason, $idemKey, $settlementId, $allowDebt): LedgerEntry {
            $wallet = $this->lockWallet($userId);

            // Checked after the lock, so a concurrent twin of this posting has finished and is visible.
            $existing = LedgerEntry::query()->where('idem_key', $idemKey)->first();

            if ($existing !== null) {
                if ($existing->user_id !== $userId || $existing->currency !== $currency || $existing->delta !== $delta || $existing->reason !== $reason) {
                    throw new IdempotencyConflictException($idemKey);
                }

                return $existing;
            }

            $current = (int) $wallet->{$column};
            $balance = $current + $delta;

            if ($balance < 0 && ! ($allowDebt && $delta < 0)) {
                throw match ($currency) {
                    self::CASH => new InsufficientFundsException($current, -$delta),
                    self::REFILL => new RuleRefusedException('insufficient_refills', ['available' => $current, 'required' => -$delta]),
                    default => new RuleRefusedException('insufficient_premium', ['available' => $current, 'required' => -$delta]),
                };
            }

            $entry = LedgerEntry::query()->create([
                'user_id' => $userId,
                'currency' => $currency,
                'delta' => $delta,
                'balance_after' => $balance,
                'reason' => $reason,
                'settlement_id' => $settlementId,
                'idem_key' => $idemKey,
            ]);

            $wallet->forceFill([$column => $balance])->save();

            return $entry;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /** Adds experience points. The caller makes it idempotent (it happens inside an idempotent settlement). */
    public function grantXp(int $userId, int $xp): void
    {
        if ($xp < 0) {
            throw new InvalidArgumentException('Experience cannot be negative.');
        }

        if ($xp === 0) {
            return;
        }

        $this->ensureWallet($userId);

        DB::transaction(function () use ($userId, $xp): void {
            $wallet = $this->lockWallet($userId);
            $wallet->forceFill(['xp' => $wallet->xp + $xp])->save();
        }, self::DEADLOCK_ATTEMPTS);
    }

    public function balance(int $userId): int
    {
        return (int) (Wallet::query()->whereKey($userId)->value('cash_cents') ?? 0);
    }

    /** The premium coins of the driver (negative when a payment was taken back after they were spent). */
    public function premiumBalance(int $userId): int
    {
        return (int) (Wallet::query()->whereKey($userId)->value('premium_coins') ?? 0);
    }

    /** The charges of the auto-refill the driver has in stock. */
    public function refillBalance(int $userId): int
    {
        return (int) (Wallet::query()->whereKey($userId)->value('auto_refills') ?? 0);
    }

    /** The experience of the driver: it gives the level that opens the tracks. */
    public function xp(int $userId): int
    {
        return (int) (Wallet::query()->whereKey($userId)->value('xp') ?? 0);
    }

    /** The balance of a currency (the cash, the premium coins or the charges of the auto-refill) equals the sum of its entries of the ledger and the last entry agrees with it. */
    public function isConsistent(int $userId, string $currency = self::CASH): bool
    {
        $balance = (int) (Wallet::query()->whereKey($userId)->value(self::COLUMNS[$currency]) ?? 0);
        $sum = (int) LedgerEntry::query()->where('user_id', $userId)->where('currency', $currency)->sum('delta');
        $last = LedgerEntry::query()->where('user_id', $userId)->where('currency', $currency)->orderByDesc('id')->first();

        return $balance === $sum && ($last === null || $last->balance_after === $balance);
    }

    /**
     * Makes sure the wallet row exists. Call it before opening a transaction: the insert then commits at once
     * and holds no locks. Creating the row inside the transaction after a locking read of the missing row
     * deadlocks on InnoDB, because every transaction holds the gap lock that blocks the insert of the others.
     */
    public function ensureWallet(int $userId): void
    {
        if (! Wallet::query()->whereKey($userId)->exists()) {
            // Two requests may get here together: the loser of the insert simply ignores the conflict.
            Wallet::query()->insertOrIgnore(['user_id' => $userId, 'cash_cents' => 0, 'xp' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Locks the wallet for the rest of the transaction, creating it when it does not exist (see ensureWallet). */
    public function lockWallet(int $userId): Wallet
    {
        $query = fn () => Wallet::query()->whereKey($userId)->lockForUpdate()->first();

        $wallet = $query();

        if ($wallet === null) {
            $this->ensureWallet($userId);
            $wallet = $query() ?? throw new InvalidArgumentException("The driver {$userId} does not exist.");
        }

        return $wallet;
    }
}
