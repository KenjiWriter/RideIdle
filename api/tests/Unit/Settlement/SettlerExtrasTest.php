<?php

declare(strict_types=1);

use App\Domain\Settlement\BonusWindow;
use App\Domain\Settlement\EventType;
use App\Domain\Settlement\Ppm;
use App\Domain\Settlement\RefillPolicy;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;
use App\Domain\Settlement\SettlementResult;
use App\Domain\Settlement\Settler;

// The two things that settlement is given as data for the paid extras: the policy of the auto-refill and the windows of the VIP bonus. The car of these tests laps
// in 75 s for $15.50 and 25 XP, uses 1.5 l a lap and has a tank of 45 l, which is 30 laps (2 250 000 ms).

const EXTRAS_LAP_MS = 75_000;
const EXTRAS_TANK_MS = 2_250_000;
const EXTRAS_TANK_ML = 45_000;

function extrasConfig(): RunConfig
{
    return RunConfig::constant(EXTRAS_LAP_MS, 1_550, 25, 1_500);
}

/** @return list<string> the types of the events in order, with the time of the ones that are not batches of laps (the batches that follow each other are one: weather cuts them) */
function extrasTimeline(SettlementResult $result): array
{
    $timeline = [];

    foreach ($result->events as $event) {
        $entry = $event->type === EventType::LapBatch ? 'laps' : "{$event->type->value}@{$event->atMs}";

        if ($entry !== 'laps' || end($timeline) !== 'laps') {
            $timeline[] = $entry;
        }
    }

    return $timeline;
}

