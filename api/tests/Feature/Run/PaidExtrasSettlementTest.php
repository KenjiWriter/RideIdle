<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\RunService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

// The paid extras in the settlement of the laps: the tank filled for the driver (a charge, or nothing while the pass lasts) and the bonus of the VIP account. The
// car of these runs laps in 75 s for $15.50 and 25 XP, uses 1.5 l a lap and has a tank of 45 l (30 laps, 2 250 000 ms), and it drives at once.

const PAID_HOUR_MS = 3_600_000;

/** A driver with the run of the factory (it was frozen without any knowledge of the extras) and the charges they have. @return array{0: User, 1: RaceRun} */
function paidDriver(int $charges = 0, array $run = []): array
{
    [$driver, $race] = driverWithRun(['seed' => str_repeat('0', 32), ...$run]);
    (new Ledger)->ensureWallet($driver->id);

    if ($charges > 0) {
        (new Ledger)->postRefills($driver->id, $charges, 'gift', "paid-stock:{$driver->id}");
    }

    return [$driver, $race];
}

/** @param  array<string, mixed>  $columns */
function onWallet(User $driver, array $columns): void
{
    Wallet::query()->whereKey($driver->id)->update($columns);
}

function momentAt(int $msAfterT0): Carbon
{
    return Carbon::createFromTimestampMs(RUN_T0 + $msAfterT0);
}

function settledAt(RaceRun $run, int $msAfterT0, ?RunService $service = null): RaceRun
{
    ($service ?? runService())->settle($run->id, RUN_T0 + $msAfterT0);

    return $run->refresh();
}

/** The service with the rules of the repository, changed. @param  callable(array<string, mixed>): array<string, mixed>  $change */
function serviceWithRules(callable $change): RunService
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);

    return new RunService(new Ledger, rules: Rules::fromArray($change($data)));
}

afterEach(fn () => Carbon::setTestNow());

