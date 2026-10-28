<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\CityGarage;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Testing\TestResponse;

// Several tracks in a city: the map lists them with what each is for the car that is played, and the driver changes the track of the car where it stands, to a
// sprint (a lap of 10 to 15 seconds that pays little and burns little), the standard track, or an endurance track (a lap of minutes that burns a tank in about
// twenty).

/** A driver with a run (the stock Rdzak with 45 l, at T0) and the experience of a level. @return array{0: User, 1: App\Models\RaceRun} */
function driverAtLevel(int $level, array $run = []): array
{
    [$driver, $race] = driverWithRun(['seed' => str_repeat('0', 32), ...$run]);

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    foreach (array_keys(app(Rules::class)->regions()) as $region) {
        CityGarage::query()->firstOrCreate(['user_id' => $driver->id, 'region' => $region], ['capacity' => 3]);
    }

    return [$driver, $race];
}

function mapOf(User $driver): TestResponse
{
    return test()->getJson('/api/v1/map', bearerFor($driver));
}

function putOnTrack(User $driver, int $run, string $track, array $headers = []): TestResponse
{
    return test()->patchJson("/api/v1/runs/{$run}", ['track' => $track], [...bearerFor($driver), ...$headers]);
}

/** The rules with a change, for the requests that follow. */
function withRulesChanged(Closure $change): void
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);

    app()->singleton(Rules::class, fn () => Rules::fromArray($change($data)));
}

describe('the tracks of a city on the map', function () {
    it('are listed in the order of the rules, so that the first is the one a car arrives on, with their kind and the level they ask', function () {
        [$driver] = driverAtLevel(1);

        $cities = collect(mapOf($driver)->assertOk()->json('data.cities'))->keyBy('slug');
        $zlomowo = collect($cities['zlomowo']['tracks']);

        expect($zlomowo->pluck('slug')->all())->toBe(['zlomowo-street', 'zlomowo-yard-dash', 'zlomowo-ring-road'])
            ->and($zlomowo->pluck('kind')->all())->toBe(['standard', 'sprint', 'endurance'])
            ->and($zlomowo->pluck('level_required')->all())->toBe([1, 1, 4])
            ->and($zlomowo->pluck('open')->all())->toBe([true, true, false])
            ->and($zlomowo->pluck('current')->all())->toBe([true, false, false])
            // The track that was shown before the list is the first of it, and the kind is said there too.
            ->and($cities['zlomowo']['track'])->toMatchArray(['slug' => 'zlomowo-street', 'kind' => 'standard'])
            ->and(collect($cities['kruszyn']['tracks'])->pluck('slug')->all())->toBe(['kruszyn-quarry'])
            ->and(collect($cities['autodrom']['tracks'])->pluck('slug')->all())->toBe(['autodrom-circuit', 'autodrom-short-loop', 'autodrom-endurance']);
    });

    it('say what a lap is for the car: a sprint is short, burns little an hour and lasts a tank for long, an endurance track is the other way', function () {
        [$driver] = driverAtLevel(4);

        $tracks = collect(mapOf($driver)->json('data.cities'))->keyBy('slug')['zlomowo']['tracks'];
        [$standard, $sprint, $endurance] = array_column($tracks, 'car');

        expect($sprint['lap_ms'])->toBeBetween(8_000, 20_000)
            ->and($standard['lap_ms'])->toBeBetween(60_000, 90_000)
            ->and($endurance['lap_ms'])->toBeBetween(240_000, 360_000)
            ->and($sprint['cash_cents'])->toBeLessThan($standard['cash_cents'])
            ->and($endurance['cash_cents'])->toBeGreaterThan($standard['cash_cents'])
            // The fuel an hour grows from the sprint to the endurance track, so the tank lasts the shorter, and the laps it holds fall too.
            ->and($sprint['fuel_cents_per_hour'])->toBeLessThan($standard['fuel_cents_per_hour'])
            ->and($standard['fuel_cents_per_hour'])->toBeLessThan($endurance['fuel_cents_per_hour'])
            ->and($sprint['tank_ms'])->toBeGreaterThan($standard['tank_ms'])
            ->and($standard['tank_ms'])->toBeGreaterThan($endurance['tank_ms'])
            ->and($sprint['laps_per_tank'])->toBeGreaterThan($standard['laps_per_tank'])
            ->and($endurance['laps_per_tank'])->toBeBetween(2, 8)
            // What an hour earns after the fuel is not what it pays: the numbers are the gross and the fuel of an hour, and the difference.
            ->and($endurance['net_cents_per_hour'])->toBe($endurance['gross_cents_per_hour'] - $endurance['fuel_cents_per_hour'])
            ->and($sprint['fits_tank'])->toBeTrue()
            ->and($endurance['fits_tank'])->toBeTrue()
            ->and($endurance['tank_ml'])->toBe(45_000);
    });

    it('have nothing to say about a car when the driver has none, but are listed', function () {
        $driver = User::factory()->guest()->create();

        $tracks = collect(mapOf($driver)->assertOk()->json('data.cities'))->keyBy('slug')['zlomowo']['tracks'];

        expect(array_column($tracks, 'car'))->toBe([null, null, null])
            ->and(array_column($tracks, 'current'))->toBe([false, false, false]);
    });
});

