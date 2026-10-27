<?php

declare(strict_types=1);

use App\Domain\Run\WearPlan;

// A car with tires that wear by 32 500 billionths of their life in a lap of 2.6 km (40 000 km of life: 25 000 per km, 2.6 km), in stages of 5%, and a service
// every 5 000 km that is overdue in steps of 500 km, five steps at the most, 4% a step.
function plan(array $wear = ['tires' => 0], int $mileageM = 0, int $servicedAtM = 0, array $perLap = ['tires' => 32_500]): WearPlan
{
    return new WearPlan(
        lengthM: 2_600,
        mileageM: $mileageM,
        servicedAtM: $servicedAtM,
        wearPpb: $wear,
        perLapPpb: $perLap,
        perKmPpb: array_map(static fn (int $rate): int => intdiv($rate * 1000, 2_600), $perLap),
        stagePpb: 50_000_000,
        serviceEveryM: 5_000_000,
        serviceStepM: 500_000,
        serviceMaxSteps: 5,
        servicePenaltyPpmPerStep: 40_000,
    );
}

describe('the condition of a part', function () {
    it('is what is left of its life, and the frozen configuration sees it in stages of 5%', function () {
        $plan = plan();

        expect(WearPlan::conditionPpm(0))->toBe(1_000_000)
            ->and(WearPlan::conditionPpm(75_000_000))->toBe(925_000)
            ->and(WearPlan::conditionPpm(1_000_000_000))->toBe(0)
            ->and(WearPlan::conditionPpm(2_000_000_000))->toBe(0)
            // Inside a stage nothing changes, and at the edge of the next one the condition is that of the stage.
            ->and($plan->stagedConditionPpm(49_999_999))->toBe(1_000_000)
            ->and($plan->stagedConditionPpm(50_000_000))->toBe(950_000)
            ->and($plan->stagedConditionPpm(99_999_999))->toBe(950_000)
            ->and($plan->stagedConditionPpm(1_000_000_000))->toBe(0);
    });

    it('counts the parts that are under the condition at which they are unserviceable, and the mean wear of the parts', function () {
        $plan = plan(['tires' => 0, 'brakes' => 100_000_000, 'exhaust' => 850_000_000], perLap: ['tires' => 1, 'brakes' => 1, 'exhaust' => 1]);

        // 0%, 10% and 85% worn: the last one has 15% of its life left, under the 20% of the rules.
        expect($plan->unserviceableParts(200_000))->toBe(1)
            ->and($plan->meanWearPpm())->toBe(intdiv(0 + 100_000 + 850_000, 3))
            ->and(plan([])->meanWearPpm())->toBe(0);
    });
});

describe('when something changes by itself', function () {
    it('is at the lap at which a part reaches the next stage of its condition: 5% of its life at 32 500 a lap is 1 539 laps', function () {
        expect(plan()->lapsToNextChange())->toBe(1_539)
            // A part that is 1 000 short of the next stage gets there in a lap, and one that is just in a stage has the whole of it to go.
            ->and(plan(['tires' => 49_999_000])->lapsToNextChange())->toBe(1)
            ->and(plan(['tires' => 50_000_000])->lapsToNextChange())->toBe(1_539);
    });

    it('is at the lap at which the service falls due when that comes first, then at every step of the overdue, and never past the last one', function () {
        // 10 km short of the 5 000 km: 4 laps of 2.6 km (3.85 up).
        expect(plan(mileageM: 4_990_000)->lapsToNextChange())->toBe(4)
            // Due a moment ago: the next boundary is 500 km later, which is 116 laps from a car that is 200 m past (300 km / 2.6).
            ->and(plan(mileageM: 5_200_000)->lapsToNextChange())->toBe(116)
            // Past the fifth step nothing changes any more, and a car with no part that wears has nothing to change.
            ->and(plan(['tires' => 0], mileageM: 7_600_000, perLap: [])->lapsToNextChange())->toBeNull()
            // And the part, which has 1 539 laps to its stage, is the one that counts there.
            ->and(plan(mileageM: 7_600_000)->lapsToNextChange())->toBe(1_539);
    });

    it('says nothing for a part that is worn out', function () {
        expect(plan(['tires' => 1_000_000_000], mileageM: 7_600_000)->lapsToNextChange())->toBeNull();
    });
});

describe('the service', function () {
    it('is due after 5 000 km since the last, and is late by whole steps of 500 km, each taking 4% off the performance, five at the most', function () {
        // The odometer says 260 123 km and the car was serviced at 255 000: 5 123 km since, 123 km late: not a whole step yet.
        $first = plan(mileageM: 260_123_000, servicedAtM: 255_000_000);

        expect($first->sinceServiceM())->toBe(5_123_000)
            ->and($first->overdueSteps())->toBe(0)
            ->and($first->servicePenaltyPpm())->toBe(0)
            ->and(plan(mileageM: 5_500_000)->overdueSteps())->toBe(1)
            ->and(plan(mileageM: 5_500_000)->servicePenaltyPpm())->toBe(40_000)
            ->and(plan(mileageM: 6_700_000)->servicePenaltyPpm())->toBe(120_000)
            ->and(plan(mileageM: 99_000_000)->overdueSteps())->toBe(5)
            ->and(plan(mileageM: 99_000_000)->servicePenaltyPpm())->toBe(200_000)
            ->and(plan(mileageM: 99_000_000)->overdueSharePpm())->toBe(1_000_000)
            ->and(plan(mileageM: 6_000_000)->overdueSharePpm())->toBe(400_000);
    });
});
