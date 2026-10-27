<?php

declare(strict_types=1);

use App\Content\Rules;

// The numbers of the wear in the rules: 40 000 km of life for tires and 80 000 for the suspension, a reference power of 97 kW, a base chance of a breakdown of
// 0.3% a lap, a service every 5 000 km.

it('wears a part by 1 / life of its slot a kilometre, faster on gravel, with a powerful car and with a booster on the engine', function () {
    $rules = app(Rules::class);

    expect($rules->wearPerKmPpb('tires', 'asphalt', 97))->toBe(25_000)
        ->and($rules->wearPerKmPpb('suspension', 'asphalt', 97))->toBe(12_500)
        // Gravel wears tires and suspension twice as fast, and not the brakes.
        ->and($rules->wearPerKmPpb('tires', 'gravel', 97))->toBe(50_000)
        ->and($rules->wearPerKmPpb('brakes', 'gravel', 97))->toBe(20_000)
        // Twice the power wears by the square root of two more, and never less than 0.8 or more than 2 times.
        ->and($rules->wearPerKmPpb('tires', 'asphalt', 194))->toBe(35_355)
        ->and($rules->wearPerKmPpb('tires', 'asphalt', 10))->toBe(20_000)
        ->and($rules->wearPerKmPpb('tires', 'asphalt', 9_700))->toBe(50_000)
        // A booster takes it out of the engine and what drives with it, not the tires.
        ->and($rules->wearPerKmPpb('engine', 'asphalt', 97, 50))->toBe(12_500)
        ->and($rules->wearPerKmPpb('engine', 'asphalt', 97))->toBe(8_333)
        ->and($rules->wearPerKmPpb('tires', 'asphalt', 97, 50))->toBe(25_000);
});

it('gives a part what is left of its statistics: 60% when it is worn out, in stages of 5% of its life', function () {
    $rules = app(Rules::class);

    expect($rules->conditionStatPpm(1_000_000))->toBe(1_000_000)
        ->and($rules->conditionStatPpm(500_000))->toBe(800_000)
        ->and($rules->conditionStatPpm(0))->toBe(600_000)
        ->and($rules->stagedConditionPpm(49_999_999))->toBe(1_000_000)
        ->and($rules->stagedConditionPpm(50_000_000))->toBe(950_000)
        ->and($rules->stagedConditionPpm(2_000_000_000))->toBe(0);
});

it('prices the repair of a part at half of its price for the condition it is short of, its time as that share of putting it on, and a service as a share of the income', function () {
    $rules = app(Rules::class);

    expect($rules->partRepairCostCents(57_000, 500_000))->toBe(14_250)
        ->and($rules->partRepairCostCents(57_000, 1_000_000))->toBe(0)
        ->and($rules->partRepairCostCents(57_000, 0))->toBe(28_500)
        ->and($rules->partRepairMs('tires', 2, 500_000))->toBe(117_600)
        // 4% of what a lap of $15.50 earns in the 1 924 laps of 5 000 km on a track of 2.6 km, and not less than $20.
        ->and($rules->serviceIntervalLaps(2_600))->toBe(1_924)
        ->and($rules->serviceCostCents(1_550, 2_600))->toBe(119_288)
        ->and($rules->serviceCostCents(3_100, 2_600))->toBe(238_576)
        ->and($rules->serviceCostCents(1, 2_600))->toBe(2_000)
        ->and($rules->serviceJobMs())->toBe(882_000);
});

it('makes a breakdown likelier with the wear of the parts, the overdue of the service and every part that is unserviceable', function () {
    $rules = app(Rules::class);
    $base = $rules->breakChancePerLapPpm();

    expect($rules->breakChancePerLapPpmFor(1_000_000))->toBe($base)
        // 1 + 3 x 20% mean wear.
        ->and($rules->breakChancePerLapPpmFor(1_000_000, 200_000))->toBe((int) round($base * 1.6))
        // 1 + 2 x the whole of the overdue.
        ->and($rules->breakChancePerLapPpmFor(1_000_000, 0, 1_000_000))->toBe($base * 3)
        // 1 + 1 for the part that is unserviceable, and 1 + 2 for two.
        ->and($rules->breakChancePerLapPpmFor(1_000_000, 0, 0, 1))->toBe($base * 2)
        ->and($rules->breakChancePerLapPpmFor(1_000_000, 0, 0, 2))->toBe($base * 3)
        ->and($rules->breakChancePerLapPpmFor(1_000_000, 500_000, 1_000_000, 3))->toBeLessThanOrEqual(1_000_000);
});

it('knows how long the laps are, and what the odometer of a wreck and of a new car says', function () {
    $rules = app(Rules::class);

    expect($rules->trackLengthM('zlomowo-street'))->toBe(2_600)
        ->and($rules->trackLengthM('autodrom-circuit'))->toBe(3_200)
        ->and($rules->wreckMileageKm('rdzak-gt-4'))->toBe(260_000)
        ->and($rules->wreckMileageKm('some-other-car'))->toBe(0)
        ->and($rules->dealerMileageKm())->toBe(10);
});