describe('the tank filled for the driver', function () {
    it('has a car of the tests that holds 45 l', function () {
        expect(app(RunInputsFactory::class)->tankMl('rdzak-gt-4'))->toBe(45_000);
    });

    it('is filled by a charge each time the fuel would run out, also in a long absence, and the charges go with the settlement', function () {
        [$driver, $run] = paidDriver(3);

        $run = settledAt($run, 4 * PAID_HOUR_MS);

        // 4 tanks of 30 laps: the one it had and the three that the charges filled; then the tank is empty and nothing fills it.
        expect($run->laps_done)->toBe(120)
            ->and($run->status)->toBe('stopped')
            ->and($run->stop_reason)->toBe('fuel_empty')
            ->and($run->fuel_ml)->toBe(0)
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(0);

        $settlement = Settlement::query()->where('race_run_id', $run->id)->sole();
        $entry = LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'autorefill')->sole();
        $refills = SettlementEvent::query()->where('settlement_id', $settlement->id)->where('type', 'auto_refill')->orderBy('seq')->get();

        expect($settlement->auto_refills)->toBe(3)
            ->and($settlement->refill_charges)->toBe(3)
            ->and($settlement->bonus_cash_cents)->toBe(0)
            ->and($entry->currency)->toBe('refill')
            ->and($entry->delta)->toBe(-3)
            ->and($entry->settlement_id)->toBe($settlement->id)
            ->and($entry->idem_key)->toBe("autorefill:{$run->id}:0")
            ->and($refills->pluck('at_ms')->all())->toBe([RUN_T0 + 2_250_000, RUN_T0 + 4_500_000, RUN_T0 + 6_750_000])
            ->and($refills->first()->payload)->toBe(['added_ml' => 45_000, 'free' => 0])
            ->and((new Ledger)->isConsistent($driver->id, Ledger::REFILL))->toBeTrue()
            ->and(cashOf($run))->toBe(120 * 1_550);
    });

    it('takes the charges once: a settlement that is made again changes nothing, and a driver who gets more charges drives on', function () {
        [$driver, $run] = paidDriver(1);

        settledAt($run, 3 * PAID_HOUR_MS);
        $entries = LedgerEntry::query()->where('user_id', $driver->id)->count();
        $settlements = Settlement::query()->count();

        settledAt($run, 3 * PAID_HOUR_MS);
        settledAt($run, 3 * PAID_HOUR_MS + 5_000);

        expect(LedgerEntry::query()->where('user_id', $driver->id)->count())->toBe($entries)
            ->and(Settlement::query()->count())->toBe($settlements)
            ->and($run->refresh()->laps_done)->toBe(60)
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(0);

        // A stopped car stays stopped: the charges bought now do not fill a tank for the time that passed. The driver refuels, or fills it, and drives on.
        (new Ledger)->postRefills($driver->id, 2, 'gift', "more:{$driver->id}");
        settledAt($run, 4 * PAID_HOUR_MS);

        expect($run->refresh()->laps_done)->toBe(60)->and($run->status)->toBe('stopped');
    });

    it('gives the same laps and charges in steps as at once', function () {
        [$driver, $once] = paidDriver(4);
        [$otherDriver, $steps] = paidDriver(4);

        settledAt($once, 5 * PAID_HOUR_MS);

        foreach ([PAID_HOUR_MS / 2, PAID_HOUR_MS, 2 * PAID_HOUR_MS + 7_777, 3 * PAID_HOUR_MS, 5 * PAID_HOUR_MS] as $at) {
            settledAt($steps, (int) $at);
        }

        expect($steps->laps_done)->toBe($once->refresh()->laps_done)
            ->and($steps->fuel_ml)->toBe($once->fuel_ml)
            ->and(Wallet::query()->find($otherDriver->id)->auto_refills)->toBe(Wallet::query()->find($driver->id)->auto_refills)
            ->and(cashOf($steps))->toBe(cashOf($once))
            ->and($once->laps_done)->toBe(150);
    });

    it('costs no charge while the pass lasts, and the charges in stock stay', function () {
        [$driver, $run] = paidDriver(2);
        onWallet($driver, ['infinity_until' => momentAt(2 * 86_400_000)]);

        $run = settledAt($run, 4 * PAID_HOUR_MS);

        // 192 laps fit in 4 hours, and the tank is filled at every 30th lap.
        expect($run->laps_done)->toBe(192)
            ->and($run->status)->toBe('running')
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(2)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'autorefill')->count())->toBe(0);

        $settlement = Settlement::query()->where('race_run_id', $run->id)->sole();

        expect($settlement->auto_refills)->toBe(6)
            ->and($settlement->refill_charges)->toBe(0)
            ->and(SettlementEvent::query()->where('settlement_id', $settlement->id)->where('type', 'auto_refill')->get()->every(fn (SettlementEvent $event): bool => $event->payload['free'] === 1))->toBeTrue();
    });

    it('is free while the pass lasts and takes a charge after it ends, inside one absence', function () {
        [$driver, $run] = paidDriver(1);
        onWallet($driver, ['infinity_until' => momentAt(PAID_HOUR_MS)]);

        $run = settledAt($run, 4 * PAID_HOUR_MS);

        // The refill at 37.5 minutes is free (the pass runs to an hour), the one at 75 minutes takes the only charge, the one at 112.5 minutes has none to take.
        expect($run->laps_done)->toBe(90)
            ->and($run->stop_reason)->toBe('fuel_empty')
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(0)
            ->and(Settlement::query()->where('race_run_id', $run->id)->sole()->refill_charges)->toBe(1);
    });

    it('does not fill the tank of a driver who has switched the wish off, and keeps their charges', function () {
        [$driver, $run] = paidDriver(5);
        onWallet($driver, ['auto_refill_enabled' => false, 'infinity_until' => momentAt(86_400_000)]);

        $run = settledAt($run, 4 * PAID_HOUR_MS);

        expect($run->laps_done)->toBe(30)
            ->and($run->stop_reason)->toBe('fuel_empty')
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(5)
            ->and(Settlement::query()->where('race_run_id', $run->id)->sole()->auto_refills)->toBe(0);
    });

    it('does not fill the tank in a game that has switched the auto-refill off, and keeps what the driver has', function () {
        [$driver, $run] = paidDriver(5);
        onWallet($driver, ['infinity_until' => momentAt(86_400_000)]);

        $service = serviceWithRules(function (array $data): array {
            $data['premium']['autorefill']['enabled'] = false;

            return $data;
        });
        $run = settledAt($run, 4 * PAID_HOUR_MS, $service);

        expect($run->laps_done)->toBe(30)
            ->and($run->stop_reason)->toBe('fuel_empty')
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(5)
            ->and(Wallet::query()->find($driver->id)->infinity_until)->not->toBeNull();
    });

    it('does not honour the pass in a game that has switched it off, but still uses the charges', function () {
        [$driver, $run] = paidDriver(1);
        onWallet($driver, ['infinity_until' => momentAt(86_400_000)]);

        $service = serviceWithRules(function (array $data): array {
            $data['premium']['infinity']['enabled'] = false;

            return $data;
        });
        $run = settledAt($run, 4 * PAID_HOUR_MS, $service);

        expect($run->laps_done)->toBe(60)->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(0);
    });

    it('does not fill the tank of a game that does not have the extras at all', function () {
        [$driver, $run] = paidDriver(5);

        $service = serviceWithRules(function (array $data): array {
            unset($data['premium']['autorefill'], $data['premium']['vip'], $data['premium']['infinity']);

            return $data;
        });

        expect(settledAt($run, 4 * PAID_HOUR_MS, $service)->laps_done)->toBe(30)
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(5);
    });

    it('leaves a car that stands where it is: the time it stood is not filled for', function () {
        [$driver, $run] = paidDriver(5, ['status' => 'stopped', 'stop_reason' => 'fuel_empty', 'fuel_ml' => 0]);
        [$broken, $wreck] = paidDriver(5, ['status' => 'stopped', 'stop_reason' => 'breakdown', 'fuel_ml' => 0, 'break_after_laps' => 0]);

        expect(settledAt($run, 4 * PAID_HOUR_MS)->laps_done)->toBe(0)
            ->and(settledAt($wreck, 4 * PAID_HOUR_MS)->laps_done)->toBe(0)
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(5)
            ->and(Wallet::query()->find($broken->id)->auto_refills)->toBe(5)
            ->and(Settlement::query()->count())->toBe(0);
    });

    it('fills the tank of a car that breaks down on the lap it runs dry, so that it is not left empty when it is repaired', function () {
        [$driver, $run] = paidDriver(2, ['break_after_laps' => 30]);

        $run = settledAt($run, PAID_HOUR_MS);

        expect($run->laps_done)->toBe(30)
            ->and($run->stop_reason)->toBe('breakdown')
            ->and($run->fuel_ml)->toBe(45_000)
            ->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(1);
    });

    it('fills the tank of a car that is on the track with an empty one, before its first lap', function () {
        [$driver, $run] = paidDriver(1, ['fuel_ml' => 500]);

        $run = settledAt($run, 10 * 75_000);

        expect($run->laps_done)->toBe(10)->and($run->fuel_ml)->toBe(45_000 - 15_000)->and(Wallet::query()->find($driver->id)->auto_refills)->toBe(0);
    });
});

