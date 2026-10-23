<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
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
            'tank_ml' => $vehicle !== null ? app(RunInputsFactory::class)->tankMl($this->car_slug) : null,
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
            'server_time_ms' => RunService::nowMs(),
        ];
    }
}
