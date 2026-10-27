<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Models\Car;
use App\Models\Part;
use App\Models\WorkshopJob;

/**
 * What a car must have for a part to be put on it (`requires` of the definition of the part, see part.schema.json): an engine with enough cylinders, and, for an
 * intercooler, a booster. The shop says what a car lacks before it sells, the garage before the driver queues the job, and the workshop refuses the job that
 * the car cannot take.
 */
final class PartRequirements
{
    public function __construct(private readonly Catalog $catalog) {}

    /** How many cylinders the engine of a car has: the number in its layout (R4 is 4, V8 is 8). */
    public function cylindersOf(string $vehicleSlug): int
    {
        return (int) preg_replace('/\D/', '', (string) ($this->catalog->vehicle($vehicleSlug)['engine']['layout'] ?? ''));
    }

    /**
     * The first thing a car lacks for a part: `cylinders` (with the `required` number and the `current` one) or `booster`, or null when the car meets all of it.
     *
     * @param  array<string, mixed>  $definition  the definition of the part
     * @param  bool|null  $boosterOn  whether the car has a booster or is getting one, when the caller knows it already (it is asked for, once, when it matters and is not given)
     * @return array{reason: string, required?: int, current?: int}|null
     */
    public function lack(array $definition, Car $car, ?bool $boosterOn = null): ?array
    {
        $requires = $definition['requires'] ?? [];

        if (isset($requires['min_cylinders'])) {
            $current = $this->cylindersOf($car->vehicle_slug);

            if ($current < $requires['min_cylinders']) {
                return ['reason' => 'cylinders', 'required' => (int) $requires['min_cylinders'], 'current' => $current];
            }
        }

        if (($requires['booster'] ?? false) && ! ($boosterOn ?? $this->boosterOn($car))) {
            return ['reason' => 'booster'];
        }

        return null;
    }

    /** Whether the car has a booster on it that is not on its way off, or a booster that a job is putting on it. */
    public function boosterOn(Car $car): bool
    {
        $jobs = WorkshopJob::query()
            ->where('car_id', $car->id)
            ->where('status', '!=', WorkshopJob::STATUS_DONE)
            ->whereIn('kind', [WorkshopJob::KIND_MOUNT, WorkshopJob::KIND_UNMOUNT])
            ->with('part')
            ->get();

        if ($jobs->contains(fn (WorkshopJob $job): bool => $job->kind === WorkshopJob::KIND_MOUNT && $job->part?->slot === 'boost')) {
            return true;
        }

        $mounted = Part::query()->where('car_id', $car->id)->where('mounted_slot', 'boost')->first();

        return $mounted !== null && ! $jobs->contains(fn (WorkshopJob $job): bool => $job->kind === WorkshopJob::KIND_UNMOUNT && $job->part_id === $mounted->id);
    }
}
