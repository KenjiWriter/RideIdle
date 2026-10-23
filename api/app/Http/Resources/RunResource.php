<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\FrozenRun;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\Settler;
use App\Models\RaceRun;
use App\Services\RunService;
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
                'name' => $track !== null ? I18nText::from($track['name'])->for() : $this->track_slug,
            ],
            'status' => $this->status,
            'stop_reason' => $this->stop_reason,
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
                'cash_cents' => $this->config['lap_cash_cents'],
                'xp' => $this->config['lap_xp'],
                'fuel_ml' => $this->config['fuel_per_lap_ml'],
            ],
            // The weather of the region now and in the next 22.5 minutes: it is drawn from the seed of the climate, so it can be told ahead.
            'weather' => $this->weather($config, $nowMs),
            'current_lap' => $this->currentLap($config, $nowMs),
            'server_time_ms' => $nowMs,
        ];
    }

    /**
     * The weather in the segment of 22.5 minutes that `$nowMs` is in, when it ends, and what the next one brings.
     *
     * @return array{now: string, ends_ms: int, next: string}
     */
    private function weather(RunConfig $config, int $nowMs): array
    {
        $segment = intdiv($nowMs, Settler::SEGMENT_MS);

        return [
            'now' => $config->weather->at($segment),
            'ends_ms' => ($segment + 1) * Settler::SEGMENT_MS,
            'next' => $config->weather->at($segment + 1),
        ];
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
        $ms = $config->lapMs[$weather] ?? null;

        return $ms === null ? null : ['start_ms' => $this->cursor_ms, 'finish_ms' => $this->cursor_ms + $ms, 'ms' => $ms, 'weather' => $weather];
    }
}
