<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Http\Controllers\Controller;
use App\Services\ContentImages;
use App\Support\I18nText;
use Illuminate\Http\JsonResponse;

/**
 * The wrecks a new driver can start with, for the screen where one is chosen. Next to the body of each car there
 * is what it does on the track a run starts on, in the most common weather there: the lap, the reward and the
 * fuel use, frozen in the same way as for a run, so the numbers on the screen are those the car will drive.
 */
final class StarterCarController extends Controller
{
    public function __invoke(Catalog $catalog, Rules $rules, RunInputsFactory $inputs): JsonResponse
    {
        $trackSlug = $rules->startingTrack();
        $track = $rules->track($trackSlug);
        $chances = $rules->region($track['region'])['weather_ppm'];
        $weather = (string) array_search(max($chances), $chances, true);

        $freezer = new RunFreezer;

        // The cheapest first: that is the car recommended for a start.
        $vehicles = collect($catalog->startingVehicles())->sortBy('price_cents')->values();

        $cars = $vehicles->map(function (array $vehicle) use ($freezer, $inputs, $trackSlug, $weather): array {
            $config = $freezer->freeze($inputs->make($vehicle['slug'], $trackSlug), 'preview', 0)->config;

            return [
                'slug' => $vehicle['slug'],
                'name' => I18nText::from($vehicle['name'])->for(),
                'description' => isset($vehicle['description']) ? I18nText::from($vehicle['description'])->for() : null,
                'image_url' => ContentImages::urlOf($vehicle),
                'class' => $vehicle['class'],
                'drivetrain' => $vehicle['drivetrain'],
                'engine' => ['layout' => $vehicle['engine']['layout'], 'displacement_l' => $vehicle['engine']['displacement_l']],
                'power_kw' => $vehicle['power_kw'],
                'mass_kg' => $vehicle['mass_kg'],
                'top_speed_kmh' => $vehicle['top_speed_kmh'],
                'cargo_kg' => $vehicle['cargo_kg'],
                'tank_l' => $vehicle['tank_l'],
                'lap' => [
                    'weather' => $weather,
                    'ms' => $config['lap_ms'][$weather],
                    'cash_cents' => $config['lap_cash_cents'][$weather],
                    'xp' => $config['lap_xp'][$weather],
                    'fuel_ml' => $config['fuel_per_lap_ml'],
                ],
            ];
        })->all();

        return response()->json([
            'data' => $cars,
            'track' => ['slug' => $trackSlug, 'name' => I18nText::from($track['name'])->for()],
        ]);
    }
}