describe('choosing the track of the car where it stands', function () {
    it('changes it to the sprint, paid and burnt by the lap of the sprint from the moment of the change', function () {
        [$driver, $run] = driverAtLevel(1);

        putOnTrack($driver, $run->id, 'zlomowo-yard-dash')
            ->assertOk()
            ->assertJsonPath('data.track.slug', 'zlomowo-yard-dash')
            ->assertJsonPath('data.track.kind', 'sprint');

        $run->refresh();

        expect($run->track_slug)->toBe('zlomowo-yard-dash')
            ->and($run->config['lap_ms']['dry'])->toBeBetween(8_000, 20_000)
            ->and($run->config['fuel_per_lap_ml'])->toBeLessThan(400);

        // And back to the standard track.
        putOnTrack($driver, $run->id, 'zlomowo-street')->assertOk()->assertJsonPath('data.track.kind', 'standard');
    });

    it('keeps the level that a track asks: the ring road is for level 4 and not before', function () {
        [$driver, $run] = driverAtLevel(1);

        putOnTrack($driver, $run->id, 'zlomowo-ring-road', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_locked')
            ->assertJsonPath('message', 'Requires player level 4 (you are level 1). You get XP by driving.');

        expect($run->fresh()->track_slug)->toBe('zlomowo-street');

        [$other, $otherRun] = driverAtLevel(4);

        putOnTrack($other, $otherRun->id, 'zlomowo-ring-road')->assertOk()->assertJsonPath('data.track.kind', 'endurance');
    });

    it('refuses a track where the car cannot hold the fuel of one lap, and says how much it takes and how much the tank holds', function () {
        withRulesChanged(function (array $data): array {
            $data['tracks']['zlomowo-ring-road']['fuel_per_lap_ml'] = 60_000;

            return $data;
        });
        [$driver, $run] = driverAtLevel(4);

        putOnTrack($driver, $run->id, 'zlomowo-ring-road', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_tank_too_small')
            ->assertJsonPath('message', 'Cannot finish a lap here: a lap takes 60 l and the tank holds 45 l.');

        expect($run->fresh()->track_slug)->toBe('zlomowo-street');
    });

    it('says it in the language of the driver', function () {
        withRulesChanged(function (array $data): array {
            $data['tracks']['zlomowo-ring-road']['fuel_per_lap_ml'] = 60_000;

            return $data;
        });
        [$driver, $run] = driverAtLevel(4);

        putOnTrack($driver, $run->id, 'zlomowo-ring-road', ['X-Locale' => 'pl'])->assertStatus(422)->assertJsonPath('message', 'Auto nie ukończy tu okrążenia: okrążenie zużywa 60 l, a zbiornik mieści 45 l.');
    });
});
