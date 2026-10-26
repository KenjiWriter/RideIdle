<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * How a car is sold in the scenario game: for a share of what was PAID for it (the game keeps the price on the car), and not of the value that
 * the anchors give the rung. A car of the ladder is bought at the value of its rung, so its sale is the same as the model had it, but the first
 * car is a wreck of the yard that cost $1,140 and not the $2,000 of the anchors, so the step from it is dearer by the share of the difference.
 *
 * The cost of the step from rung k to k + 1 is `carValue(k + 1) - resale * paid(k)`; the simulator has `carValue(k + 1) - resale * carValue(k)`,
 * so this adds `resale * (carValue(k) - paid(k))`. The share is the one of the ruleset, found from its own step cost.
 */
final class SalesExtra implements Extra
{
    private ?float $resale = null;

    /** @var array<int, float> what was added to the step from each rung that the simulator asked about */
    private array $deltas = [];

    public function name(): string
    {
        return 'sales';
    }

    public function day(DayState $state): DayEffect
    {
        return DayEffect::none();
    }

    public function stepCostDelta(EconomyRules $rules, int $rung): float
    {
        $value = $rules->carValue($rung);
        $paid = $rung === 0 ? $rules->fleetModel()->wreckPrice : $value;

        // The share of a sale is in the step cost of the ruleset: carValue(k + 1) - resale * carValue(k).
        $this->resale ??= ($rules->carValue($rung + 1) - $rules->stepCost($rung)) / $value;

        return $this->deltas[$rung] = $this->resale * ($value - $paid);
    }

    /** @return array{resale: float|null, deltas: array<int, float>} */
    public function report(): array
    {
        return ['resale' => $this->resale, 'deltas' => $this->deltas];
    }
}
