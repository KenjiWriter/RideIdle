<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\Rarity;
use App\Domain\Workshop\WorkshopGear;
use App\Domain\Workshop\WorkshopSchedule;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\WorkshopJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The workshops: putting parts on a car, taking them off, upgrading them (+N) and merging them, by the mechanics of the city. Every city has a
 * workshop of its own with its own queue and its own equipment (`WorkshopEquipment`), and the car has to be in the city to be worked on.
 *
 * The times of a job are fixed when it is queued: it starts at the first moment at which a mechanic is free for the whole of it (as many work at once as the
 * workshop has bays and mechanics for), and not before the job before it on the same car ends, and it lasts what the rules say for its slot and tier with the
 * equipment the workshop has then (`WorkshopSchedule`). Nothing runs in the background. The jobs are carried out lazily, in the order
 * of their times, when the driver comes back (`advance`), the way a run is settled:
 *
 * - when a job starts, its car goes into the workshop: what it earned until then is settled and then it stands;
 * - when a job ends, the part is on the car or off it;
 * - when the last job of a row on a car ends, the car drives again from that moment with the configuration of the parts it has now.
 *
 * Everything runs under the lock of the wallet and then the runs, like the settlement.
 */
final class Workshop
{
    public function __construct(
        private readonly RunService $runs,
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly Catalog $catalog,
        private readonly RunInputsFactory $inputs,
        private readonly Garage $garage,
        private readonly Travel $travel,
        private readonly TutorialScript $script,
        private readonly PartMerges $merges,
        private readonly WorkshopEquipment $equipment,
        private readonly PartRequirements $requirements,
    ) {}

