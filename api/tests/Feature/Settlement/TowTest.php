<?php

declare(strict_types=1);

use App\Domain\Run\RunFreezer;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Services\TowTerms;
use Database\Factories\RaceRunFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

/** A repair costs $30.00, fuel $2.00 per liter, the tow makes the car wait 5 minutes and leaves 2 laps of fuel. */
function towTerms(): TowTerms
{
    return new TowTerms(repairCostCents: 3_000, fuelPriceCentsPerLiter: 200, delayMs: 300_000, fuelLaps: 2);
}

/** A stopped car with the given fuel, for the rule that decides whether the tow is due. */
function stoppedCar(string $reason, int $fuelMl = 30_000, int $fuelPerLapMl = 1_500): RaceRun
{
    return new RaceRun([
        'status' => RaceRun::STATUS_STOPPED,
        'stop_reason' => $reason,
        'fuel_ml' => $fuelMl,
        'config' => RaceRunFactory::constantConfig(fuelPerLapMl: $fuelPerLapMl),
    ]);
}

/** A run that earns only 1 cent a lap, so a driver stays poor. */
function poorConfig(): array
{
    return RaceRunFactory::constantConfig(cash: 100);
}

describe('the terms of the tow', function () {
    it('asks nothing of a car that is not stopped', function () {
        $driving = new RaceRun(['status' => RaceRun::STATUS_RUNNING, 'fuel_ml' => 0, 'config' => RaceRunFactory::constantConfig()]);

        expect(towTerms()->restartCostCents($driving))->toBeNull()
            ->and(towTerms()->isDue($driving, 0))->toBeFalse();
    });

    it('counts the repair of a car that broke down', function () {
        expect(towTerms()->restartCostCents(stoppedCar('breakdown')))->toBe(3_000);
    });

    it('counts the fuel for one lap of a car that ran dry, in whole liters', function (int $fuelMl, int $cents) {
        expect(towTerms()->restartCostCents(stoppedCar('fuel_empty', $fuelMl)))->toBe($cents);
    })->with([
        'an empty tank needs 1.5 l, so 2 l' => [0, 400],
        'with 500 ml 1 l more is enough' => [500, 200],
        'with 100 ml 1.4 l are missing, so 2 l' => [100, 400],
        'with exactly a lap of fuel nothing is missing' => [1_500, 0],
    ]);

    it('adds the fuel to the repair when the car broke down with an empty tank', function () {
        expect(towTerms()->restartCostCents(stoppedCar('breakdown', 0)))->toBe(3_000 + 400);
    });

    it('is due only when the cash is below the cost, not when it is equal', function () {
        $broken = stoppedCar('breakdown');

        expect(towTerms()->isDue($broken, 2_999))->toBeTrue()
            ->and(towTerms()->isDue($broken, 3_000))->toBeFalse()
            ->and(towTerms()->isDue($broken, 100_000))->toBeFalse();
    });

    it('is never due for a car that needs nothing to go on', function () {
        // Stopped, but with fuel for a lap and not broken: a restart is free, so there is nothing to tow.
        expect(towTerms()->isDue(stoppedCar('fuel_empty', 1_500), 0))->toBeFalse();
    });

    it('is built from the rules', function () {
        $terms = TowTerms::fromRules(app(App\Content\Rules::class));

        expect($terms)->toEqual(new TowTerms(3_000, 200, 300_000, 2));
    });
});

