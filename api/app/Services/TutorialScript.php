<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\TutorialSteps;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\WorkshopJob;
use Illuminate\Support\Collection;

/**
 * What the server arranges for the first minutes of a driver, so that the steps of the tutorial come in them and cannot fail (the design asks for a
 * scripted outcome of the first steps): the first run starts with a small tank, the first car breaks down after a few laps, the first job of the mechanic is
 * short, and the first crate costs nothing. It only reads (the run, the rows of the tutorial, the crates opened), so the services that act on it can all ask it without asking each
 * other, and it stops arranging anything for a driver who skipped the tutorial or has done the step it was for.
 */
final class TutorialScript
{
    /** What the exchange calls the listing of the tutorial. */
    public const LISTING_KEY = 'tutorial';

    public function __construct(private readonly TutorialSteps $steps) {}

    /** How much fuel the tank of the first run of a driver starts with, or null for a run that is not the first. */
    public function firstRunFuelMl(int $userId): ?int
    {
        return RaceRun::withoutGlobalScopes()->where('user_id', $userId)->exists() ? null : $this->steps->script()['first_run_fuel_ml'];
    }

    /**
     * The lap after which the first car of the driver breaks down, so that the repair can be taught, or null when no breakdown is arranged: the run is
     * not the first, the lap is behind it, the repair is done, or the tutorial was skipped.
     *
     * @param  int|null  $runId  the run that is frozen, null for the run that is about to be made
     */
    public function breakdownLap(int $userId, ?int $runId, int $lapsDone): ?int
    {
        $lap = $this->steps->script()['breakdown_lap'];

        if ($lapsDone >= $lap) {
            return null;
        }

        $first = RaceRun::withoutGlobalScopes()->where('user_id', $userId)->min('id');

        if ($runId === null ? $first !== null : (int) $first !== $runId) {
            return null;
        }

        $rows = $this->rows($userId);

        if ($rows->contains('state', TutorialProgress::STATE_SKIPPED) || $rows->get('repair')?->state === TutorialProgress::STATE_DONE) {
            return null;
        }

        return $lap;
    }

    /**
     * The longest the first job of mounting of the driver takes, so that the step of the journey that comes right after it is not held up by the
     * mechanic for minutes, or null for a job that is not the first, for a driver who is not at the step of the mounting (the repair is not done
     * or the mounting is), and for a skipped tutorial.
     */
    public function firstMountMs(int $userId): ?int
    {
        $rows = $this->rows($userId);

        if ($rows->contains('state', TutorialProgress::STATE_SKIPPED)
            || $rows->get('repair')?->state !== TutorialProgress::STATE_DONE
            || $rows->get('mount')?->state === TutorialProgress::STATE_DONE) {
            return null;
        }

        return WorkshopJob::query()->where('user_id', $userId)->exists() ? null : $this->steps->script()['first_mount_ms'];
    }

    /** Whether the first crate that the driver opens is free: the step before it is done, the step of the crate is not, and no crate was opened yet. */
    public function freeFirstCrate(int $userId): bool
    {
        $rows = $this->rows($userId);

        if ($rows->contains('state', TutorialProgress::STATE_SKIPPED) || $rows->get('mount')?->state !== TutorialProgress::STATE_DONE) {
            return false;
        }

        return $rows->get('crate')?->state !== TutorialProgress::STATE_DONE && ! CrateOpening::query()->where('user_id', $userId)->exists();
    }

    /**
     * The kinds of job of the workshop that the step that waits for the driver arranges (see `arranges` in the steps), by kind: the job takes at most `ms`, and the
     * mentor pays up to `covers_cents` of its cost. A driver who is at no such step has none.
     *
     * @return array<string, array{ms: int, covers_cents: int}>
     */
    public function arrangements(int $userId): array
    {
        $script = $this->steps->script();
        $arranged = [];

        foreach (TutorialProgress::query()->where('user_id', $userId)->where('state', TutorialProgress::STATE_ACTIVE)->pluck('step_key') as $key) {
            foreach ($this->steps->find($key)['arranges'] ?? [] as $kind) {
                // A service costs what the car earns between two services, which the driver does not choose, so the mentor pays all of it; for the rest of the
                // jobs, whose cost follows from the part the driver picks, the sum of the script is the most.
                $arranged[$kind] = ['ms' => $script['first_mount_ms'], 'covers_cents' => $kind === WorkshopJob::KIND_SERVICE ? PHP_INT_MAX : $script['covers_up_to_cents']];
            }
        }

        return $arranged;
    }

    /**
     * What a job costs and takes as the step arranges it: shorter, and cheaper by what the mentor pays. A job that cannot be done (no cost, no time) is as it was.
     *
     * @param  array{ms: int, covers_cents: int}|null  $arrangement  the arrangement of the kind of the job, from `arrangements`
     * @param  array{cost_cents?: int|null, duration_ms?: int|null}  $quote
     * @return array{cost_cents?: int|null, duration_ms?: int|null}
     */
    public function arrange(array $quote, ?array $arrangement): array
    {
        if ($arrangement === null) {
            return $quote;
        }

        if (isset($quote['duration_ms'])) {
            $quote['duration_ms'] = min($quote['duration_ms'], $arrangement['ms']);
        }

        if (isset($quote['cost_cents'])) {
            $quote['cost_cents'] = max(0, $quote['cost_cents'] - $arrangement['covers_cents']);
        }

        return $quote;
    }

    /**
     * The car that the exchange offers this driver besides its own listings, or null: the step that waits asks for a bought car, the script has the listing of
     * the tutorial, and the driver has not bought it yet.
     *
     * @return array{vehicle: string, age_years: int, mileage_km: int, condition_ppm: int, price_cents: int}|null
     */
    public function exchangeListing(int $userId): ?array
    {
        $listing = $this->steps->script()['exchange_listing'] ?? null;

        if ($listing === null || LedgerEntry::query()->where('idem_key', "listing:{$userId}:".self::LISTING_KEY)->exists()) {
            return null;
        }

        foreach (TutorialProgress::query()->where('user_id', $userId)->where('state', TutorialProgress::STATE_ACTIVE)->pluck('step_key') as $key) {
            if (in_array('car_bought', TutorialSteps::conditions($this->steps->find($key)['completion'] ?? []), true)) {
                return $listing;
            }
        }

        return null;
    }

    /** @return Collection<string, TutorialProgress> */
    private function rows(int $userId): Collection
    {
        return TutorialProgress::query()->where('user_id', $userId)->get()->keyBy('step_key');
    }
}
