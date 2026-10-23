<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Testing\TestResponse;

function startRun(User $driver, array $body = ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/runs', $body, [...bearerFor($driver), ...$headers]);
}

it('requires a token', function () {
    $this->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'])->assertUnauthorized();
});

it('starts the run of a driver with a full tank', function () {
    $driver = User::factory()->guest()->create();

    startRun($driver)
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.car.slug', 'rdzak-gt-4')
        ->assertJsonPath('data.car.name', 'Rdzak GT-4')
        ->assertJsonPath('data.track.slug', 'zlomowo-street')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.stop_reason', null)
        ->assertJsonPath('data.laps_done', 0)
        ->assertJsonPath('data.fuel_ml', 45_000)
        ->assertJsonPath('data.tank_ml', 45_000)
        ->assertJsonPath('data.state_version', 0)
        ->assertJsonPath('data.lap.ms.dry', 75_000)
        ->assertJsonPath('data.lap.cash_cents.rain', 1_442)
        ->assertJsonPath('data.lap.fuel_ml', 1_500)
        ->assertJsonPath('data.prices', ['fuel_cents_per_liter' => 200, 'repair_cents' => 3_000])
        ->assertJsonPath('wallet.cash_cents', 0)
        ->assertJsonPath('wallet.xp', 0)
        ->assertJsonStructure(['data' => ['id', 'cursor_ms', 'server_time_ms']]);

    $run = RaceRun::query()->where('user_id', $driver->id)->firstOrFail();

    expect($run->seed)->toMatch('/^[a-f0-9]{32}$/')
        ->and($run->cursor_ms)->toBeGreaterThan(1_700_000_000_000)
        ->and($run->config['lap_ms']['dry'])->toBe(75_000)
        ->and($run->break_after_laps)->toBeInt()->toBeGreaterThan(0);
});

it('starts every starter wreck with its own tank and lap', function (string $car, int $tankMl, int $dryLapMs, int $fuelPerLapMl) {
    $driver = User::factory()->guest()->create();

    startRun($driver, ['car' => $car, 'track' => 'zlomowo-street'])
        ->assertCreated()
        ->assertJsonPath('data.fuel_ml', $tankMl)
        ->assertJsonPath('data.tank_ml', $tankMl)
        ->assertJsonPath('data.lap.ms.dry', $dryLapMs)
        ->assertJsonPath('data.lap.fuel_ml', $fuelPerLapMl);
})->with([
    'rdzak' => ['rdzak-gt-4', 45_000, 75_000, 1_500],
    'bizon' => ['bizon-m-2', 60_000, 73_394, 2_109],
    'kuna' => ['kuna-4x4', 70_000, 91_848, 1_500],
]);

it('names the car and the track in the language of the request', function (string $locale, string $track) {
    $driver = User::factory()->guest()->create();

    startRun($driver, headers: ['X-Locale' => $locale])
        ->assertCreated()
        ->assertHeader('Content-Language', $locale)
        ->assertJsonPath('data.track.name', $track);
})->with([
    'polish' => ['pl', 'Sprint uliczny Złomowo'],
    'english' => ['en', 'Złomowo Street Sprint'],
    'german' => ['de', 'Stadtsprint Złomowo'],
    'brazilian portuguese' => ['pt-BR', 'Sprint urbano de Złomowo'],
]);

it('refuses a car or a track that does not exist or is missing', function (array $body, array $invalid) {
    $driver = User::factory()->guest()->create();

    startRun($driver, $body)->assertUnprocessable()->assertJsonValidationErrors($invalid);

    expect(RaceRun::query()->count())->toBe(0);
})->with([
    'unknown car' => [['car' => 'no-such-car', 'track' => 'zlomowo-street'], ['car']],
    'unknown track' => [['car' => 'rdzak-gt-4', 'track' => 'no-such-track'], ['track']],
    'no car' => [['track' => 'zlomowo-street'], ['car']],
    'no track' => [['car' => 'rdzak-gt-4'], ['track']],
    'nothing' => [[], ['car', 'track']],
]);

it('refuses a second run of the same driver and says which one it is', function () {
    $driver = User::factory()->guest()->create();
    $first = startRun($driver)->assertCreated()->json('data.id');

    startRun($driver, ['car' => 'bizon-m-2', 'track' => 'zlomowo-street'], ['X-Locale' => 'pl'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'run_exists')
        ->assertJsonPath('run_id', $first)
        ->assertJsonPath('message', 'Masz już auto na torze.');

    expect(RaceRun::query()->count())->toBe(1);
});

it('lets two drivers start their own runs', function () {
    $a = User::factory()->guest()->create();
    $b = User::factory()->guest()->create();

    $first = startRun($a)->assertCreated()->json('data.id');
    $second = startRun($b)->assertCreated()->json('data.id');

    expect($first)->not->toBe($second)
        ->and(RaceRun::query()->count())->toBe(2);
});

describe('the level of the driver and the tracks', function () {
    it('refuses a track that the level of the driver does not open, and says what it takes and how to get it', function () {
        $driver = User::factory()->guest()->create();

        startRun($driver, ['car' => 'rdzak-gt-4', 'track' => 'autodrom-circuit'], ['X-Locale' => 'pl'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_locked')
            ->assertJsonPath('message', 'Wymaga poziomu gracza 12 (masz 1). Zdobywasz XP, jeżdżąc.');

        expect(RaceRun::query()->count())->toBe(0);
    });

    it('opens a track from the level that it asks for, and not one level before', function () {
        $rules = app(Rules::class);
        $before = User::factory()->guest()->create();
        $enough = User::factory()->guest()->create();

        // Kruszyn is open from level 3, which takes xp_base * 3 ^ xp_exponent experience.
        (new Ledger)->ensureWallet($before->id);
        (new Ledger)->grantXp($before->id, $rules->playerXpToReach(3) - 1);
        (new Ledger)->ensureWallet($enough->id);
        (new Ledger)->grantXp($enough->id, $rules->playerXpToReach(3));

        startRun($before, ['car' => 'kuna-4x4', 'track' => 'kruszyn-quarry'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_locked')
            ->assertJsonPath('details', ['required' => 3, 'current' => 2]);

        startRun($enough, ['car' => 'kuna-4x4', 'track' => 'kruszyn-quarry'])
            ->assertCreated()
            ->assertJsonPath('data.track.slug', 'kruszyn-quarry')
            ->assertJsonPath('data.lap.fuel_ml', 1_900);
    });
});