describe('towing a car', function () {
    it('tows a broken car whose driver cannot pay: free, a new plan, and a wait', function () {
        $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
        $inputs = dryInputs(breakChancePpm: 50_000);

        $towed = runService()->tow($run->id, $inputs, towTerms(), 1_000_000);

        $fresh = $run->fresh();
        $expected = (new RunFreezer())->freeze($inputs, $fresh->seed, 1);

        expect($towed->towed)->toBeTrue()
            ->and($fresh->status)->toBe('running')
            ->and($fresh->stop_reason)->toBeNull()
            ->and($fresh->seed)->not->toBe($run->seed)
            ->and($fresh->break_after_laps)->toBe($expected->breakAfterLaps)
            ->and($fresh->config)->toEqual($expected->config)
            ->and($fresh->cursor_ms)->toBe(1_300_000) // now plus the wait
            ->and($fresh->fuel_ml)->toBe(45_000 - 1_500)
            ->and($fresh->state_version)->toBe(2) // the settlement of the lap and the tow
            ->and(cashOf($run))->toBe(1_550)
            ->and(LedgerEntry::query()->count())->toBe(1); // only the lap, the tow cost nothing
    });

    it('does not tow a driver whose earnings before the breakdown pay for the repair', function () {
        $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => 2]); // two laps earn $31.00

        $towed = runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        $fresh = $run->fresh();
        expect($towed->towed)->toBeFalse()
            ->and($fresh->status)->toBe('stopped')
            ->and($fresh->stop_reason)->toBe('breakdown')
            ->and($fresh->seed)->toBe($run->seed);
    });

    it('tows a car that ran dry and leaves fuel for two laps, keeping the plan of the breakdown', function () {
        $run = aRun(['config' => poorConfig(), 'fuel_ml' => 1_500, 'break_after_laps' => 500]);

        $towed = runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        $fresh = $run->fresh();
        expect($towed->towed)->toBeTrue()
            ->and($fresh->status)->toBe('running')
            ->and($fresh->fuel_ml)->toBe(3_000)
            ->and($fresh->cursor_ms)->toBe(1_300_000)
            ->and($fresh->seed)->toBe($run->seed)
            ->and($fresh->break_after_laps)->toBe(500)
            ->and($fresh->config)->toEqual(poorConfig());
    });

    it('repairs and refuels a car that broke down with an empty tank', function () {
        $run = aRun(['config' => poorConfig(), 'fuel_ml' => 1_500, 'break_after_laps' => 1]);

        runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        $fresh = $run->fresh();
        expect($fresh->status)->toBe('running')
            ->and($fresh->seed)->not->toBe($run->seed)
            ->and($fresh->fuel_ml)->toBe(3_000);
    });

    it('does not touch the fuel of a car that still has enough for a lap', function () {
        $run = aRun(['fuel_ml' => 40_000, 'break_after_laps' => 1]);

        runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        expect($run->fresh()->fuel_ml)->toBe(40_000 - 1_500);
    });

    it('leaves a car that is driving alone', function () {
        $run = aRun(['fuel_ml' => 45_000]);

        $towed = runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        $fresh = $run->fresh();
        expect($towed->towed)->toBeFalse()
            ->and($fresh->status)->toBe('running')
            ->and($fresh->cursor_ms)->toBe(975_000) // 13 laps, the lap in progress is not dropped
            ->and($fresh->state_version)->toBe(1); // only the settlement
    });

    it('tows once when it is asked for twice', function () {
        $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);

        $first = runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);
        $seed = $run->fresh()->seed;
        $again = runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        $fresh = $run->fresh();
        expect($first->towed)->toBeTrue()
            ->and($again->towed)->toBeFalse()
            ->and($fresh->seed)->toBe($seed)
            ->and($fresh->cursor_ms)->toBe(1_300_000)
            ->and($fresh->state_version)->toBe(2);
    });

    it('lets the car drive again only after the wait', function () {
        $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
        runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        // The car is back at 1 300 000. Nothing is driven before, and the first lap ends one lap time later.
        runService()->settle($run->id, 1_300_000 - 1);
        expect($run->fresh()->laps_done)->toBe(1);

        runService()->settle($run->id, 1_300_000 + 75_000 - 1);
        expect($run->fresh()->laps_done)->toBe(1);

        runService()->settle($run->id, 1_300_000 + 75_000);
        expect($run->fresh()->laps_done)->toBe(2)
            ->and(cashOf($run))->toBe(1_550 + 2_000); // the first lap at the old reward, the next at the new one
    });

    it('does not pay for the time of the wait', function () {
        $run = aRun(['fuel_ml' => 450_000, 'break_after_laps' => 1]);
        runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000);

        runService()->settle($run->id, 2_000_000);

        // 700 s after the car came back are 9 laps of 75 s. Without the wait it would be 13.
        expect($run->fresh()->laps_done)->toBe(1 + 9);
    });

    it('undoes the settlement when the tow cannot be saved', function () {
        $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
        $seed = $run->seed;

        // The first update of the run is the settlement, the second one the tow.
        $updates = 0;
        $event = 'eloquent.updating: '.RaceRun::class;
        Event::listen($event, static function () use (&$updates): void {
            if (++$updates === 2) {
                throw new RuntimeException('The disk is full.');
            }
        });

        try {
            expect(fn () => runService()->tow($run->id, dryInputs(), towTerms(), 1_000_000))->toThrow(RuntimeException::class);
        } finally {
            Event::forget($event);
        }

        $fresh = $run->fresh();
        expect($fresh->seed)->toBe($seed)
            ->and($fresh->laps_done)->toBe(0)
            ->and($fresh->state_version)->toBe(0)
            ->and(Settlement::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('fails for a run that does not exist', function () {
        runService()->tow(999_999, dryInputs(), towTerms(), 1_000);
    })->throws(ModelNotFoundException::class);
});