describe('the bonus of the VIP account', function () {
    it('pays 20% more cash and XP for every lap while it lasts, in the same posting as the laps, and says how much of it was the bonus', function () {
        [$driver, $run] = paidDriver();
        onWallet($driver, ['vip_until' => momentAt(86_400_000)]);

        $run = settledAt($run, 10 * 75_000);

        $settlement = Settlement::query()->where('race_run_id', $run->id)->sole();
        $batch = SettlementEvent::query()->where('settlement_id', $settlement->id)->where('type', 'lap_batch')->get();

        expect($settlement->laps)->toBe(10)
            ->and($settlement->cash_cents)->toBe(10 * 1_860)
            ->and($settlement->xp)->toBe(10 * 30)
            ->and($settlement->bonus_cash_cents)->toBe(10 * 310)
            ->and($settlement->bonus_xp)->toBe(10 * 5)
            ->and(cashOf($run))->toBe(10 * 1_860)
            ->and((new Ledger)->xp($driver->id))->toBe(300)
            ->and($batch->sum(fn (SettlementEvent $event): int => $event->payload['bonus_cash_cents']))->toBe(3_100)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'settlement')->sole()->delta)->toBe(18_600)
            ->and((new Ledger)->isConsistent($driver->id))->toBeTrue();
    });

    it('pays the laps before its end and not after it, when it ends in the middle of an absence', function () {
        [$driver, $run] = paidDriver();
        // The 4th lap finishes at 300 000 ms and does not count: the account runs to the second, and the laps after it are the laps of a driver who has none.
        onWallet($driver, ['vip_until' => momentAt(300_000)]);

        $run = settledAt($run, 10 * 75_000);
        $settlement = Settlement::query()->where('race_run_id', $run->id)->sole();

        expect($settlement->bonus_cash_cents)->toBe(3 * 310)
            ->and($settlement->bonus_xp)->toBe(3 * 5)
            ->and($settlement->cash_cents)->toBe(10 * 1_550 + 3 * 310)
            ->and($settlement->xp)->toBe(10 * 25 + 3 * 5);
    });

    it('pays the same in steps as at once, wherever the steps are cut', function () {
        [, $once] = paidDriver();
        [$driver, $steps] = paidDriver();
        onWallet(User::query()->find($once->user_id), ['vip_until' => momentAt(1_000_000)]);
        onWallet($driver, ['vip_until' => momentAt(1_000_000)]);

        settledAt($once, 3 * PAID_HOUR_MS);

        foreach ([10_000, 80_000, 999_000, 1_000_000, 1_234_567, 3 * PAID_HOUR_MS] as $at) {
            settledAt($steps, $at);
        }

        expect(cashOf($steps))->toBe(cashOf($once))
            ->and(Settlement::query()->where('race_run_id', $steps->id)->sum('bonus_cash_cents'))->toBe(Settlement::query()->where('race_run_id', $once->id)->sum('bonus_cash_cents'))
            ->and((new Ledger)->xp($driver->id))->toBe((new Ledger)->xp($once->user_id));
    });

    it('is not paid by a game that has switched it off, and the time the driver has is kept', function () {
        [$driver, $run] = paidDriver();
        onWallet($driver, ['vip_until' => momentAt(86_400_000)]);

        $service = serviceWithRules(function (array $data): array {
            $data['premium']['vip']['enabled'] = false;

            return $data;
        });
        $run = settledAt($run, 10 * 75_000, $service);

        expect(cashOf($run))->toBe(10 * 1_550)
            ->and(Settlement::query()->where('race_run_id', $run->id)->sole()->bonus_cash_cents)->toBe(0)
            ->and(Wallet::query()->find($driver->id)->vip_until)->not->toBeNull();
    });

    it('is not paid to a driver who never had one, and an account that ended before the laps pays nothing', function () {
        [$driver, $run] = paidDriver();
        [$expired, $late] = paidDriver();
        onWallet($expired, ['vip_until' => momentAt(-86_400_000)]);

        expect(cashOf(settledAt($run, 10 * 75_000)))->toBe(10 * 1_550)
            ->and(cashOf(settledAt($late, 10 * 75_000)))->toBe(10 * 1_550);
    });

    it('is paid on top of the tank filled for the driver, in the same settlement', function () {
        [$driver, $run] = paidDriver(1);
        onWallet($driver, ['vip_until' => momentAt(86_400_000)]);

        $run = settledAt($run, PAID_HOUR_MS);

        $settlement = Settlement::query()->where('race_run_id', $run->id)->sole();

        expect($run->laps_done)->toBe(48)
            ->and($settlement->auto_refills)->toBe(1)
            ->and($settlement->bonus_cash_cents)->toBe(48 * 310);
    });
});

