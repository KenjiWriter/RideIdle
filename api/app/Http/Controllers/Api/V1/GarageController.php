<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Http\Resources\WorkshopJobResource;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\WorkshopJob;
use App\Services\Garage;
use App\Services\RunService;
use App\Services\Workshop;
use App\Support\I18nText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The garage of the driver: the car with the part in each of its slots, the statistics those parts make, the parts in the
 * inventory, and the workshop queue. Putting a part on or off is a job of the workshop that takes time.
 */
final class GarageController extends Controller
{
    /** The slots of a car, in the order the garage shows them. The engine is the one of the body for now. */
    private const SLOTS = ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank'];

    public function __construct(
        private readonly Garage $garage,
        private readonly Workshop $workshop,
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
    ) {}

    /** The garage as it is now: what is due in the workshop is carried out first. */
    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);

        return $this->respond($userId);
    }

    /**
     * Queues a job: `kind` is mount or unmount, `part` the id of a part of the driver. Putting a part in a slot that has one takes
     * the old one off first, which is a job of its own. The jobs are done one after the other by the one mechanic, and the car
     * stands in the workshop while one of them is being done.
     */
    public function queueJob(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in([WorkshopJob::KIND_MOUNT, WorkshopJob::KIND_UNMOUNT])],
            'part' => ['required', 'integer', 'min:1'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->queue($userId, $data['kind'], $data['part']);

        return $this->respond($userId, 201);
    }

    /**
     * Cancels a job: one that waits, or one that the mechanic is at (the work done on it is lost, and its part stays where it
     * was). A job that goes only with it goes too: cancelling the taking off of a part cancels the putting on of the one that was
     * to take its place. Nothing is paid or given back. `cancelled` lists the jobs that were cancelled, the asked one first.
     */
    public function cancelJob(Request $request, int $job): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $cancelled = $this->workshop->cancel($userId, $job);

        return $this->respond($userId, 200, ['cancelled' => array_map(static fn (WorkshopJob $gone): int => $gone->id, $cancelled)]);
    }

    /** @param  array<string, mixed>  $extra  more keys of the top level of the answer */
    private function respond(int $userId, int $status = 200, array $extra = []): JsonResponse
    {
        $car = $this->garage->carOf($userId);
        $run = RaceRun::query()->where('user_id', $userId)->orderByDesc('id')->first();

        return response()->json([
            'data' => [
                'car' => $car === null ? null : $this->carData($car, $run),
                'inventory' => PartResource::collection(
                    Part::query()->where('user_id', $userId)->whereNull('car_id')->orderBy('id')->get(),
                )->resolve(),
                'workshop' => [
                    'queue_max' => $this->rules->workshopMaxQueue(),
                    'mechanic' => $this->rules->mechanic(),
                    'jobs' => WorkshopJobResource::collection($this->workshop->pendingJobs($userId))->resolve(),
                ],
            ],
            'now_ms' => RunService::nowMs(),
            ...$extra,
        ], $status)->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function carData(Car $car, ?RaceRun $run): array
    {
        $vehicle = $this->catalog->vehicle($car->vehicle_slug);
        $mounted = $car->parts()->whereNotNull('mounted_slot')->get()->keyBy('mounted_slot');
        $slugs = $mounted->pluck('part_slug', 'mounted_slot')->all();

        return [
            'id' => $car->id,
            'level' => $car->level,
            // The experience of the car, and where its level begins and ends: the bar of the garage is `total` between the two.
            'xp' => [
                'total' => $car->xp,
                'level_start' => $this->rules->carXpToReach($car->level),
                'next_level_at' => $car->level >= $this->rules->carMaxLevel() ? null : $this->rules->carXpToReach($car->level + 1),
            ],
            // What the next level that opens a tier of parts opens, so that a locked part can say what it waits for.
            'tier_unlock' => $this->tierUnlock($car->level),
            'vehicle' => [
                'slug' => $car->vehicle_slug,
                'name' => $vehicle !== null ? I18nText::from($vehicle['name'])->for() : $car->vehicle_slug,
                'engine' => $vehicle !== null ? "{$vehicle['engine']['layout']} {$vehicle['engine']['displacement_l']}" : null,
                'power_kw' => $vehicle['power_kw'] ?? null,
                'drivetrain' => $vehicle['drivetrain'] ?? null,
            ],
            'stats' => $this->rounded($this->inputs->stats($car->vehicle_slug, $slugs)->stats),
            'base_stats' => $this->rounded($this->inputs->stats($car->vehicle_slug)->stats),
            // The bar of a statistic is full at its ceiling: what the best car of the whole game reaches, the same on every track.
            'ceilings' => $this->rules->statCeilings(),
            'tank_ml' => $this->inputs->tankMl($car->vehicle_slug, $slugs),
            'slots' => array_map(static fn (string $slot): array => [
                'slot' => $slot,
                'part' => ($part = $mounted->get($slot)) === null ? null : PartResource::make($part)->resolve(),
                'built_in' => $slot === 'engine',
            ], self::SLOTS),
            'lap_ms' => $run?->config['lap_ms'] ?? null,
            'run' => $run === null ? null : ['id' => $run->id, 'status' => $run->status, 'stop_reason' => $run->stop_reason],
        ];
    }

    /**
     * The next tier of parts the car does not have yet, and the level it takes to mount it, or null when the highest
     * level of a car does not reach another tier.
     *
     * @return array{tier: int, level: int}|null
     */
    private function tierUnlock(int $carLevel): ?array
    {
        $tier = $this->rules->maxMountableTier($carLevel) + 1;
        $level = $this->rules->requiredCarLevel($tier);

        return $level <= $this->rules->carMaxLevel() ? ['tier' => $tier, 'level' => $level] : null;
    }

    /**
     * @param  array<string, float>  $stats
     * @return array<string, float>
     */
    private function rounded(array $stats): array
    {
        return array_map(static fn (float $value): float => round($value, 2), $stats);
    }
}
