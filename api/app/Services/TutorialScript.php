<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\TutorialSteps;
use App\Models\CrateOpening;
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

    /** @return Collection<string, TutorialProgress> */
    private function rows(int $userId): Collection
    {
        return TutorialProgress::query()->where('user_id', $userId)->get()->keyBy('step_key');
    }
}