    /**
     * Carries out the jobs that are due. Safe to call at any time and any number of times; it is a cheap read when the
     * driver has no job waiting. Whoever settles a run calls it first, so the run is never settled past the start of a job.
     */
    public function advance(int $userId, ?int $nowMs = null): void
    {
        // Everything that is lazy about a car is brought up to date from here: an arrival that is due is carried out too, so that a
        // car that has just come to a city is there before anything settles it or queues a job on it.
        $this->travel->advance($userId, $nowMs);

        if (! $this->hasPendingJobs($userId)) {
            return;
        }

        $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId): void {
            $this->advanceLocked($userId, $runs, $now);
        });
    }

    /**
     * Queues a job on the car the driver plays, in the workshop of the city it is in. Putting a part on a slot that has one takes the old
     * part off first, as a job of its own, so that a replacement is two jobs and uses two places of the queue. Returns the jobs that were
     * made, in order.
     *
     * An upgrade is a job on a part that sits on the car: it is paid when it is queued (the price of the step, see `Rules::upgradeCostCents`),
     * the car stands while the mechanic is at it, and the part has the next level when it is done.
     *
     * A merge is a job on two parts of the inventory (`$withPartId` is the second one, which is used up): it is paid when it is queued, waits for the
     * mechanic like the rest, and the car does not stand for it, because the parts are not on it. Both parts are reserved by the job.
     *
     * A repair puts a worn part right (the price and the time follow from its condition): a part on the car makes the car stand, a part of the inventory does
     * not. A service is a job on the car and no part (`$partId` is null): the car stands, and the interval of its service starts again.
     *
     * @param  string  $kind  WorkshopJob::KIND_MOUNT, KIND_UNMOUNT, KIND_UPGRADE, KIND_MERGE, KIND_REPAIR or KIND_SERVICE
     * @return list<WorkshopJob>
     *
     * @throws RuleRefusedException
     */
    public function queue(int $userId, string $kind, ?int $partId, ?int $nowMs = null, ?int $withPartId = null): array
    {
        return $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId, $kind, $partId, $withPartId): array {
            // What is due comes first, so the parts and the cars are as they are now.
            $this->advanceLocked($userId, $runs, $now);

            $car = $this->garage->carOf($userId) ?? throw new RuleRefusedException('no_car');
            $run = $runs->get($car->id);

            if ($run?->stop_reason === RaceRun::STOP_TRAVEL) {
                throw new RuleRefusedException('car_travelling');
            }

            $city = $run === null ? $this->rules->homeRegion() : $this->garage->cityOf($run);
            $gear = $this->equipment->gearOf($userId, $city);
            // The service is a job on the car, and no part.
            $part = $kind === WorkshopJob::KIND_SERVICE ? null : Part::query()->where('user_id', $userId)->lockForUpdate()->find($partId ?? 0) ?? throw new RuleRefusedException('part_not_found');

            $pending = WorkshopJob::query()->where('user_id', $userId)->where('status', '!=', WorkshopJob::STATUS_DONE)->orderBy('finish_ms')->get();

            $partner = null;

            if ($kind === WorkshopJob::KIND_MERGE) {
                $partner = $withPartId === null || $withPartId === $part?->id
                    ? throw new RuleRefusedException('merge_parts_differ')
                    : Part::query()->where('user_id', $userId)->lockForUpdate()->find($withPartId) ?? throw new RuleRefusedException('part_not_found');
            }

            // A part that is in a job is spoken for: so is the one that a merge is to use up.
            $busy = $pending->flatMap(static fn (WorkshopJob $job): array => [$job->part_id, $job->merge_part_id])->filter()->all();

            if (($part !== null && in_array($part->id, $busy, true)) || ($partner !== null && in_array($partner->id, $busy, true))) {
                throw new RuleRefusedException('part_busy');
            }

            $queue = $pending->where('city', $city);

            $plan = match ($kind) {
                WorkshopJob::KIND_MOUNT => $this->planMount($car, $part, $pending->where('car_id', $car->id)),
                WorkshopJob::KIND_UPGRADE => $this->planUpgrade($car, $part, $gear),
                WorkshopJob::KIND_MERGE => $this->planMerge($part, $partner),
                WorkshopJob::KIND_REPAIR => $this->planRepair($car, $part, $gear),
                WorkshopJob::KIND_SERVICE => [[WorkshopJob::KIND_SERVICE, null]],
                default => $this->planUnmount($car, $part),
            };

            if ($queue->count() + count($plan) > $this->rules->workshopMaxQueue($gear->workers())) {
                throw new RuleRefusedException('queue_full');
            }

            // The jobs of the city keep their times; a car is busy until its last job ends, wherever the jobs of it stand.
            $placed = $queue->map(static fn (WorkshopJob $job): array => ['start_ms' => $job->start_ms, 'finish_ms' => $job->finish_ms])->values()->all();
            $carFreeAt = $pending->whereNotNull('car_id')->groupBy('car_id')->map(static fn (Collection $jobs): int => (int) $jobs->max('finish_ms'))->all();
            $jobs = [];
            // The first mounting of a driver is short while the tutorial teaches it, so that the journey of the step after it does not wait for the mechanic, and
            // the vulcanizer does not ask a fee of it.
            $firstMountMs = $kind === WorkshopJob::KIND_MOUNT ? $this->script->firstMountMs($userId) : null;
            // The step that waits may make jobs of a kind short and, up to a sum, free (and a merge sure).
            $arranged = $this->script->arrangements($userId);

            foreach ($plan as [$jobKind, $jobPart]) {
                $definition = $jobPart === null ? null : $this->catalog->part($jobPart->part_slug);
                $quote = match ($jobKind) {
                    WorkshopJob::KIND_UPGRADE => $this->upgradeQuote($jobPart, $car, $gear, $arranged),
                    WorkshopJob::KIND_MERGE => $this->script->arrange(['cost_cents' => $this->rules->mergeCostCents($definition['price_cents']), 'duration_ms' => $this->rules->mergeJobMs($jobPart->slot, $definition['tier'], $gear)], $arranged[WorkshopJob::KIND_MERGE] ?? null),
                    WorkshopJob::KIND_REPAIR => $this->repairQuote($jobPart, $gear),
                    WorkshopJob::KIND_SERVICE => $this->serviceQuote($car, $gear, $arranged),
                    default => $this->slotJobQuote($jobKind, $jobPart, $gear, $firstMountMs, $arranged[$jobKind] ?? null),
                };
                $duration = $quote['duration_ms'];

                // The parts of a merge are not on the car, nor is a part of the inventory that is repaired, so the car is not in the job: it does not stand
                // while the mechanic is at it.
                $carId = $jobKind === WorkshopJob::KIND_MERGE || ($jobKind === WorkshopJob::KIND_REPAIR && ! $jobPart->isMounted()) ? null : $car->id;
                $start = WorkshopSchedule::startFor($placed, $gear->workers(), max($now, $carId === null ? 0 : $carFreeAt[$carId] ?? 0), $duration);
                $placed[] = ['start_ms' => $start, 'finish_ms' => $start + $duration];

                if ($carId !== null) {
                    $carFreeAt[$carId] = $start + $duration;
                }

                $job = WorkshopJob::query()->create([
                    'user_id' => $userId,
                    'car_id' => $carId,
                    'city' => $city,
                    'part_id' => $jobPart?->id,
                    'merge_part_id' => $jobKind === WorkshopJob::KIND_MERGE ? $partner?->id : null,
                    'kind' => $jobKind,
                    'cost_cents' => $quote['cost_cents'] ?? 0,
                    'upgrade_to' => $quote['to'] ?? null,
                    'status' => WorkshopJob::STATUS_QUEUED,
                    'scripted' => $jobKind === WorkshopJob::KIND_MERGE && isset($arranged[WorkshopJob::KIND_MERGE]),
                    'start_ms' => $start,
                    'finish_ms' => $start + $duration,
                ]);

                // Paid when queued, under the lock of the wallet we hold: not enough cash undoes the job with the transaction.
                if ($job->cost_cents > 0) {
                    $this->ledger->post($userId, -$job->cost_cents, $jobKind, "{$jobKind}:{$job->id}");
                }

                $jobs[] = $job;
            }

            // A job that can start now does: the car goes into the workshop right away.
            $this->advanceLocked($userId, $runs, $now);

            return array_map(static fn (WorkshopJob $job): WorkshopJob => $job->refresh(), $jobs);
        });
    }

    /**
     * Buys the next level of an item of the workshop in the city the car of the driver is in, or one more bay or mechanic, for cash: `$item` is
     * `tire_machine`, `lift`, `hoist`, `toolkit`, `bays` or `mechanics`. It is there at once, but only the jobs queued from then on are quicker: the jobs queued are
     * not changed, except that more mechanics at work take the jobs that wait earlier. `$requestId` makes a repeated request harmless: a purchase that was paid already
     * buys nothing, and the answer is null.
     *
     * @return WorkshopGear|null what the workshop has now, or null for a repeated request
     *
     * @throws RuleRefusedException `equipment_not_found`, `equipment_max`, `car_travelling`
     */
    public function buyGear(int $userId, string $item, string $requestId, ?int $nowMs = null): ?WorkshopGear
    {
        return $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId, $item, $requestId): ?WorkshopGear {
            $key = "workshop-gear:{$userId}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $key)->exists()) {
                return null;
            }

            if (! in_array($item, [...WorkshopGear::ITEMS, WorkshopGear::BAYS, WorkshopGear::MECHANICS], true)) {
                throw new RuleRefusedException('equipment_not_found');
            }

            // What is due comes first, so the queue is as it is now.
            $this->advanceLocked($userId, $runs, $now);

            $car = $this->garage->carOf($userId);
            $run = $car === null ? null : $runs->get($car->id);

            if ($run?->stop_reason === RaceRun::STOP_TRAVEL) {
                throw new RuleRefusedException('car_travelling');
            }

            $city = $run === null ? $this->rules->homeRegion() : $this->garage->cityOf($run);
            $before = $this->equipment->gearOf($userId, $city);
            $price = $this->rules->gearNextPriceCents($item, $before->level($item)) ?? throw new RuleRefusedException('equipment_max');

            $this->ledger->post($userId, -$price, 'workshop_gear', $key);
            $gear = $this->equipment->raise($userId, $city, $item);

            // A mechanic more takes the jobs that wait earlier.
            if ($gear->workers() > $before->workers()) {
                $this->moveUp(
                    WorkshopJob::query()->where('user_id', $userId)->where('city', $city)->where('status', '!=', WorkshopJob::STATUS_DONE)->orderBy('start_ms')->orderBy('id')->lockForUpdate()->get()->values(),
                    $now,
                    $gear->workers(),
                );
                $this->advanceLocked($userId, $runs, $now);
            }

            return $gear;
        });
    }

    /**
     * Cancels a job that waits for the mechanic or that the mechanic is at, and the jobs that cannot happen without it: the
     * part that was to take the place of the one whose taking off is cancelled. A job costs only time, so nothing is given back,
     * except for a job that has not started and was paid (an upgrade, a merge, a repair, a service, or the fee of the vulcanizer), which is paid back whole
     * (one at work loses its price and its work); the work done on the job at work is lost, and its part stays where it was. The jobs that
     * wait in the queue of that city move up to the first moment a mechanic is free for them, or to now when one is.
     * A car that has no job at work after that drives again from now.
     *
     * @return list<WorkshopJob> the jobs that were cancelled, the one that was asked for first
     *
     * @throws RuleRefusedException the job is not the driver's, or is done already
     */
    public function cancel(int $userId, int $jobId, ?int $nowMs = null): array
    {
        return $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId, $jobId): array {
            // What is due comes first: a job that was done in the meantime cannot be taken back.
            $this->advanceLocked($userId, $runs, $now);

            $job = WorkshopJob::query()->where('user_id', $userId)->with('part')->find($jobId) ?? throw new RuleRefusedException('job_not_found');

            if ($job->status === WorkshopJob::STATUS_DONE) {
                throw new RuleRefusedException('job_done');
            }

            $pending = WorkshopJob::query()
                ->where('user_id', $userId)
                ->where('city', $job->city)
                ->where('status', '!=', WorkshopJob::STATUS_DONE)
                ->orderBy('start_ms')
                ->orderBy('id')
                ->with('part')
                ->lockForUpdate()
                ->get()
                ->values();

            $cancelled = $pending->filter(fn (WorkshopJob $other): bool => $other->id === $job->id || $this->cannotHappenWithout($other, $job))->values();
            WorkshopJob::query()->whereIn('id', $cancelled->modelKeys())->delete();

            // What a job of upgrading or merging cost is given back while the mechanic has not started on it; the work and the price of one at work are lost.
            foreach ($cancelled->where('status', WorkshopJob::STATUS_QUEUED)->where('cost_cents', '>', 0) as $unpaid) {
                $this->ledger->post($userId, $unpaid->cost_cents, "{$unpaid->kind}_refund", "{$unpaid->kind}-refund:{$unpaid->id}");
            }

            $remaining = $pending->reject(fn (WorkshopJob $other): bool => $cancelled->contains('id', $other->id))->values();
            $this->moveUp($remaining, $now, $this->equipment->gearOf($userId, $job->city)->workers());

            // The first of the jobs that wait starts at once when the mechanic has just been freed.
            $this->advanceLocked($userId, $runs, $now);

            foreach ($cancelled->pluck('car_id')->filter()->unique() as $carId) {
                $this->leaveIfIdle((int) $carId, $runs->get($carId), $now);
            }

            return [$job, ...$cancelled->reject(fn (WorkshopJob $other): bool => $other->id === $job->id)->values()->all()];
        });
    }

    /**
     * The jobs that are not done, in the order they are carried out: all of the driver's, or only those of one city.
     *
     * @return list<WorkshopJob>
     */
    public function pendingJobs(int $userId, ?string $city = null): array
    {
        return WorkshopJob::query()
            ->where('user_id', $userId)
            ->when($city !== null, fn ($query) => $query->where('city', $city))
            ->where('status', '!=', WorkshopJob::STATUS_DONE)
            ->orderBy('start_ms')
            ->orderBy('id')
            ->with(['part', 'mergePart'])
            ->get()
            ->all();
    }

    /**
     * What a job of putting a part on or taking it off costs and takes: nothing but time, and the fee of the vulcanizer where the workshop has no tire machine.
     * While the tutorial arranges the first mounting of the driver (`$firstMountMs`, see `TutorialScript`) the putting on is short and the fee is not asked.
     *
     * @param  string  $kind  WorkshopJob::KIND_MOUNT or KIND_UNMOUNT
     * @param  array{ms: int, covers_cents: int}|null  $arrangement  what the step that waits makes of a job of this kind (see `TutorialScript::arrangements`)
     * @return array{cost_cents: int, duration_ms: int}
     */
    private function slotJobQuote(string $kind, Part $part, WorkshopGear $gear, ?int $firstMountMs, ?array $arrangement = null): array
    {
        $tier = $this->catalog->part($part->part_slug)['tier'];
        $duration = $this->rules->workshopJobMs($part->slot, $tier, removal: $kind === WorkshopJob::KIND_UNMOUNT, gear: $gear);

        return $this->script->arrange([
            'cost_cents' => $firstMountMs === null ? $this->rules->gearFeeCents($gear, $part->slot) : 0,
            'duration_ms' => $kind === WorkshopJob::KIND_MOUNT && $firstMountMs !== null ? min($duration, $firstMountMs) : $duration,
        ], $arrangement);
    }

    /**
     * What pressing "put on" costs and takes for every part of the inventory, by the id of the part: what the jobs that it queues come to, which is the putting
     * on and, when the slot has a part that is not on its way off already, the taking off of that one first. Tires of a workshop without a tire machine cost the
     * fee for each. A part with no definition has no quote.
     *
     * @param  Collection<int, Part>  $inventory  the parts of the driver that are on no car
     * @param  Collection<int, WorkshopJob>  $pending  the jobs of the driver that are not done
     * @return array<int, array{cost_cents: int, duration_ms: int, locked: array{reason: string, required?: int, current?: int}|null}|null> `locked` is what the car lacks
     *                                                                                                                                      for the part, or null
     */
    public function mountQuotes(Car $car, Collection $inventory, Collection $pending, WorkshopGear $gear): array
    {
        $firstMountMs = $this->script->firstMountMs((int) $car->user_id);
        $arranged = $this->script->arrangements((int) $car->user_id);
        $occupants = Part::query()->where('car_id', $car->id)->whereNotNull('mounted_slot')->get()->keyBy('mounted_slot');
        $leaving = $pending->where('kind', WorkshopJob::KIND_UNMOUNT)->where('car_id', $car->id)->pluck('part_id');
        $state = $this->requirements->stateOf($car);
        $quotes = [];

        foreach ($inventory as $part) {
            $definition = $this->catalog->part($part->part_slug);

            if ($definition === null) {
                $quotes[$part->id] = null;

                continue;
            }

            $quote = $this->slotJobQuote(WorkshopJob::KIND_MOUNT, $part, $gear, $firstMountMs, $arranged[WorkshopJob::KIND_MOUNT] ?? null);
            $occupant = $occupants->get($part->slot);

            if ($occupant !== null && ! $leaving->contains($occupant->id) && $this->catalog->part($occupant->part_slug) !== null) {
                $removal = $this->slotJobQuote(WorkshopJob::KIND_UNMOUNT, $occupant, $gear, $firstMountMs, $arranged[WorkshopJob::KIND_UNMOUNT] ?? null);
                $quote = ['cost_cents' => $quote['cost_cents'] + $removal['cost_cents'], 'duration_ms' => $quote['duration_ms'] + $removal['duration_ms']];
            }

            $quotes[$part->id] = [...$quote, 'locked' => $this->requirements->lack($definition, $car, $state)];
        }

        return $quotes;
    }

    /**
     * What taking a part off the car costs and takes. The engine says in `locked` what stops it from coming out: the booster on the car needs more cylinders than
     * the factory engine has.
     *
     * @return array{cost_cents: int, duration_ms: int, locked?: array{reason: string, required: int, current: int}|null}|null null for a part with no definition
     */
    public function unmountQuote(Part $part, WorkshopGear $gear): ?array
    {
        if ($this->catalog->part($part->part_slug) === null) {
            return null;
        }

        $quote = $this->slotJobQuote(WorkshopJob::KIND_UNMOUNT, $part, $gear, null, $this->script->arrangements((int) $part->user_id)[WorkshopJob::KIND_UNMOUNT] ?? null);
        $car = $part->slot === 'engine' && $part->car_id !== null ? Car::query()->find($part->car_id) : null;

        return $car === null ? $quote : [...$quote, 'locked' => $this->requirements->lackWithoutEngine($car)];
    }

    /**
     * What it takes to put the part on the car: a job to take off the part that sits in the slot (unless that is queued
     * already), and then the job that puts this one on.
     *
     * @param  Collection<int, WorkshopJob>  $pending  the jobs of this car that are not done
     * @return list<array{0: string, 1: Part}>
     *
     * @throws RuleRefusedException
     */
    private function planMount(Car $car, Part $part, Collection $pending): array
    {
        if ($part->isMounted()) {
            throw new RuleRefusedException('part_mounted');
        }

        $definition = $this->catalog->part($part->part_slug) ?? throw new RuleRefusedException('part_not_found');
        $required = $this->rules->requiredCarLevel($definition['tier']);

        if ($car->level < $required) {
            throw new RuleRefusedException('car_level_required', ['required' => $required, 'current' => $car->level]);
        }

        // An engine with too few cylinders cannot take the turbo, an intercooler has nothing to cool without a booster, and an engine has to fit the bay of the body.
        $lack = $this->requirements->lack($definition, $car);

        if ($lack !== null) {
            throw new RuleRefusedException($this->lackCode($lack['reason']), $this->lackParams($lack));
        }

        // Another part of this slot is already on its way onto the car.
        $incoming = $pending->where('kind', WorkshopJob::KIND_MOUNT)->pluck('part_id');

        if ($incoming->isNotEmpty() && Part::query()->whereIn('id', $incoming)->where('slot', $part->slot)->exists()) {
            throw new RuleRefusedException('slot_busy');
        }

        $plan = [];
        $occupant = Part::query()->where('car_id', $car->id)->where('mounted_slot', $part->slot)->first();

        if ($occupant !== null && ! $pending->contains(fn (WorkshopJob $job): bool => $job->part_id === $occupant->id && $job->kind === WorkshopJob::KIND_UNMOUNT)) {
            $plan[] = [WorkshopJob::KIND_UNMOUNT, $occupant];
        }

        $plan[] = [WorkshopJob::KIND_MOUNT, $part];

        return $plan;
    }

    /**
     * @return list<array{0: string, 1: Part}>
     *
     * @throws RuleRefusedException
     */
    private function planUnmount(Car $car, Part $part): array
    {
        if (! $part->isMounted() || $part->car_id !== $car->id) {
            throw new RuleRefusedException('part_not_mounted');
        }

        // The booster on the car may need more cylinders than the factory engine that the car goes back to has.
        $lack = $part->slot === 'engine' ? $this->requirements->lackWithoutEngine($car) : null;

        if ($lack !== null) {
            throw new RuleRefusedException($this->lackCode($lack['reason']), $this->lackParams($lack));
        }

        return [[WorkshopJob::KIND_UNMOUNT, $part]];
    }

    /**
     * What a refusal says about what the car lacks, for the message: the numbers and names (a list of layouts is made into words).
     *
     * @param  array<string, mixed>  $lack
     * @return array<string, int|float|string>
     */
    private function lackParams(array $lack): array
    {
        return array_map(static fn (mixed $value): int|float|string => is_array($value) ? implode(', ', $value) : $value, array_diff_key($lack, ['reason' => 1]));
    }

    /** The code of the refusal for what a car lacks (`PartRequirements::lack`). */
    private function lackCode(string $reason): string
    {
        return match ($reason) {
            'cylinders' => 'part_requires_cylinders',
            'bay_layout' => 'engine_bay_layout',
            'bay_size' => 'engine_bay_size',
            'booster_cylinders' => 'engine_booster_cylinders',
            default => 'part_requires_booster',
        };
    }

    /**
     * The repair of a part that is worn: on the car the workshop works on, or in the inventory.
     *
     * @return list<array{0: string, 1: Part}>
     *
     * @throws RuleRefusedException
     */
    private function planRepair(Car $car, Part $part, WorkshopGear $gear): array
    {
        if ($part->isMounted() && $part->car_id !== $car->id) {
            throw new RuleRefusedException('part_not_mounted');
        }

        $locked = $this->repairQuote($part, $gear)['locked'];

        if ($locked !== null) {
            throw new RuleRefusedException(match ($locked['reason']) {
                'fine' => 'part_not_worn',
                'worn_out' => 'part_worn_out',
                default => 'repair_unavailable',
            });
        }

        return [[WorkshopJob::KIND_REPAIR, $part]];
    }

    /**
     * What putting a worn part right costs and takes, and what stops it: the part is not worn, it is worn out (a part at 0% has to be replaced), or it
     * has no price.
     *
     * @return array{cost_cents: int|null, duration_ms: int|null, locked: array{reason: string}|null}
     */
    public function repairQuote(Part $part, ?WorkshopGear $gear = null): array
    {
        $gear ??= $this->gearOfDriver((int) $part->user_id);
        $definition = $this->catalog->part($part->part_slug);

        if (($definition['price_cents'] ?? null) === null) {
            return ['cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'unavailable']];
        }

        if ((int) $part->wear_ppb <= 0) {
            return ['cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'fine']];
        }

        if ($part->conditionPpm() <= 0) {
            return ['cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'worn_out']];
        }

        return [
            // The fee of the vulcanizer is part of the price: a workshop without a tire machine has the tires put right outside.
            'cost_cents' => $this->rules->partRepairCostCents($definition['price_cents'], $part->conditionPpm()) + $this->rules->gearFeeCents($gear, $part->slot),
            'duration_ms' => $this->rules->partRepairMs($part->slot, $definition['tier'], $part->conditionPpm(), $gear),
            'locked' => null,
        ];
    }

    /**
     * What a service of the car costs and takes: 4% of what the car earns in the kilometres between two services (a four wheel drive pays a fifth more),
     * and a quarter of an hour of the mechanic (less with the toolkit of the workshop). A car with no run earns nothing, so a service costs the least it can.
     *
     * @param  array<string, array{ms: int, covers_cents: int}>|null  $arranged  what the step that waits makes of the jobs (`TutorialScript::arrangements`), or null to ask
     * @return array{cost_cents: int, duration_ms: int}
     */
    public function serviceQuote(Car $car, ?WorkshopGear $gear = null, ?array $arranged = null): array
    {
        $gear ??= $this->gearOfCar($car);
        $arranged ??= $this->script->arrangements((int) $car->user_id);
        $run = $this->garage->runOf($car);
        $lapCash = (int) ($run?->config['lap_cash_cents']['dry'] ?? 0);
        $lengthM = $run === null ? $this->rules->trackLengthM($this->rules->tracksOf($this->rules->homeRegion())[0]) : $this->rules->trackLengthM($run->track_slug);

        $drivetrain = $this->catalog->vehicle($car->vehicle_slug)['drivetrain'] ?? null;

        return $this->script->arrange(['cost_cents' => $this->rules->serviceCostCents($lapCash, $lengthM, $drivetrain), 'duration_ms' => $this->rules->serviceJobMs($gear)], $arranged[WorkshopJob::KIND_SERVICE] ?? null);
    }

    /**
     * Two parts of the inventory that are made into one: the same slot, tier and rarity, neither on a car, and not the highest rarity.
     *
     * @return list<array{0: string, 1: Part}>
     *
     * @throws RuleRefusedException
     */
    private function planMerge(Part $part, Part $partner): array
    {
        if ($part->isMounted() || $partner->isMounted()) {
            throw new RuleRefusedException('part_mounted');
        }

        $definition = $this->catalog->part($part->part_slug);
        $otherDefinition = $this->catalog->part($partner->part_slug);

        if ($definition === null || $otherDefinition === null || ($definition['price_cents'] ?? null) === null) {
            throw new RuleRefusedException('merge_unavailable');
        }

        if ($part->slot !== $partner->slot || $definition['tier'] !== $otherDefinition['tier'] || $part->rarity !== $partner->rarity) {
            throw new RuleRefusedException('merge_parts_differ');
        }

        if (Rarity::tryFrom((int) $part->rarity) === Rarity::Legendary) {
            throw new RuleRefusedException('merge_max');
        }

        return [[WorkshopJob::KIND_MERGE, $part]];
    }

    /**
     * The upgrade of a part that sits on the car: the one step to the next level, which the rarity of the part and the level of the car allow.
     *
     * @return list<array{0: string, 1: Part}>
     *
     * @throws RuleRefusedException
     */
    private function planUpgrade(Car $car, Part $part, WorkshopGear $gear): array
    {
        if (! $part->isMounted() || $part->car_id !== $car->id) {
            throw new RuleRefusedException('part_not_mounted');
        }

        $quote = $this->upgradeQuote($part, $car, $gear);

        if ($quote['locked'] !== null) {
            throw match ($quote['locked']['reason']) {
                'car_level' => new RuleRefusedException('upgrade_car_level', ['to' => $quote['to'], 'required' => $quote['locked']['required'], 'current' => $quote['locked']['current']]),
                'max' => new RuleRefusedException('upgrade_max'),
                default => new RuleRefusedException('upgrade_unavailable'),
            };
        }

        return [[WorkshopJob::KIND_UPGRADE, $part]];
    }

    /**
     * What the next step of the upgrade of a part is on this car: the level it takes the part to, the price, the time, and what stops it (the part is
     * at the most its rarity allows, or the car is not of the level that the step takes).
     *
     * @param  array<string, array{ms: int, covers_cents: int}>|null  $arranged  what the step that waits makes of the jobs (`TutorialScript::arrangements`), or null to ask
     * @return array{to: int|null, cost_cents: int|null, duration_ms: int|null, locked: array{reason: string, required?: int, current?: int}|null}
     */
    public function upgradeQuote(Part $part, Car $car, ?WorkshopGear $gear = null, ?array $arranged = null): array
    {
        $gear ??= $this->gearOfCar($car);
        $arranged ??= $this->script->arrangements((int) $part->user_id);
        $rarity = Rarity::tryFrom((int) $part->rarity) ?? Rarity::Common;
        $level = (int) $part->upgrade_level;
        $price = $this->catalog->part($part->part_slug)['price_cents'] ?? null;

        if ($price === null) {
            return ['to' => null, 'cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'unavailable']];
        }

        if ($level >= $this->rules->upgradeMax($rarity)) {
            return ['to' => null, 'cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'max']];
        }

        $to = $level + 1;

        return [
            'to' => $to,
            ...$this->script->arrange([
                // The fee of the vulcanizer is part of the price, like the one of a repair.
                'cost_cents' => $this->rules->upgradeCostCents($price, $level) + $this->rules->gearFeeCents($gear, $part->slot),
                'duration_ms' => $this->rules->upgradeJobMs($level, $this->catalog->part($part->part_slug)['tier'] ?? 1, $gear, $part->slot),
            ], $arranged[WorkshopJob::KIND_UPGRADE] ?? null),
            'locked' => $level >= $this->rules->upgradeCap($rarity, $car->level)
                ? ['reason' => 'car_level', 'required' => $this->rules->requiredCarLevelForUpgrade($to), 'current' => $car->level]
                : null,
        ];
    }

    /**
     * Whether a job is there only for the sake of another: a part is put on a slot after the part that sat there has been
     * taken off, so that job goes when the one that empties the slot does.
     */
    private function cannotHappenWithout(WorkshopJob $job, WorkshopJob $cancelled): bool
    {
        return $cancelled->kind === WorkshopJob::KIND_UNMOUNT
            && $job->kind === WorkshopJob::KIND_MOUNT
            && $job->car_id === $cancelled->car_id
            && $job->part->slot === $cancelled->part->slot
            && ($job->start_ms > $cancelled->start_ms || ($job->start_ms === $cancelled->start_ms && $job->id > $cancelled->id));
    }

    /**
     * Puts the jobs that wait where they start first now that the workshop is different (a job was cancelled, or a mechanic came): the ones at work keep their
     * times, and the others take, one after the other in the order they were in, the first moment a mechanic is free for them, and not before the job before them
     * on the same car ends. Each lasts what it did before, so only its times move.
     *
     * @param  Collection<int, WorkshopJob>  $remaining  in the order they are carried out
     * @param  int  $workers  how many mechanics work at once
     */
    private function moveUp(Collection $remaining, int $now, int $workers): void
    {
        $placed = [];
        $carFreeAt = [];

        foreach ($remaining->where('status', WorkshopJob::STATUS_WORKING) as $job) {
            $placed[] = ['start_ms' => $job->start_ms, 'finish_ms' => $job->finish_ms];

            if ($job->car_id !== null) {
                $carFreeAt[$job->car_id] = max($carFreeAt[$job->car_id] ?? 0, $job->finish_ms);
            }
        }

        foreach ($remaining->where('status', '!=', WorkshopJob::STATUS_WORKING) as $job) {
            $duration = $job->finish_ms - $job->start_ms;
            $start = WorkshopSchedule::startFor($placed, $workers, max($now, $job->car_id === null ? 0 : $carFreeAt[$job->car_id] ?? 0), $duration);

            $job->forceFill(['start_ms' => $start, 'finish_ms' => $start + $duration])->save();
            $placed[] = ['start_ms' => $start, 'finish_ms' => $start + $duration];

            if ($job->car_id !== null) {
                $carFreeAt[$job->car_id] = $start + $duration;
            }
        }
    }

    /** What the workshop has in the city the car stands in. */
    private function gearOfCar(Car $car): WorkshopGear
    {
        $run = $this->garage->runOf($car);

        return $this->equipment->gearOf((int) $car->user_id, $run === null ? $this->rules->homeRegion() : $this->garage->cityOf($run));
    }

    /** What the workshop of the car the driver plays has (the home city's when they have none). */
    private function gearOfDriver(int $userId): WorkshopGear
    {
        $car = $this->garage->carOf($userId);

        return $car === null ? $this->equipment->gearOf($userId, $this->rules->homeRegion()) : $this->gearOfCar($car);
    }

    /** A car that stands in the workshop with no job at work any more leaves it, and drives from now with the parts it has. */
    private function leaveIfIdle(int $carId, ?RaceRun $run, int $now): void
    {
        if ($run === null || $run->stop_reason !== RaceRun::STOP_WORKSHOP) {
            return;
        }

        if (WorkshopJob::query()->where('car_id', $carId)->where('status', WorkshopJob::STATUS_WORKING)->exists()) {
            return;
        }

        $car = Car::query()->findOrFail($carId);

        $this->runs->leaveWorkshop($run, $this->inputs->makeForCar($car, $run->track_slug), $now);
    }

    private function hasPendingJobs(int $userId): bool
    {
        return WorkshopJob::query()->where('user_id', $userId)->where('status', '!=', WorkshopJob::STATUS_DONE)->exists();
    }

    /**
     * The jobs that are due, in the order of their times, in every city; the locks are held (the wallet, then the runs).
     *
     * @param  Collection<int, RaceRun>  $runs  the runs of the driver, by car
     */
    private function advanceLocked(int $userId, Collection $runs, int $now): void
    {
        $jobs = WorkshopJob::query()
            ->where('user_id', $userId)
            ->where('status', '!=', WorkshopJob::STATUS_DONE)
            ->orderBy('start_ms')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->values();

        // The starts and the ends that are due, in the order of the clock whichever mechanic they belong to, so that what a job changes (a part, the draw of a
        // merge) happens in the same order whenever the driver comes back. An end comes before a start of the same moment, and a job that is due to start and
        // to end is started first.
        $events = [];

        foreach ($jobs as $index => $job) {
            if ($job->status === WorkshopJob::STATUS_QUEUED && $job->start_ms <= $now) {
                $events[] = ['at' => $job->start_ms, 'end' => false, 'id' => $job->id, 'index' => $index];
            }

            if ($job->finish_ms <= $now) {
                $events[] = ['at' => $job->finish_ms, 'end' => true, 'id' => $job->id, 'index' => $index];
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['end'] ? 0 : 1, $a['id']] <=> [$b['at'], $b['end'] ? 0 : 1, $b['id']]);

        foreach ($events as ['end' => $ends, 'index' => $index]) {
            $job = $jobs[$index];
            // A merge has no car: nothing stands for it.
            $run = $job->car_id === null ? null : $runs->get($job->car_id);

            if (! $ends) {
                // The car goes into the workshop when the mechanic starts.
                if ($run !== null) {
                    $this->runs->enterWorkshop($run, $job->start_ms);
                }

                $job->forceFill(['status' => WorkshopJob::STATUS_WORKING])->save();

                continue;
            }

            $this->apply($job);
            // The parts of a failed merge are gone, and the row of the job with them: it is only marked when it is still there.
            WorkshopJob::query()->whereKey($job->id)->update(['status' => WorkshopJob::STATUS_DONE]);
            $job->status = WorkshopJob::STATUS_DONE;

            // The car stays while its next job starts at once, and drives when there is a gap or no job.
            $next = $jobs->slice($index + 1)->first(fn (WorkshopJob $other): bool => $other->car_id === $job->car_id);
            $continues = $next !== null && $next->start_ms <= $job->finish_ms;

            if ($run !== null && ! $continues) {
                $car = Car::query()->findOrFail($job->car_id);

                $this->runs->leaveWorkshop(
                    $run,
                    $this->inputs->makeForCar($car, $run->track_slug),
                    $job->finish_ms,
                );
            }
        }
    }

    /** The part goes onto the car, or comes off it, or has its next level of upgrade, or the draw of the merge is made, or the part or the car is put right. */
    private function apply(WorkshopJob $job): void
    {
        if ($job->kind === WorkshopJob::KIND_MERGE) {
            $this->merges->resolve($job);

            return;
        }

        // The interval of the service starts again from where the car is.
        if ($job->kind === WorkshopJob::KIND_SERVICE) {
            Car::query()->whereKey($job->car_id)->update(['serviced_at_m' => DB::raw('mileage_m')]);

            return;
        }

        $part = Part::query()->lockForUpdate()->findOrFail($job->part_id);

        if ($job->kind === WorkshopJob::KIND_REPAIR) {
            $part->forceFill(['wear_ppb' => 0])->save();

            return;
        }

        if ($job->kind === WorkshopJob::KIND_UPGRADE) {
            $part->forceFill(['upgrade_level' => $job->upgrade_to])->save();

            return;
        }

        $part->forceFill($job->kind === WorkshopJob::KIND_MOUNT
            ? ['car_id' => $job->car_id, 'mounted_slot' => $part->slot]
            : ['car_id' => null, 'mounted_slot' => null])->save();
    }

    /**
     * Runs `$work` with the wallet of the driver and then all their runs locked, always in this order (the runs by id), like the settlement of
     * a run, so two requests cannot wait for each other. The clock is read after the locks.
     *
     * @template T
     *
     * @param  callable(Collection<int, RaceRun>, int): T  $work  gets the runs of the driver by car
     * @return T
     */
    private function locked(int $userId, ?int $nowMs, callable $work): mixed
    {
        // Before the transaction: creating the wallet under a lock deadlocks (see Ledger::ensureWallet).
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $nowMs, $work): mixed {
            $this->ledger->lockWallet($userId);
            $runs = RaceRun::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->get()->keyBy('car_id');

            return $work($runs, $nowMs ?? RunService::nowMs());
        }, Ledger::DEADLOCK_ATTEMPTS);
    }
}
