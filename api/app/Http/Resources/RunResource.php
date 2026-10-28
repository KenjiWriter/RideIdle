<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\FrozenRun;
use App\Domain\Run\GameClock;
use App\Domain\Run\Surface;
use App\Domain\Settlement\Ppm;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\Settler;
use App\Models\RaceRun;
use App\Services\RunService;
use App\Services\Travel;
use App\Support\I18nText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run as the client sees it. Times are milliseconds since the Unix epoch of the server clock, which the
 * client should use for its countdowns (`server_time_ms` is the clock at the moment of the answer).
 *
 * @mixin RaceRun
 */
final class RunResource extends JsonResource
{
    /** How many segments of the weather the forecast shows after the one that is now. */
    private const FORECAST_SEGMENTS = 4;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $vehicle = app(Catalog::class)->vehicle($this->car_slug);
        $track = app(Rules::class)->track($this->track_slug);
        $config = FrozenRun::configFromArray($this->config);
        $nowMs = RunService::nowMs();

        return [
            'id' => $this->id,
            'car' => [
                'slug' => $this->car_slug,
                'name' => $vehicle !== null ? I18nText::from($vehicle['name'])->for() : $this->car_slug,
            ],
            'track' => [
                'slug' => $this->track_slug,
                'region' => $track['region'] ?? null,
                'kind' => $track === null ? 'standard' : app(Rules::class)->trackKind($this->track_slug),
                'name' => $track !== null ? I18nText::from($track['name'])->for() : $this->track_slug,
            ],
            'status' => $this->status,
            'stop_reason' => $this->stop_reason,
            // The journey the car is on, when it stands on the road between two cities.
            'travel' => $this->stop_reason === RaceRun::STOP_TRAVEL ? $this->travel() : null,
            'laps_done' => $this->laps_done,
            'fuel_ml' => $this->fuel_ml,
            'tank_ml' => $vehicle !== null ? app(RunInputsFactory::class)->tankMl($this->car_slug, $this->car?->mountedSlugs() ?? []) : null,
            'cursor_ms' => $this->cursor_ms,
            'state_version' => $this->state_version,
            // What the actions of the run cost, so a button can say it before it is pressed.
            'prices' => [
                'fuel_cents_per_liter' => app(Rules::class)->fuelPriceCentsPerLiter(),
                'repair_cents' => app(Rules::class)->repairCostCents(),
            ],
            'lap' => [
                'ms' => $this->config['lap_ms'],
                // How much of its time a lap takes when it starts at night: 1 000 000 on a track that is lit.
                'night_ppm' => $this->config['night_lap_ppm'] ?? Ppm::ONE,
                'cash_cents' => $this->config['lap_cash_cents'],
                'xp' => $this->config['lap_xp'],
                'fuel_ml' => $this->config['fuel_per_lap_ml'],
                // What a lap gives the car, which levels on its laps: the same in every weather.
                'car_xp' => app(Rules::class)->carXpPerLap(),
            ],
            // The bots the car races against, and the place it holds among them by its dry lap, which multiplies the reward of a lap: null for a run that was frozen
            // before the bots, or on a track and a tier that the table of the profiles does not know.
            'field' => $this->config['field'] ?? null,
            // The clock of the game, which a client counts from the time of the server: the length of a day and where its phases begin.
            'clock' => ['day_ms' => GameClock::DAY_MS, 'phases' => GameClock::phases()],
            // The weather of the region now and ahead: it is drawn from the seed of the climate, so it can be told ahead.
            'weather' => $this->weather($config, $nowMs),
            'current_lap' => $this->currentLap($config, $nowMs),
            'server_time_ms' => $nowMs,
        ];
    }

    /**
     * The weather in the segment of 22.5 minutes that `$nowMs` is in, when it ends, and what the next one brings, and the forecast: this
     * segment and the four after it (an hour and a half), each with the surface it makes of the track.
     *
     * @return array{now: string, ends_ms: int, next: string, forecast: list<array{weather: string, starts_ms: int, ends_ms: int, surface: string}>}
     */
    private function weather(RunConfig $config, int $nowMs): array
    {
        $segment = intdiv($nowMs, Settler::SEGMENT_MS);

        return [
            'now' => $config->weather->at($segment),
            'ends_ms' => ($segment + 1) * Settler::SEGMENT_MS,
            'next' => $config->weather->at($segment + 1),
            'forecast' => $this->forecast($config, $segment),
        ];
    }

    /** @return array{from: string, from_name: string, to: string, to_name: string, start_ms: int, arrive_ms: int}|null */
    private function travel(): ?array
    {
        $journey = app(Travel::class)->activeOf($this->id);

        $rules = app(Rules::class);

        return $journey === null ? null : [
            'from' => $journey->from_region,
            'from_name' => I18nText::from($rules->region($journey->from_region)['name'])->for(),
            'to' => $journey->to_region,
            'to_name' => I18nText::from($rules->region($journey->to_region)['name'])->for(),
            'start_ms' => $journey->start_ms,
            'arrive_ms' => $journey->arrive_ms,
        ];
    }

    /**
     * @return list<array{weather: string, starts_ms: int, ends_ms: int, surface: string}>
     */
    private function forecast(RunConfig $config, int $segment): array
    {
        $rules = app(Rules::class);
        $track = $rules->track($this->track_slug);
        $forecast = [];

        for ($ahead = 0; $ahead <= self::FORECAST_SEGMENTS; $ahead++) {
            $weather = $config->weather->at($segment + $ahead);
            $ground = $rules->weather()[$weather]['ground'] ?? 'dry';

            $forecast[] = [
                'weather' => $weather,
                'starts_ms' => ($segment + $ahead) * Settler::SEGMENT_MS,
                'ends_ms' => ($segment + $ahead + 1) * Settler::SEGMENT_MS,
                'surface' => Surface::of($track['surface'] ?? 'asphalt', $ground),
            ];
        }

        return $forecast;
    }

    /**
     * The lap in progress: it started at the cursor, which settlement leaves at the start of the lap that is not done
     * yet, and takes the lap time of the weather it started in. A car that stands, in the workshop, with an empty tank or
     * after a breakdown, or one that waits for a tow, has none.
     *
     * @return array{start_ms: int, finish_ms: int, ms: int, weather: string}|null
     */
    private function currentLap(RunConfig $config, int $nowMs): ?array
    {
        if ($this->status !== RaceRun::STATUS_RUNNING || $this->cursor_ms > $nowMs) {
            return null;
        }

        $weather = $config->weather->at(intdiv($this->cursor_ms, Settler::SEGMENT_MS));
        $ms = isset($config->lapMs[$weather]) ? $config->lapMsAt($weather, $this->cursor_ms) : null;

        return $ms === null ? null : ['start_ms' => $this->cursor_ms, 'finish_ms' => $this->cursor_ms + $ms, 'ms' => $ms, 'weather' => $weather];
    }
}