describe('the auto-refill', function () {
    it('fills the tank where the fuel would run out and drives on, one charge for each refill', function () {
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), 3 * EXTRAS_TANK_MS, extrasConfig(), new RefillPolicy(EXTRAS_TANK_ML, 2));

        // 30 laps, a refill, 30 laps, a refill, 30 laps and the third tank is empty with nothing to fill it with.
        expect($result->laps)->toBe(90)
            ->and($result->refills)->toBe(2)
            ->and($result->chargesUsed)->toBe(2)
            ->and($result->state->fuelMl)->toBe(0)
            ->and($result->state->cursorMs)->toBe(3 * EXTRAS_TANK_MS)
            ->and($result->stopReason())->toBe(EventType::FuelEmpty)
            ->and(extrasTimeline($result))->toBe(['laps', 'auto_refill@'.EXTRAS_TANK_MS, 'laps', 'auto_refill@'.(2 * EXTRAS_TANK_MS), 'laps', 'fuel_empty@'.(3 * EXTRAS_TANK_MS)]);

        $first = array_values(array_filter($result->events, fn (SettlementEvent $event): bool => $event->type === EventType::AutoRefill))[0];

        expect($first->toArray()['payload'])->toBe(['added_ml' => EXTRAS_TANK_ML, 'free' => 0]);
    });

    it('does not stop the car when the tank is filled on the very lap it would have stopped, and fills only what is missing', function () {
        $result = (new Settler)->settle(new RunState(0, 0, 4_000), 100 * EXTRAS_LAP_MS, extrasConfig(), new RefillPolicy(EXTRAS_TANK_ML, 5));

        // 2 laps from 4 l leave 1 l, which is less than a lap: 44 l fill the tank to 45, which is 30 laps, and so on until the 100th lap.
        $refill = array_values(array_filter($result->events, fn (SettlementEvent $event): bool => $event->type === EventType::AutoRefill))[0];

        expect($refill->toArray())->toBe(['type' => 'auto_refill', 'at_ms' => 2 * EXTRAS_LAP_MS, 'payload' => ['added_ml' => 44_000, 'free' => 0]])
            ->and($result->laps)->toBe(100)
            ->and($result->refills)->toBe(4)
            ->and($result->stopReason())->toBeNull();
    });

    it('costs no charge while the pass lasts, and one after it', function () {
        // The pass ends in the middle of the second tank.
        $policy = new RefillPolicy(EXTRAS_TANK_ML, 1, EXTRAS_TANK_MS + 1_000);
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), 10 * EXTRAS_TANK_MS, extrasConfig(), $policy);

        // The refill at the end of the first tank is free (the pass is on), the one at the end of the second takes the charge, the third finds none.
        expect($result->refills)->toBe(2)
            ->and($result->chargesUsed)->toBe(1)
            ->and($result->laps)->toBe(90)
            ->and(array_map(fn (SettlementEvent $event): int => $event->toArray()['payload']['free'], array_values(array_filter($result->events, fn (SettlementEvent $event): bool => $event->type === EventType::AutoRefill))))->toBe([1, 0]);
    });

    it('fills the tank at the end of the window too, so that the car is not left empty for a lap that is about to begin', function () {
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), 10 * EXTRAS_TANK_MS, extrasConfig(), new RefillPolicy(EXTRAS_TANK_ML, 0, PHP_INT_MAX));

        expect($result->laps)->toBe(300)
            ->and($result->refills)->toBe(10)
            ->and($result->chargesUsed)->toBe(0)
            ->and($result->state->fuelMl)->toBe(EXTRAS_TANK_ML)
            ->and($result->stopReason())->toBeNull();
    });

    it('leaves a car with no charge and no pass to stop as it always did', function () {
        $without = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), 5 * EXTRAS_TANK_MS, extrasConfig());

        foreach ([RefillPolicy::none(), new RefillPolicy(EXTRAS_TANK_ML, 0, 0), new RefillPolicy(0, 9, PHP_INT_MAX)] as $policy) {
            $with = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), 5 * EXTRAS_TANK_MS, extrasConfig(), $policy);

            expect($with)->toEqual($without)
                ->and($with->laps)->toBe(30)
                ->and($with->stopReason())->toBe(EventType::FuelEmpty);
        }
    });

    it('does not fill a tank that holds less than a lap', function () {
        $result = (new Settler)->settle(new RunState(0, 0, 3_000), 10 * EXTRAS_TANK_MS, extrasConfig(), new RefillPolicy(1_000, 9));

        expect($result->laps)->toBe(2)->and($result->refills)->toBe(0)->and($result->stopReason())->toBe(EventType::FuelEmpty);
    });

    it('fills the tank before the first lap of a car that stands in front of an empty one', function () {
        $result = (new Settler)->settle(new RunState(100_000, 0, 500), 100_000 + 2 * EXTRAS_LAP_MS, extrasConfig(), new RefillPolicy(EXTRAS_TANK_ML, 1));

        expect($result->laps)->toBe(2)
            ->and(extrasTimeline($result))->toBe(['auto_refill@100000', 'laps'])
            ->and($result->state->fuelMl)->toBe(EXTRAS_TANK_ML - 3_000);
    });

    it('fills the tank of a car that breaks down on the lap it runs dry, and the breakdown is the reason it stops', function () {
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML, 30), 10 * EXTRAS_TANK_MS, extrasConfig(), new RefillPolicy(EXTRAS_TANK_ML, 3));

        expect($result->laps)->toBe(30)
            ->and(extrasTimeline($result))->toBe(['laps', 'breakdown@'.EXTRAS_TANK_MS, 'auto_refill@'.EXTRAS_TANK_MS])
            ->and($result->stopReason())->toBe(EventType::Breakdown)
            ->and($result->state->fuelMl)->toBe(EXTRAS_TANK_ML)
            ->and($result->chargesUsed)->toBe(1);
    });

    it('does not fill a tank for a lap that has not finished: the lap in progress is neither paid nor lost', function () {
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS - 1, extrasConfig(), new RefillPolicy(EXTRAS_TANK_ML, 3));

        expect($result->laps)->toBe(29)->and($result->refills)->toBe(0);
    });

    it('gives the same in steps as at once, the charges that the first step used being gone from the second', function () {
        $settler = new Settler;
        $now = 7 * EXTRAS_TANK_MS + 12_345;

        foreach ([1, 40_000, EXTRAS_TANK_MS, EXTRAS_TANK_MS + 1, 3 * EXTRAS_TANK_MS - 1, 5 * EXTRAS_TANK_MS + 777] as $t1) {
            $policy = new RefillPolicy(EXTRAS_TANK_ML, 4, 4 * EXTRAS_TANK_MS);
            $state = new RunState(0, 0, EXTRAS_TANK_ML);

            $first = $settler->settle($state, $t1, extrasConfig(), $policy);
            $rest = new RefillPolicy(EXTRAS_TANK_ML, $policy->charges - $first->chargesUsed, $policy->freeUntilMs);
            $second = $settler->settle($first->state, $now, extrasConfig(), $rest);
            $once = $settler->settle($state, $now, extrasConfig(), $policy);

            expect($second->state)->toEqual($once->state)
                ->and($first->laps + $second->laps)->toBe($once->laps)
                ->and($first->cashCents + $second->cashCents)->toBe($once->cashCents)
                ->and($first->refills + $second->refills)->toBe($once->refills)
                ->and($first->chargesUsed + $second->chargesUsed)->toBe($once->chargesUsed);
        }
    });

    it('gives the same in windows of three days as in one step', function () {
        $settler = new Settler;
        $now = 10 * 86_400_000;
        $policy = new RefillPolicy(EXTRAS_TANK_ML, 150, 2 * 86_400_000);

        $chunks = $settler->settleInChunks(new RunState(0, 0, EXTRAS_TANK_ML), $now, extrasConfig(), 6 * 3_600_000, $policy);
        $once = $settler->settleInChunks(new RunState(0, 0, EXTRAS_TANK_ML), $now, extrasConfig(), Settler::MAX_WINDOW_MS, $policy);

        expect($chunks->laps)->toBe($once->laps)
            ->and($chunks->state)->toEqual($once->state)
            ->and($chunks->refills)->toBe($once->refills)
            ->and($chunks->chargesUsed)->toBe($once->chargesUsed)
            ->and($chunks->chargesUsed)->toBeLessThanOrEqual(150)
            ->and($chunks->refills)->toBeGreaterThan($chunks->chargesUsed);
    });

    it('refuses a policy that is below zero', function () {
        expect(fn () => new RefillPolicy(-1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new RefillPolicy(1, -1))->toThrow(InvalidArgumentException::class);
    });
});

