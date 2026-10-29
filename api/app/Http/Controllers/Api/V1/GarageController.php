<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\WearPlan;
use App\Domain\Workshop\WorkshopGear;
use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Http\Resources\WorkshopJobResource;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\WorkshopJob;
use App\Services\ContentImages;
use App\Services\Garage;
use App\Services\Ledger;
use App\Services\PartMerges;
use App\Services\RunService;
use App\Services\Workshop;
use App\Services\WorkshopEquipment;
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
    public function __construct(
        private readonly Garage $garage,
        private readonly Workshop $workshop,
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
        private readonly PartMerges $merges,
        private readonly WorkshopEquipment $equipment,
        private readonly Ledger $ledger,
    ) {}

    /** The garage as it is now: what is due in the workshop is carried out first. */
    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);

        return $this->respond($userId);
    }

    /**
     * Queues a job: `kind` is mount, unmount or upgrade, `part` the id of a part of the driver. Putting a part in a slot that has one takes
     * the old one off first, which is a job of its own; an upgrade is a job on a part that sits on the car and is paid when it is queued; a merge
     * (`with` is the second part, which is used up) is one on two parts of the inventory, paid when it is queued, and the car does not stand for it; a repair
     * puts a worn part right, a service is a job on the car and takes no `part`. The
     * jobs are done one after the other by the one mechanic, and the car stands in the workshop while one of them is being done.
     */
    public function queueJob(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in([WorkshopJob::KIND_MOUNT, WorkshopJob::KIND_UNMOUNT, WorkshopJob::KIND_UPGRADE, WorkshopJob::KIND_MERGE, WorkshopJob::KIND_REPAIR, WorkshopJob::KIND_SERVICE])],
            'part' => ['required_unless:kind,'.WorkshopJob::KIND_SERVICE, 'nullable', 'integer', 'min:1'],
            'with' => ['required_if:kind,'.WorkshopJob::KIND_MERGE, 'nullable', 'integer', 'min:1'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $this->workshop->queue($userId, $data['kind'], $data['part'] ?? null, withPartId: $data['with'] ?? null);

        return $this->respond($userId, 201);
    }

    /**
     * Cancels a job: one that waits, or one that the mechanic is at (the work done on it is lost, and its part stays where it
     * was). A job that goes only with it goes too: cancelling the taking off of a part cancels the putting on of the one that was
     * to take its place. Nothing is paid or given back, except for the price of an upgrade or a merge that has not started. `cancelled` lists the jobs
     * that were cancelled, the asked one first.
     */
    public function cancelJob(Request $request, int $job): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $cancelled = $this->workshop->cancel($userId, $job);

        return $this->respond($userId, 200, ['cancelled' => array_map(static fn (WorkshopJob $gone): int => $gone->id, $cancelled)]);
    }

    /**
     * Finishes the job that the mechanic is at, now, for premium coins (`speedup.coins` of the job says how many). `request_id` is one intention: sending it again
     * charges nothing and answers with `repeated: true`. Answers with the garage, `finished` (the id of the job) and `spent_coins`.
     */
    public function finishJob(Request $request, int $job): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH]]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $done = $this->workshop->finish($userId, $job, $data['request_id']);

        return $this->respond($userId, 200, ['repeated' => $done === null, 'finished' => $done === null ? null : $done['job']->id, 'spent_coins' => $done['coins'] ?? 0]);
    }

    /**
     * Buys the next level of an item of the workshop of the city the car is in (`item`: tire_machine, lift, hoist, toolkit, bays or mechanics), for cash.
     * `request_id` makes a repeated request harmless (it buys nothing twice, and `repeated` says so).
     */
    public function buyEquipment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item' => ['required', 'string', Rule::in([...WorkshopGear::ITEMS, WorkshopGear::BAYS, WorkshopGear::MECHANICS])],
            'request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $gear = $this->workshop->buyGear($userId, $data['item'], $data['request_id']);

        return $this->respond($userId, $gear === null ? 200 : 201, ['repeated' => $gear === null]);
    }

    /** @param  array<string, mixed>  $extra  more keys of the top level of the answer */
    private function respond(int $userId, int $status = 200, array $extra = []): JsonResponse
    {
        $car = $this->garage->carOf($userId);
        $run = $car === null ? null : $this->garage->runOf($car);
        $city = $run === null ? $this->rules->homeRegion() : $this->garage->cityOf($run);
        // What the workshop of this city has bought: every time and every price of the answer is the one of this workshop.
        $gear = $this->equipment->gearOf($userId, $city);

        $inventory = Part::query()->where('user_id', $userId)->whereNull('car_id')->orderBy('id')->get();
        $pending = $this->workshop->pendingJobs($userId);
        $busy = collect($pending)->flatMap(static fn (WorkshopJob $job): array => [$job->part_id, $job->merge_part_id])->filter()->values()->all();
        $quotes = $this->merges->quotes($userId, $inventory, $busy, $gear);
        // What "put on" would cost and take for each part of the inventory (the fee of the vulcanizer, the taking off of the part that sits there), with this workshop.
        $mounts = $car === null ? [] : $this->workshop->mountQuotes($car, $inventory, collect($pending), $gear);

        return response()->json([
            'data' => [
                'car' => $car === null ? null : $this->carData($car, $run, $gear),
                // What is in the inventory, and for each part what merging it would do (null when it cannot be merged now).
                'inventory' => $inventory->map(fn (Part $part): array => [...PartResource::make($part)->resolve(), 'merge' => $quotes[$part->id] ?? null, 'repair_next' => $this->workshop->repairQuote($part, $gear), 'mount_next' => $mounts[$part->id] ?? null])->all(),
                // The last results of merging: they are drawn when the mechanic is done, so the driver may be told of them later.
                'merges' => $this->merges->recent($userId),
                'workshop' => [
                    'queue_max' => $this->rules->workshopMaxQueue($gear->workers()),
                    'mechanic' => $this->rules->mechanic(),
                    // The equipment, the bays and the mechanics of this workshop: the level of each, what the next costs and what it takes off the time of the jobs.
                    'gear' => $this->equipment->summary($gear),
                    'city' => ['slug' => $city, 'name' => $this->rules->cityName($city)],
                    'jobs' => WorkshopJobResource::collection($this->workshop->pendingJobs($userId, $city))->resolve(),
                ],
            ],
            // What the driver has to spend, as of this answer (what a job or a purchase has just cost is already taken off).
            'wallet' => ['cash_cents' => $this->ledger->balance($userId), 'xp' => $this->ledger->xp($userId)],
            'premium_coins' => $this->ledger->premiumBalance($userId),
            'now_ms' => RunService::nowMs(),
            ...$extra,
        ], $status)->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function carData(Car $car, ?RaceRun $run, WorkshopGear $gear): array
    {
        $vehicle = $this->catalog->vehicle($car->vehicle_slug);
        $mounted = $car->parts()->whereNotNull('mounted_slot')->get()->keyBy('mounted_slot');
        $slugs = $mounted->map(static fn (Part $part): array => ['slug' => $part->part_slug, 'rarity' => (int) $part->rarity, 'upgrade' => (int) $part->upgrade_level, 'wear' => (int) $part->wear_ppb, 'affixes' => $part->affixes ?? []])->all();
        // The wear of its parts and the service of the car: what the odometer says, how far the service is, what the overdue takes off, how often it breaks down.
        $plan = $this->inputs->wearPlan($car->vehicle_slug, $run?->track_slug ?? $this->rules->tracksOf($this->rules->homeRegion())[0], $slugs, $car->odometer());

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
                'image_url' => $vehicle !== null ? ContentImages::urlOf($vehicle) : null,
                // The engine that is in the car: the engine part on it, else the factory engine of the body, and what it makes (no booster counted).
                'engine' => $vehicle !== null ? $this->engineLabel($this->catalog->part($mounted->get('engine')?->part_slug ?? '')['engine'] ?? $vehicle['engine']) : null,
                'power_kw' => $vehicle !== null ? (int) round($this->inputs->enginePowerKw($car->vehicle_slug, $slugs)) : null,
                'drivetrain' => $vehicle['drivetrain'] ?? null,
            ],
            'stats' => $this->rounded($this->inputs->stats($car->vehicle_slug, $slugs, $plan->servicePenaltyPpm())->stats),
            'mileage_km' => intdiv((int) $car->mileage_m, 1000),
            // The condition of the car as a whole, for the screen of its technical state: the mean of the parts that are on it (a car with none is as good as new).
            'parts_condition_percent' => $mounted->isEmpty() ? 100 : (int) round($mounted->avg(static fn (Part $part): int => $part->conditionPpm()) / 10_000),
            'service' => $this->serviceData($car, $plan, $gear),
            // How often it breaks down: the chance after a lap, with the condition of the car, the wear of its parts and the service counted.
            'breakdown' => ['chance_ppm' => $this->rules->breakChancePerLapPpmFor($car->conditionPpm(), $plan->meanWearPpm(), $plan->overdueSharePpm(), $plan->unserviceableParts($this->rules->unserviceablePpm()))],
            'base_stats' => $this->rounded($this->inputs->stats($car->vehicle_slug)->stats),
            // The bar of a statistic is full at its ceiling: what the best car of the whole game reaches, the same on every track.
            'ceilings' => $this->rules->statCeilings(),
            'tank_ml' => $this->inputs->tankMl($car->vehicle_slug, $slugs),
            'slots' => array_map(fn (string $slot): array => [
                'slot' => $slot,
                'part' => ($part = $mounted->get($slot)) === null ? null : [
                    ...PartResource::make($part)->resolve(),
                    // The next step of the upgrade of this part on this car: its price, its time and what stops it, so the garage can offer it.
                    'upgrade_next' => $this->workshop->upgradeQuote($part, $car, $gear),
                    // What putting the part right would cost and take, or why it cannot be (it is not worn, or it is worn out).
                    'repair_next' => $this->workshop->repairQuote($part, $gear),
                    // What taking it off costs and takes (the fee of the vulcanizer for tires, where the workshop has no tire machine).
                    'unmount_next' => $this->workshop->unmountQuote($part, $gear),
                ],
                // What the slot is to the formulas of the game (`tires`, `engine`, ...), or null for one that has no role: the client asks the role, not the key.
                'role' => $this->rules->model()->roleOf($slot),
                // The engine is the one of the body until a part replaces it.
                'built_in' => $this->rules->model()->is($slot, 'engine'),
            ], $this->rules->model()->visibleSlotKeys()),
            'lap_ms' => $run?->config['lap_ms'] ?? null,
            'run' => $run === null ? null : ['id' => $run->id, 'status' => $run->status, 'stop_reason' => $run->stop_reason],
        ];
    }

    /**
     * The name of an engine as it is shown: the layout and the litres with a decimal (R6 3.0).
     *
     * @param  array{layout: string, displacement_l: int|float}  $engine
     */
    private function engineLabel(array $engine): string
    {
        return $engine['layout'].' '.number_format((float) $engine['displacement_l'], 1, '.', '');
    }

    /**
     * The service of the car: how often it is due, how far it has driven since the last, how far it is from the next (less than nothing when it is
     * overdue), what the overdue takes off its performance, and what a service costs and takes.
     *
     * @return array<string, int>
     */
    private function serviceData(Car $car, WearPlan $plan, WorkshopGear $gear): array
    {
        $quote = $this->workshop->serviceQuote($car, $gear);
        $everyKm = intdiv($plan->serviceEveryM, 1000);
        $sinceKm = intdiv($plan->sinceServiceM(), 1000);

        return [
            'every_km' => $everyKm,
            'since_km' => $sinceKm,
            'due_in_km' => $everyKm - $sinceKm,
            'overdue_km' => max(0, $sinceKm - $everyKm),
            'penalty_percent' => (int) round($plan->servicePenaltyPpm() / 10_000),
            'cost_cents' => $quote['cost_cents'],
            'duration_ms' => $quote['duration_ms'],
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
