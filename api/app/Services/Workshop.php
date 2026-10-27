<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\Rarity;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\WorkshopJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The workshops: putting parts on a car, taking them off, upgrading them (+N) and merging them, by the mechanic of the city, one job after the other. Every city has a
 * workshop of its own with its own queue, and the car has to be in the city to be worked on.
 *
 * The times of a job are fixed when it is queued: it starts when the job before it in the queue of the city ends (or now, when the mechanic
 * is free) and lasts what the rules say for its slot and tier. Nothing runs in the background. The jobs are carried out lazily, in the order
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
     * @param  string  $kind  WorkshopJob::KIND_MOUNT, KIND_UNMOUNT, KIND_UPGRADE or KIND_MERGE
     * @return list<WorkshopJob>
     *
     * @throws RuleRefusedException
     */
    public function queue(int $userId, string $kind, int $partId, ?int $nowMs = null, ?int $withPartId = null): array
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
            $part = Part::query()->where('user_id', $userId)->lockForUpdate()->find($partId) ?? throw new RuleRefusedException('part_not_found');

            $pending = WorkshopJob::query()->where('user_id', $userId)->where('status', '!=', WorkshopJob::STATUS_DONE)->orderBy('finish_ms')->get();

            $partner = null;

            if ($kind === WorkshopJob::KIND_MERGE) {
                $partner = $withPartId === null || $withPartId === $part->id
                    ? throw new RuleRefusedException('merge_parts_differ')
                    : Part::query()->where('user_id', $userId)->lockForUpdate()->find($withPartId) ?? throw new RuleRefusedException('part_not_found');
            }

            // A part that is in a job is spoken for: so is the one that a merge is to use up.
            $busy = $pending->flatMap(static fn (WorkshopJob $job): array => [$job->part_id, $job->merge_part_id])->filter()->all();

            if (in_array($part->id, $busy, true) || ($partner !== null && in_array($partner->id, $busy, true))) {
                throw new RuleRefusedException('part_busy');
            }

            $queue = $pending->where('city', $city);

            $plan = match ($kind) {
                WorkshopJob::KIND_MOUNT => $this->planMount($car, $part, $pending->where('car_id', $car->id)),
                WorkshopJob::KIND_UPGRADE => $this->planUpgrade($car, $part),
                WorkshopJob::KIND_MERGE => $this->planMerge($part, $partner),
                default => $this->planUnmount($car, $part),
            };

            if ($queue->count() + count($plan) > $this->rules->workshopMaxQueue()) {
                throw new RuleRefusedException('queue_full');
            }

            $cursor = max($now, (int) $queue->max('finish_ms'));
            $jobs = [];
            // The first mounting of a driver is short while the tutorial teaches it, so that the journey of the step after it does not wait for the mechanic.
            $firstMountMs = $kind === WorkshopJob::KIND_MOUNT ? $this->script->firstMountMs($userId) : null;

            foreach ($plan as [$jobKind, $jobPart]) {
                $definition = $this->catalog->part($jobPart->part_slug);
                $quote = match ($jobKind) {
                    WorkshopJob::KIND_UPGRADE => $this->upgradeQuote($jobPart, $car),
                    WorkshopJob::KIND_MERGE => ['cost_cents' => $this->rules->mergeCostCents($definition['price_cents']), 'duration_ms' => $this->rules->mergeJobMs($jobPart->slot, $definition['tier'])],
                    default => null,
                };
                $duration = $quote['duration_ms'] ?? $this->rules->workshopJobMs($jobPart->slot, $definition['tier'], removal: $jobKind === WorkshopJob::KIND_UNMOUNT);

                if ($jobKind === WorkshopJob::KIND_MOUNT && $firstMountMs !== null) {
                    $duration = min($duration, $firstMountMs);
                }

                $job = WorkshopJob::query()->create([
                    'user_id' => $userId,
                    // The parts of a merge are not on the car, so it is not in the job: it does not stand while the mechanic is at it.
                    'car_id' => $jobKind === WorkshopJob::KIND_MERGE ? null : $car->id,
                    'city' => $city,
                    'part_id' => $jobPart->id,
                    'merge_part_id' => $jobKind === WorkshopJob::KIND_MERGE ? $partner?->id : null,
                    'kind' => $jobKind,
                    'cost_cents' => $quote['cost_cents'] ?? 0,
                    'upgrade_to' => $quote['to'] ?? null,
                    'status' => WorkshopJob::STATUS_QUEUED,
                    'start_ms' => $cursor,
                    'finish_ms' => $cursor + $duration,
                ]);

                // Paid when queued, under the lock of the wallet we hold: not enough cash undoes the job with the transaction.
                if ($job->cost_cents > 0) {
                    $this->ledger->post($userId, -$job->cost_cents, $jobKind, "{$jobKind}:{$job->id}");
                }

                $jobs[] = $job;

                $cursor += $duration;
            }

            // A job that can start now does: the car goes into the workshop right away.
            $this->advanceLocked($userId, $runs, $now);

            return array_map(static fn (WorkshopJob $job): WorkshopJob => $job->refresh(), $jobs);
        });
    }

    /**
     * Cancels a job that waits for the mechanic or that the mechanic is at, and the jobs that cannot happen without it: the
     * part that was to take the place of the one whose taking off is cancelled. A job costs only time, so nothing is given back,
     * except for an upgrade or a merge that has not started, which is paid back whole (one at work loses its price and its work);
     * the work done on the job at work is lost, and its part stays where it was. The jobs that
     * wait in the queue of that city move up, one after the other, from the end of the job still at work, or from now when none is.
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
            $this->moveUp($remaining, $now);

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

        return [[WorkshopJob::KIND_UNMOUNT, $part]];
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
    private function planUpgrade(Car $car, Part $part): array
    {
        if (! $part->isMounted() || $part->car_id !== $car->id) {
            throw new RuleRefusedException('part_not_mounted');
        }

        $quote = $this->upgradeQuote($part, $car);

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
     * @return array{to: int|null, cost_cents: int|null, duration_ms: int|null, locked: array{reason: string, required?: int, current?: int}|null}
     */
    public function upgradeQuote(Part $part, Car $car): array
    {
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
            'cost_cents' => $this->rules->upgradeCostCents($price, $level),
            'duration_ms' => $this->rules->upgradeJobMs($level),
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
     * Puts the jobs that wait one after the other from the end of the job at work, or from now when none is: each lasts
     * what it did before, so only its times move.
     *
     * @param  Collection<int, WorkshopJob>  $remaining  in the order they are carried out
     */
    private function moveUp(Collection $remaining, int $now): void
    {
        $cursor = $now;

        foreach ($remaining as $job) {
            if ($job->status === WorkshopJob::STATUS_WORKING) {
                $cursor = $job->finish_ms;

                continue;
            }

            $duration = $job->finish_ms - $job->start_ms;
            $job->forceFill(['start_ms' => $cursor, 'finish_ms' => $cursor + $duration])->save();
            $cursor += $duration;
        }
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

        $this->runs->leaveWorkshop($run, $this->inputs->make($run->car_slug, $run->track_slug, $car->mountedParts(), $car->conditionPpm()), $now);
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

        foreach ($jobs as $index => $job) {
            // A merge has no car: nothing stands for it.
            $run = $job->car_id === null ? null : $runs->get($job->car_id);

            if ($job->status === WorkshopJob::STATUS_QUEUED) {
                if ($job->start_ms > $now) {
                    continue;
                }

                // The car goes into the workshop when the mechanic starts.
                if ($run !== null) {
                    $this->runs->enterWorkshop($run, $job->start_ms);
                }

                $job->forceFill(['status' => WorkshopJob::STATUS_WORKING])->save();
            }

            if ($job->finish_ms > $now) {
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
                    $this->inputs->make($run->car_slug, $run->track_slug, $car->mountedParts(), $car->conditionPpm()),
                    $job->finish_ms,
                );
            }
        }
    }

    /** The part goes onto the car, or comes off it, or has its next level of upgrade, or the draw of the merge is made. */
    private function apply(WorkshopJob $job): void
    {
        if ($job->kind === WorkshopJob::KIND_MERGE) {
            $this->merges->resolve($job);

            return;
        }

        $part = Part::query()->lockForUpdate()->findOrFail($job->part_id);

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
