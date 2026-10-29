<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Models\Car;
use App\Models\Part;
use App\Models\WorkshopJob;

/**
 * What a car must have for a part to be put on it (`requires` of the definition of the part, see part.schema.json): an engine with enough cylinders, and, for an
 * intercooler, a booster. An engine part also has to fit the bay of the body (the layouts it takes and the displacement it holds, by the class of the car) and to
 * leave the booster on the car enough cylinders. The shop says what a car lacks before it sells, the garage before the driver queues the job, and the workshop
 * refuses the job that the car cannot take.
 *
 * What is on the car is what will be on it when the jobs that are queued are done: a part that waits to go on counts, one that waits to come off does not.
 */
final class PartRequirements
{
    public function __construct(private readonly Catalog $catalog, private readonly Rules $rules) {}

    /** How many cylinders an engine layout has: the number in it (R4 is 4, V8 is 8). */
    public function cylindersOfLayout(string $layout): int
    {
        return (int) preg_replace('/\D/', '', $layout);
    }

    /**
     * What is on the car, or on its way onto it, that the requirements ask about: the engine part (null for the factory engine) and the booster (null for none),
     * as definitions, by the role of their slots (`engine` and `boost`, whatever the slots are called in the game). The shop and the garage ask once for a car and
     * check every part against it.
     *
     * @return array{engine: array<string, mixed>|null, boost: array<string, mixed>|null}
     */
    public function stateOf(Car $car): array
    {
        $jobs = WorkshopJob::query()
            ->where('car_id', $car->id)
            ->where('status', '!=', WorkshopJob::STATUS_DONE)
            ->whereIn('kind', [WorkshopJob::KIND_MOUNT, WorkshopJob::KIND_UNMOUNT])
            ->with('part')
            ->get();
        $slots = array_filter(['engine' => $this->rules->model()->slotOf('engine'), 'boost' => $this->rules->model()->slotOf('boost')]);
        $mounted = Part::query()->where('car_id', $car->id)->whereIn('mounted_slot', array_values($slots))->get()->keyBy('mounted_slot');

        $state = ['engine' => null, 'boost' => null];

        foreach ($slots as $role => $slot) {
            $incoming = $jobs->first(fn (WorkshopJob $job): bool => $job->kind === WorkshopJob::KIND_MOUNT && $job->part?->slot === $slot);
            $part = $incoming?->part;

            // A part that is on the car and not on its way off stays there.
            if ($part === null && ($onCar = $mounted->get($slot)) !== null && ! $jobs->contains(fn (WorkshopJob $job): bool => $job->kind === WorkshopJob::KIND_UNMOUNT && $job->part_id === $onCar->id)) {
                $part = $onCar;
            }

            $state[$role] = $part === null ? null : $this->catalog->part($part->part_slug);
        }

        return $state;
    }

    /** Whether the car has a booster on it that is not on its way off, or a booster that a job is putting on it. */
    public function boosterOn(Car $car): bool
    {
        return $this->stateOf($car)['boost'] !== null;
    }

    /** How many cylinders the engine of the car has: the engine part that is on it (or is going on), else the factory engine. */
    public function cylindersOf(Car $car, ?array $state = null): int
    {
        $engine = ($state ?? $this->stateOf($car))['engine'];

        return $this->cylindersOfLayout($engine['engine']['layout'] ?? $this->factoryLayout($car));
    }

    /**
     * The first thing a car lacks for a part: `cylinders` (with the `required` number and the `current` one), `booster`, `bay_layout` (the bay does not take the
     * layout: `layout` and what it `allowed`), `bay_size` (the engine is too big: its `displacement_l` and the `max_displacement_l`) or `booster_cylinders` (the
     * booster on the car needs more cylinders than this engine has: `required` and `current`), or null when the car meets all of it.
     *
     * @param  array<string, mixed>  $definition  the definition of the part
     * @param  array{engine: array<string, mixed>|null, boost: array<string, mixed>|null}|null  $state  what is on the car, when the caller has it already (it is asked for when not given)
     * @return array{reason: string, required?: int, current?: int, layout?: string, allowed?: list<string>, displacement_l?: int|float, max_displacement_l?: int|float}|null
     */
    public function lack(array $definition, Car $car, ?array $state = null): ?array
    {
        $state ??= $this->stateOf($car);
        $requires = $definition['requires'] ?? [];

        if (isset($requires['min_cylinders'])) {
            $current = $this->cylindersOf($car, $state);

            if ($current < $requires['min_cylinders']) {
                return ['reason' => 'cylinders', 'required' => (int) $requires['min_cylinders'], 'current' => $current];
            }
        }

        if (($requires['booster'] ?? false) && $state['boost'] === null) {
            return ['reason' => 'booster'];
        }

        return isset($definition['engine']) ? $this->engineLack($definition['engine'], $car, $state) : null;
    }

    /**
     * What stops the engine of the car from coming out: the booster that is on it needs more cylinders than the factory engine has. Null when nothing does, or
     * when the car is on its factory engine already.
     *
     * @return array{reason: string, required: int, current: int}|null
     */
    public function lackWithoutEngine(Car $car): ?array
    {
        $state = $this->stateOf($car);
        $needed = $state['boost']['requires']['min_cylinders'] ?? null;
        $factory = $this->cylindersOfLayout($this->factoryLayout($car));

        return $state['engine'] !== null && $needed !== null && $factory < $needed ? ['reason' => 'booster_cylinders', 'required' => (int) $needed, 'current' => $factory] : null;
    }

    /**
     * Whether the engine fits the bay of the body of the car, and the booster on it still has the cylinders it needs.
     *
     * @param  array{layout: string, displacement_l: int|float}  $engine
     * @param  array{engine: array<string, mixed>|null, boost: array<string, mixed>|null}  $state
     * @return array{reason: string, required?: int, current?: int, layout?: string, allowed?: list<string>, displacement_l?: int|float, max_displacement_l?: int|float}|null
     */
    private function engineLack(array $engine, Car $car, array $state): ?array
    {
        $bay = $this->rules->engineBay((string) ($this->catalog->vehicle($car->vehicle_slug)['class'] ?? ''));

        if ($bay !== null && ! in_array($engine['layout'], $bay['layouts'], true)) {
            return ['reason' => 'bay_layout', 'layout' => $engine['layout'], 'allowed' => $bay['layouts']];
        }

        if ($bay !== null && $engine['displacement_l'] > $bay['max_displacement_l']) {
            return ['reason' => 'bay_size', 'displacement_l' => $engine['displacement_l'], 'max_displacement_l' => $bay['max_displacement_l']];
        }

        $needed = $state['boost']['requires']['min_cylinders'] ?? null;
        $current = $this->cylindersOfLayout($engine['layout']);

        return $needed !== null && $current < $needed ? ['reason' => 'booster_cylinders', 'required' => (int) $needed, 'current' => $current] : null;
    }

    /** The layout of the factory engine of the car (R4, V8, ...). */
    private function factoryLayout(Car $car): string
    {
        return (string) ($this->catalog->vehicle($car->vehicle_slug)['engine']['layout'] ?? '');
    }
}
