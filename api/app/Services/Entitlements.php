<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Exceptions\RuleRefusedException;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a driver has bought that is not coins: the charges of the auto-refill (the tank is filled for them when the fuel would run out), the VIP account (more cash
 * and XP for every lap, for a time) and the pass of the endless refill (the refill costs no charge, for a time). The rules say what is sold and for how much
 * (`premium.autorefill`, `premium.vip`, `premium.infinity`), and each can be switched off for a game.
 *
 * The charges are a currency of the ledger (`refill`), the two accounts are an end time on the wallet. Everything is bought or changed under the lock of the wallet
 * with a key of one intention, so a repeated request changes nothing. And **the runs of the driver are settled first**: what the cars did until now is paid
 * with what the driver had until now, so a driver cannot buy charges for time that has passed, and a VIP that was bought after the laps does not pay for them.
 */
final class Entitlements
{
    /** The product that the book of purchases records for the pass of the endless refill. */
    public const PASS_PRODUCT = 'infinity';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly Premium $premium,
        private readonly Workshop $workshop,
        private readonly RunService $runs,
    ) {}

    /**
     * What the shop shows of these three things: for each, whether the game sells it, what a driver has of it and what it costs. A feature the rules do not
     * mention is null. The times are in milliseconds of the clock of the server, and `remaining_ms` is counted on it, so the client needs no clock of its own.
     *
     * @return array{now_ms: int, autorefill: array<string, mixed>|null, vip: array<string, mixed>|null, infinity: array<string, mixed>|null}
     */
    public function view(int $userId, ?int $nowMs = null): array
    {
        $now = $nowMs ?? RunService::nowMs();
        $wallet = Wallet::query()->find($userId);
        $refill = $this->rules->autoRefill();
        $vip = $this->rules->vip();
        $pass = $this->rules->infinity();
        $vipUntil = $wallet?->vip_until?->getTimestampMs();
        $passUntil = $wallet?->infinity_until?->getTimestampMs();

        $packs = [];

        foreach ($refill['packs'] ?? [] as $slug => $pack) {
            $packs[] = ['slug' => $slug, ...$pack];
        }

        return [
            'now_ms' => $now,
            'autorefill' => $refill === null ? null : [
                'enabled' => $refill['enabled'],
                'preference' => $wallet === null || (bool) $wallet->auto_refill_enabled,
                'stock' => (int) ($wallet->auto_refills ?? 0),
                'stock_cap' => $refill['stock_cap'],
                'packs' => $packs,
            ],
            'vip' => $vip === null ? null : [
                'enabled' => $vip['enabled'],
                'active' => $vip['enabled'] && $vipUntil !== null && $vipUntil > $now,
                'until_ms' => $vipUntil,
                'remaining_ms' => $vipUntil === null ? 0 : max(0, $vipUntil - $now),
                'days' => $vip['days'],
                'coins' => $vip['coins'],
                'cash_bonus_ppm' => $vip['cash_bonus_ppm'],
                'xp_bonus_ppm' => $vip['xp_bonus_ppm'],
            ],
            'infinity' => $pass === null ? null : [
                'enabled' => $pass['enabled'],
                'active' => $pass['enabled'] && $passUntil !== null && $passUntil > $now,
                'until_ms' => $passUntil,
                'remaining_ms' => $passUntil === null ? 0 : max(0, $passUntil - $now),
                'days' => $pass['days'],
                'price_minor' => $pass['price_minor'],
                'purchasable' => $pass['enabled'] && self::paymentsConnected(),
            ],
        ];
    }

    /** Whether a payment provider is connected: until one is (stage P3), the pass can be given by the people who run the game, but not bought in the game. */
    public static function paymentsConnected(): bool
    {
        return config('ride.premium.provider') !== null;
    }

    /**
     * Buys a pack of charges of the auto-refill for premium coins. The stock has a cap (`stock_cap`), and a pack that would take it over is refused whole.
     * `$requestId` is one intention: sent again it charges nothing and gives nothing (`repeated`).
     *
     * @return array{repeated: bool, coins: int, charges: int}
     *
     * @throws RuleRefusedException `autorefill_unavailable`, `autorefill_pack_not_found`, `autorefill_stock_full` or `insufficient_premium` (nothing is changed)
     */
    public function buyRefills(int $userId, string $packSlug, string $requestId): array
    {
        $refill = $this->rules->autoRefill();

        if (! $this->rules->autoRefillOn() || $refill === null) {
            throw new RuleRefusedException('autorefill_unavailable');
        }

        $pack = $refill['packs'][$packSlug] ?? throw new RuleRefusedException('autorefill_pack_not_found');
        $this->checkRequestId($requestId);
        $this->settleRuns($userId);

        return DB::transaction(function () use ($userId, $pack, $refill, $requestId): array {
            $wallet = $this->ledger->lockWallet($userId);
            $coinKey = "refillbuy:{$userId}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $coinKey)->exists()) {
                return ['repeated' => true, 'coins' => 0, 'charges' => 0];
            }

            if ((int) $wallet->auto_refills + $pack['charges'] > $refill['stock_cap']) {
                throw new RuleRefusedException('autorefill_stock_full', ['stock' => (int) $wallet->auto_refills, 'cap' => $refill['stock_cap'], 'charges' => $pack['charges']]);
            }

            $this->premium->spend($userId, $pack['coins'], 'autorefill', $coinKey);
            $this->ledger->postRefills($userId, $pack['charges'], 'purchase', "refillstock:{$userId}:{$requestId}");

            return ['repeated' => false, 'coins' => $pack['coins'], 'charges' => $pack['charges']];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Buys the VIP account for premium coins, or extends it: a purchase adds the days of the rules to the end of the VIP the driver has, or to now when it has
     * ended. The bonus is paid for the laps that are finished while it lasts.
     *
     * @return array{repeated: bool, coins: int}
     *
     * @throws RuleRefusedException `vip_unavailable` or `insufficient_premium` (nothing is changed)
     */
    public function buyVip(int $userId, string $requestId): array
    {
        $vip = $this->rules->vip();

        if (! $this->rules->vipOn() || $vip === null) {
            throw new RuleRefusedException('vip_unavailable');
        }

        $this->checkRequestId($requestId);
        $this->settleRuns($userId);

        return DB::transaction(function () use ($userId, $vip, $requestId): array {
            $wallet = $this->ledger->lockWallet($userId);
            $key = "vip:{$userId}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $key)->exists()) {
                return ['repeated' => true, 'coins' => 0];
            }

            $this->premium->spend($userId, $vip['coins'], 'vip', $key);
            $wallet->forceFill(['vip_until' => self::extended($wallet->vip_until, $vip['days'])])->save();

            return ['repeated' => false, 'coins' => $vip['coins']];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * The driver's wish to have the tank filled for them (on by default). It counts from now on: the time that has passed is settled first with the wish that
     * was in force.
     *
     * @throws RuleRefusedException `autorefill_unavailable` when the game does not sell it
     */
    public function setAutoRefill(int $userId, bool $enabled): void
    {
        if (! $this->rules->autoRefillOn()) {
            throw new RuleRefusedException('autorefill_unavailable');
        }

        $this->settleRuns($userId);
        $this->ledger->ensureWallet($userId);

        DB::transaction(function () use ($userId, $enabled): void {
            $this->ledger->lockWallet($userId)->forceFill(['auto_refill_enabled' => $enabled])->save();
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Adds the days of a pass of the endless refill to the end of the one the driver has (to now when it has ended). The caller is the book of the purchases
     * (`Purchases::fulfilPass`), which makes it happen once per payment, inside its own transaction and with the wallet locked.
     */
    public function extendInfinity(Wallet $wallet, int $days): void
    {
        $wallet->forceFill(['infinity_until' => self::extended($wallet->infinity_until, $days)])->save();
    }

    /** A pass that was paid for and is taken back ends now. Called inside the transaction of the refund, with the wallet locked. */
    public function endInfinity(Wallet $wallet): void
    {
        if ($wallet->infinity_until !== null && $wallet->infinity_until->isFuture()) {
            $wallet->forceFill(['infinity_until' => now()->startOfSecond()])->save();
        }
    }

    /**
     * Brings every car of the driver up to date: what is due is carried out and each run is settled. Whatever changes what the cars earn or spend (the stock, the
     * VIP, the pass, the wish to be refilled) calls this first, so the change counts from now on and not for the time that has passed.
     */
    public function settleRuns(int $userId): void
    {
        $this->workshop->advance($userId);

        foreach (RaceRun::query()->where('user_id', $userId)->orderBy('id')->pluck('id') as $runId) {
            $this->runs->settle((int) $runId);
        }
    }

    /** The end of an account that lasts `$days` more: from its end when it has not ended yet, from now when it has (or was never bought). Whole seconds, as the column keeps them. */
    private static function extended(?CarbonInterface $until, int $days): CarbonInterface
    {
        $from = $until !== null && $until->isFuture() ? $until : now();

        return $from->copy()->addDays($days)->startOfSecond();
    }

    private function checkRequestId(string $requestId): void
    {
        if ($requestId === '' || strlen($requestId) > RunService::MAX_REQUEST_ID_LENGTH) {
            throw new InvalidArgumentException('A purchase needs a request id of at most '.RunService::MAX_REQUEST_ID_LENGTH.' characters.');
        }
    }
}