describe('the bonus of the VIP account', function () {
    it('pays 20% more cash and XP for every lap in the window, each rounded on its own, and says how much of the pay it was', function () {
        $window = new BonusWindow(0, PHP_INT_MAX, 200_000, 200_000);
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig(), null, [$window]);

        // $15.50 + 20% is $18.60, and 25 XP + 20% is 30.
        expect($result->laps)->toBe(30)
            ->and($result->cashCents)->toBe(30 * 1_860)
            ->and($result->xp)->toBe(30 * 30)
            ->and($result->bonusCashCents)->toBe(30 * 310)
            ->and($result->bonusXp)->toBe(30 * 5)
            ->and($result->events[0]->toArray()['payload'])->toMatchArray([
                'cash_cents' => $result->events[0]->toArray()['payload']['laps'] * 1_860,
                'xp' => $result->events[0]->toArray()['payload']['laps'] * 30,
                'bonus_cash_cents' => $result->events[0]->toArray()['payload']['laps'] * 310,
                'bonus_xp' => $result->events[0]->toArray()['payload']['laps'] * 5,
            ]);
    });

    it('leaves the payload of a batch as it was when there is no bonus', function () {
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig());

        expect(array_keys($result->events[0]->toArray()['payload']))->toBe(['segment', 'weather', 'laps', 'cash_cents', 'xp', 'from_ms', 'to_ms'])
            ->and($result->bonusCashCents)->toBe(0)
            ->and($result->bonusXp)->toBe(0);
    });

    it('pays only the laps that finish while the account lasts, when it ends in the middle of the window', function () {
        // The 10th lap finishes at 750 000 ms. An account that ends at 750 000 does not pay it (the window is open at its end), one that ends a millisecond later does.
        $endsBefore = new BonusWindow(0, 10 * EXTRAS_LAP_MS, 200_000, 0);
        $endsAfter = new BonusWindow(0, 10 * EXTRAS_LAP_MS + 1, 200_000, 0);

        $before = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig(), null, [$endsBefore]);
        $after = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig(), null, [$endsAfter]);

        expect($before->bonusCashCents)->toBe(9 * 310)
            ->and($after->bonusCashCents)->toBe(10 * 310)
            ->and($before->bonusXp)->toBe(0)
            ->and($before->cashCents)->toBe(30 * 1_550 + 9 * 310);
    });

    it('pays only the laps after its start, when it starts in the middle of the window', function () {
        $window = new BonusWindow(20 * EXTRAS_LAP_MS, PHP_INT_MAX, 200_000, 200_000);
        $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig(), null, [$window]);

        // The lap that finishes exactly at the start is inside: laps 20 to 30.
        expect($result->bonusCashCents)->toBe(11 * 310)->and($result->bonusXp)->toBe(11 * 5);
    });

    it('pays nothing for a window that is over before the laps, or is not yet there', function () {
        foreach ([new BonusWindow(0, 1_000, 200_000, 200_000), new BonusWindow(3 * EXTRAS_TANK_MS, 4 * EXTRAS_TANK_MS, 200_000, 200_000)] as $window) {
            $result = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig(), null, [$window]);

            expect($result->bonusCashCents)->toBe(0)->and($result->cashCents)->toBe(30 * 1_550);
        }
    });

    it('rounds each lap on its own, so that splitting the laps into steps cannot change what they pay', function () {
        // 25 XP at 14.7% is 3.675, a lap pays 4, and 1 550 cents at 14.7% is 227.85, a lap pays 228.
        $window = new BonusWindow(0, PHP_INT_MAX, 147_000, 147_000);
        $settler = new Settler;
        $config = extrasConfig();
        $once = $settler->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, $config, null, [$window]);

        expect($once->bonusXp)->toBe(30 * 4)->and($once->bonusCashCents)->toBe(30 * 228);

        foreach ([1, 74_999, 75_000, 500_000, 1_234_567, 2_249_999] as $t1) {
            $first = $settler->settle(new RunState(0, 0, EXTRAS_TANK_ML), $t1, $config, null, [$window]);
            $second = $settler->settle($first->state, EXTRAS_TANK_MS, $config, null, [$window]);

            expect($first->cashCents + $second->cashCents)->toBe($once->cashCents)
                ->and($first->xp + $second->xp)->toBe($once->xp)
                ->and($first->bonusCashCents + $second->bonusCashCents)->toBe($once->bonusCashCents);
        }
    });

    it('does not change what a lap pays without the bonus: the pay of the track is the total less the bonus', function () {
        $window = new BonusWindow(0, 12 * EXTRAS_LAP_MS, 333_333, 100_000);
        $plain = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig());
        $paid = (new Settler)->settle(new RunState(0, 0, EXTRAS_TANK_ML), EXTRAS_TANK_MS, extrasConfig(), null, [$window]);

        expect($paid->cashCents - $paid->bonusCashCents)->toBe($plain->cashCents)
            ->and($paid->xp - $paid->bonusXp)->toBe($plain->xp)
            ->and($paid->laps)->toBe($plain->laps)
            ->and($paid->state)->toEqual($plain->state);
    });

    it('counts the laps that finish inside a window', function () {
        $window = new BonusWindow(1_000, 5_000, 1, 1);

        // Laps of 1 000 ms starting at 0 finish at 1 000, 2 000, ...: those at 1 000 to 4 999 are inside.
        expect($window->lapsFinishingInside(0, 1_000, 10))->toBe(4)
            ->and($window->lapsFinishingInside(0, 1_000, 2))->toBe(2)
            ->and($window->lapsFinishingInside(4_000, 1_000, 10))->toBe(0)
            ->and($window->lapsFinishingInside(10_000, 1_000, 10))->toBe(0)
            ->and($window->lapsFinishingInside(0, 3_000, 10))->toBe(1)
            ->and((new BonusWindow(0, 1_000, 1, 1))->lapsFinishingInside(0, 1_000, 10))->toBe(0)
            ->and(Ppm::mul(1_550, 200_000))->toBe(310);
    });

    it('refuses a window that runs backwards', function () {
        expect(fn () => new BonusWindow(10, 5, 1, 1))->toThrow(InvalidArgumentException::class);
    });
});