describe('the economy of the game', function () {
    it('is measured without the extras: the simulator and its gates give the same report for a game that sells them and one that does not', function () {
        Artisan::call('economy:simulate', ['--json' => true]);
        $with = Artisan::output();

        $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($data['premium']['autorefill'], $data['premium']['vip'], $data['premium']['infinity']);
        app()->singleton(Rules::class, fn () => Rules::fromArray($data));

        Artisan::call('economy:simulate', ['--json' => true]);

        expect(Artisan::output())->toBe($with)->and($with)->toContain('"');
    });

    it('freezes the same configuration of a run whatever the driver has bought', function () {
        [$driver, $run] = paidDriver(3);
        onWallet($driver, ['vip_until' => momentAt(86_400_000), 'infinity_until' => momentAt(86_400_000)]);

        $before = $run->config;
        settledAt($run, PAID_HOUR_MS);

        // The bonus and the refills are not in the frozen configuration, so they cannot move the income that the gates measure.
        expect($run->refresh()->config)->toBe($before);
    });
});

describe('what the answer of a run says of them', function () {
    it('says in the summary and in each settlement how much the VIP account added and how many times the tank was filled', function () {
        [$driver, $run] = paidDriver(2);
        onWallet($driver, ['vip_until' => momentAt(86_400_000)]);
        clockAt(PAID_HOUR_MS);

        $response = getRun($driver)->assertOk();

        expect($response->json('summary.laps'))->toBe(48)
            ->and($response->json('summary.bonus_cash_cents'))->toBe(48 * 310)
            ->and($response->json('summary.bonus_xp'))->toBe(48 * 5)
            ->and($response->json('summary.auto_refills'))->toBe(1)
            ->and($response->json('summary.refill_charges'))->toBe(1)
            ->and($response->json('summary.cash_cents'))->toBe(48 * 1_860)
            ->and($response->json('settlements.0.bonus_cash_cents'))->toBe(48 * 310)
            ->and($response->json('settlements.0.auto_refills'))->toBe(1)
            ->and(collect($response->json('settlements.0.events'))->pluck('type')->contains('auto_refill'))->toBeTrue();
    });
});
