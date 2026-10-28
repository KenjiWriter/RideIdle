<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Content\ContentSource;
use App\Models\Car;
use App\Models\RaceRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceRun>
 */
class RaceRunFactory extends Factory
{
    /**
     * A run of a car with a constant 75 second lap that pays $15.50 and 25 XP, uses 1.5 l per lap
     * and has 45 l in the tank: the numbers of the settlement test vectors.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'car_slug' => 'rdzak-gt-4',
            'car_id' => fn (array $attributes): int => Car::factory()->create([
                'user_id' => $attributes['user_id'],
                'vehicle_slug' => $attributes['car_slug'],
            ])->id,
            'track_slug' => 'zlomowo-street',
            'status' => RaceRun::STATUS_RUNNING,
            'cursor_ms' => 0,
            'laps_done' => 0,
            'fuel_ml' => 45_000,
            'break_after_laps' => null,
            'seed' => bin2hex(random_bytes(16)),
            'config' => self::constantConfig(),
            // Frozen with the content that is live, as a run started now would be, so that it is not frozen again for a pack that is the one it is on.
            'ruleset_version' => fn (): string => app(ContentSource::class)->rulesetVersion(),
            'state_version' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function constantConfig(int $lapMs = 75_000, int $cash = 1_550, int $xp = 25, int $fuelPerLapMl = 1_500): array
    {
        return [
            'lap_ms' => ['dry' => $lapMs],
            'lap_cash_cents' => ['dry' => $cash],
            'lap_xp' => ['dry' => $xp],
            'fuel_per_lap_ml' => $fuelPerLapMl,
            'weather' => ['seed' => 'test-region', 'chances_ppm' => ['dry' => 1_000_000]],
        ];
    }
}
