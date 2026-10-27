<?php

declare(strict_types=1);

namespace App\Domain\Run;

use App\Domain\Settlement\Ppm;

/**
 * What the wear of the parts of a car and its service come to for a run, as integers: how worn every part is, how much a lap wears it, the odometer and
 * the service. It is the one place that knows when something of the car changes by itself, so that the frozen configuration of a run can be made again
 * at that moment and at no other: after `lapsToNextChange` laps a part reaches the next stage of its condition, or the car passes the next step of the
 * service. Wear is a function of the laps driven, which is what keeps settlement composable (settling at t1 and then at t2 gives what t2 alone does).
 *
 * Wear is counted in billionths of the life of a part (`WORN_OUT_PPB` is a part that is worn out), the odometer in metres.
 */
final readonly class WearPlan
{
    public const WORN_OUT_PPB = 1_000_000_000;

    /**
     * @param  array<string, int>  $wearPpb  how worn every part on the car is, by slot
     * @param  array<string, int>  $perLapPpb  what a lap wears every part on the car by, by slot
     * @param  array<string, int>  $perKmPpb  what a kilometre wears it by, by slot (a journey wears the parts by this)
     * @param  int  $stagePpb  the condition counts in steps of this much wear
     * @param  int  $serviceEveryM  the car is due for service when it has driven this far since the last one
     * @param  int  $serviceStepM  past that, the performance falls with every step of this many metres
     * @param  int  $serviceMaxSteps  and the steps stop at this many
     * @param  int  $servicePenaltyPpmPerStep  what a step of overdue takes off the performance
     */
    public function __construct(
        public int $lengthM,
        public int $mileageM,
        public int $servicedAtM,
        public array $wearPpb,
        public array $perLapPpb,
        public array $perKmPpb,
        public int $stagePpb,
        public int $serviceEveryM,
        public int $serviceStepM,
        public int $serviceMaxSteps,
        public int $servicePenaltyPpmPerStep,
    ) {}

    /** The condition of a part that is this worn, in ppm (1 000 000 is new). */
    public static function conditionPpm(int $wearPpb): int
    {
        return max(0, Ppm::ONE - intdiv(max(0, $wearPpb), 1000));
    }

    /** The condition as the frozen configuration sees it: the wear is rounded down to the stage it is in, so that it only changes when a stage is passed. */
    public function stagedConditionPpm(int $wearPpb): int
    {
        $staged = min(self::WORN_OUT_PPB, intdiv(max(0, $wearPpb), $this->stagePpb) * $this->stagePpb);

        return self::conditionPpm($staged);
    }

    /** How far the car has driven since its last service, in metres. */
    public function sinceServiceM(): int
    {
        return max(0, $this->mileageM - $this->servicedAtM);
    }

    /** How many steps of the overdue of the service the car is past: none until it is a whole step late. */
    public function overdueSteps(): int
    {
        return min($this->serviceMaxSteps, intdiv(max(0, $this->sinceServiceM() - $this->serviceEveryM), $this->serviceStepM));
    }

    /** What the overdue of the service takes off the performance of the car, in ppm. */
    public function servicePenaltyPpm(): int
    {
        return $this->overdueSteps() * $this->servicePenaltyPpmPerStep;
    }

    /** How far into its overdue the car is, as a share of the longest it counts for, in ppm. */
    public function overdueSharePpm(): int
    {
        return intdiv($this->overdueSteps() * Ppm::ONE, $this->serviceMaxSteps);
    }

    /** The mean wear of the parts on the car, by stage, in ppm of their life: 0 for a car with no parts. */
    public function meanWearPpm(): int
    {
        if ($this->wearPpb === []) {
            return 0;
        }

        $sum = 0;

        foreach ($this->wearPpb as $wear) {
            $sum += Ppm::ONE - $this->stagedConditionPpm($wear);
        }

        return intdiv($sum, count($this->wearPpb));
    }

    /** How many parts on the car are under the condition at which they are unserviceable. */
    public function unserviceableParts(int $thresholdPpm): int
    {
        return count(array_filter($this->wearPpb, fn (int $wear): bool => $this->stagedConditionPpm($wear) < $thresholdPpm));
    }

    /**
     * After how many laps something changes by itself: a part reaches the next stage of its condition, or the car passes the moment its service is
     * due or the next step of its overdue. Null when nothing will (the car has no parts that wear and no service to come).
     */
    public function lapsToNextChange(): ?int
    {
        $laps = [];

        foreach ($this->perLapPpb as $slot => $rate) {
            $wear = $this->wearPpb[$slot] ?? 0;

            if ($rate <= 0 || $wear >= self::WORN_OUT_PPB) {
                continue;
            }

            $threshold = min(self::WORN_OUT_PPB, (intdiv($wear, $this->stagePpb) + 1) * $this->stagePpb);
            $laps[] = max(1, intdiv($threshold - $wear + $rate - 1, $rate));
        }

        $boundaryM = $this->nextServiceBoundaryM();

        if ($boundaryM !== null && $this->lengthM > 0) {
            $laps[] = max(1, intdiv($boundaryM - $this->mileageM + $this->lengthM - 1, $this->lengthM));
        }

        return $laps === [] ? null : min($laps);
    }

    /** The odometer at which the service is due, or the overdue passes the next step: null when it is past the last step. */
    private function nextServiceBoundaryM(): ?int
    {
        $due = $this->servicedAtM + $this->serviceEveryM;

        if ($this->mileageM < $due) {
            return $due;
        }

        $step = intdiv($this->mileageM - $due, $this->serviceStepM) + 1;

        return $step <= $this->serviceMaxSteps ? $due + $step * $this->serviceStepM : null;
    }
}
