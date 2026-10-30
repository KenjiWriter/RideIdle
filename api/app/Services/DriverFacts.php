<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\TutorialSteps;
use App\Domain\Run\SeededWeather;
use App\Domain\Settlement\Settler;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\MergeAttempt;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use Carbon\CarbonInterface;

/**
 * The facts about a driver that the server can read from the state of the game: whether they have a run, how full the tank is, whether the car has broken down,
 * whether a job of the workshop was done since a moment, what they hold. The tutorial asks them to tell when a step starts and ends (a fact, or a list of facts that
 * must all hold; a fact may take a number or a word after a colon: `cash:450`), and the missions ask them for the goals that are a state and not a count.
 *
 * The facts about what the driver did (a job done, a car bought) count only what was done since a moment (the start of the step), so that something done before
 * it was asked for does not end it at once. They only read.
 */
final class DriverFacts
{
    /** A tank under a quarter is low, and one at four fifths is filled. */
    private const LOW_FUEL_SHARE = 0.25;

    private const FILLED_FUEL_SHARE = 0.8;

    /** How many segments of the weather the forecast shows after the one that is now (as the run shows it). */
    private const FORECAST_SEGMENTS = 4;

    public function __construct(
        private readonly Rules $rules,
        private readonly Catalog $catalog,
        private readonly Garage $garage,
        private readonly CityGarages $garages,
        private readonly RunInputsFactory $inputs,
    ) {}

    /**
     * Whether every fact of a trigger or a completion holds.
     *
     * @param  string|list<string>  $conditions
     */
    public function holdsAll(string|array $conditions, int $userId, ?RaceRun $run, ?TutorialProgress $row): bool
    {
        foreach (TutorialSteps::conditions($conditions) as $condition) {
            if (! $this->holds($condition, $userId, $run, $row)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a fact of the game holds for the driver now. */
    public function holds(string $condition, int $userId, ?RaceRun $run, ?TutorialProgress $row): bool
    {
        $broken = $run !== null && $run->status === RaceRun::STATUS_STOPPED && $run->stop_reason === 'breakdown';
        [$condition, $argument] = array_pad(explode(':', $condition, 2), 2, null);
        $since = $row?->started_at;

        return match ($condition) {
            'none' => true,
            'run_started' => $run !== null,
            'driving' => $run !== null && $run->stop_reason !== RaceRun::STOP_PARKED,
            'lap_completed' => $run !== null && $run->laps_done >= 1,
            'fuel_low' => $run !== null && $run->fuel_ml < $this->tankMl($run) * self::LOW_FUEL_SHARE,
            'fuel_restored' => $run !== null && $run->fuel_ml >= $this->tankMl($run) * self::FILLED_FUEL_SHARE,
            'breakdown' => $broken,
            'recovered' => $run !== null && ! $broken,
            'acknowledged' => $row?->acknowledged_at !== null,
            'job_queued' => WorkshopJob::query()->where('user_id', $userId)->exists(),
            'crate_opened' => CrateOpening::query()->where('user_id', $userId)->exists(),
            'travel_started' => Travel::query()->where('user_id', $userId)->exists(),
            'travel_done' => Travel::query()->where('user_id', $userId)->where('status', Travel::STATUS_ARRIVED)->exists(),
            'rain_forecast' => $run !== null && $this->rainComes($run),
            'merge_done' => MergeAttempt::query()->where('user_id', $userId)->when($since, fn ($query) => $query->where('created_at', '>=', $since))->exists(),
            'service_done' => $this->jobDone($userId, WorkshopJob::KIND_SERVICE, $since),
            'upgrade_done' => $this->jobDone($userId, WorkshopJob::KIND_UPGRADE, $since),
            'car_bought' => $this->bought($userId, 'exchange', $since),
            'equipment_bought' => $this->bought($userId, 'workshop_gear', $since),
            'garage_bought' => $this->bought($userId, 'garage', $since),
            'email_confirmed' => User::query()->whereKey($userId)->whereNotNull('email_verified_at')->exists(),
            'city_open' => $this->cityOpen($userId),
            'tires_mounted' => $this->tiresMounted($userId, (string) $argument),
            'car_level' => ($this->garage->carOf($userId)?->level ?? 0) >= (int) $argument,
            'cash' => (int) Wallet::query()->where('user_id', $userId)->value('cash_cents') >= (int) $argument * 100,
            'returned' => $this->returnedAfter($userId, (int) $argument),
            default => false,
        };
    }

    /** Whether the car that the driver plays stands on tires of that kind. */
    public function tiresMounted(int $userId, string $kind): bool
    {
        $car = $this->garage->carOf($userId);
        $tiresSlot = $this->rules->model()->slotOf('tires');
        $slug = $tiresSlot === null ? null : ($car?->mountedSlugs()[$tiresSlot] ?? null);

        return $slug !== null && ($this->catalog->part($slug)['tire_kind'] ?? null) === $kind;
    }

    /** Whether the wet ground is on the forecast of the track of the run: this segment of the weather and the four after it, as the run shows them. */
    private function rainComes(RaceRun $run): bool
    {
        $weather = $run->config['weather'] ?? null;

        if (! is_array($weather)) {
            return false;
        }

        $seeded = new SeededWeather((string) $weather['seed'], $weather['chances_ppm']);
        $segment = intdiv(RunService::nowMs(), Settler::SEGMENT_MS);

        for ($ahead = 0; $ahead <= self::FORECAST_SEGMENTS; $ahead++) {
            if (($this->rules->weather()[$seeded->at($segment + $ahead)]['ground'] ?? 'dry') === 'wet') {
                return true;
            }
        }

        return false;
    }

    private function jobDone(int $userId, string $kind, ?CarbonInterface $since): bool
    {
        return WorkshopJob::query()->where('user_id', $userId)->where('kind', $kind)->where('status', WorkshopJob::STATUS_DONE)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))->exists();
    }

    /** Whether the driver paid for something of that kind (a posting of the ledger with that reason) since the step started. */
    private function bought(int $userId, string $reason, ?CarbonInterface $since): bool
    {
        return LedgerEntry::query()->where('user_id', $userId)->where('reason', $reason)->where('delta', '<', 0)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))->exists();
    }

    /** Whether there is a city with a track that the level of the driver opens, where the driver has no garage yet. */
    private function cityOpen(int $userId): bool
    {
        $level = $this->rules->playerLevelAt((int) Wallet::query()->where('user_id', $userId)->value('xp'));
        $owned = $this->garages->of($userId)->pluck('region')->all();

        foreach (array_keys($this->rules->regions()) as $region) {
            if (! in_array($region, $owned, true) && $this->rules->cityMinPlayerLevel($region) <= $level) {
                return true;
            }
        }

        return false;
    }

    /** Whether the last two visits of the driver were at least this many minutes apart, which is a break that ended just now (see `TouchLastSeen`). */
    private function returnedAfter(int $userId, int $minutes): bool
    {
        $user = User::query()->find($userId);

        return $user?->last_seen_at !== null && $user->previous_seen_at !== null && $user->previous_seen_at->diffInMinutes($user->last_seen_at) >= $minutes;
    }

    private function tankMl(RaceRun $run): int
    {
        return $this->inputs->tankMl($run->car_slug, $run->car?->mountedSlugs() ?? []);
    }
}
