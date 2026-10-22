<?php

declare(strict_types=1);

use App\Models\User;

function getStarterCars(array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->getJson('/api/v1/starter-cars', [...bearerFor(User::factory()->guest()->create()), ...$headers]);
}

it('requires a token', function () {
    $this->getJson('/api/v1/starter-cars')->assertUnauthorized();
});

it('lists the three starter wrecks, the cheapest first', function () {
    $response = getStarterCars()->assertOk();

    expect($response->json('data.*.slug'))->toBe(['rdzak-gt-4', 'kuna-4x4', 'bizon-m-2']);
});

it('describes a wreck and what it does on the starting track', function () {
    getStarterCars(['X-Locale' => 'pl'])
        ->assertOk()
        ->assertJsonPath('track', ['slug' => 'zlomowo-street', 'name' => 'Sprint uliczny Złomowo'])
        ->assertJsonPath('data.0.name', 'Rdzak GT-4')
        ->assertJsonPath('data.0.description', 'Ulica i niskie koszty. Słaby w terenie i na mokrym. Polecany na start.')
        ->assertJsonPath('data.0.class', 'hatchback')
        ->assertJsonPath('data.0.drivetrain', 'fwd')
        ->assertJsonPath('data.0.engine', ['layout' => 'R4', 'displacement_l' => 1.8])
        ->assertJsonPath('data.0.power_kw', 97)
        ->assertJsonPath('data.0.mass_kg', 1_000)
        ->assertJsonPath('data.0.top_speed_kmh', 175)
        ->assertJsonPath('data.0.tank_l', 45)
        ->assertJsonPath('data.0.lap', ['weather' => 'dry', 'ms' => 75_000, 'cash_cents' => 1_550, 'xp' => 25, 'fuel_ml' => 1_500]);
});

it('shows the numbers the car will drive, the same as when a run starts', function () {
    $response = getStarterCars()->assertOk();

    // The Bizon is faster and thirstier than the Rdzak, the Kuna slower (computed apart from the code).
    expect($response->json('data.2.slug'))->toBe('bizon-m-2')
        ->and($response->json('data.2.lap.ms'))->toBe(67_015)
        ->and($response->json('data.2.lap.fuel_ml'))->toBe(2_109)
        ->and($response->json('data.1.lap.ms'))->toBe(82_313);

    $driver = User::factory()->guest()->create();
    test()->postJson('/api/v1/runs', ['car' => 'bizon-m-2', 'track' => $response->json('track.slug')], bearerFor($driver))
        ->assertCreated()
        ->assertJsonPath('data.lap.ms.dry', 67_015)
        ->assertJsonPath('data.lap.fuel_ml', 2_109);
});

it('is in the language of the request', function (string $locale, string $description) {
    getStarterCars(['X-Locale' => $locale])->assertOk()->assertJsonPath('data.2.description', $description);
})->with([
    'english' => ['en', 'Plenty of power on dry asphalt. Weak on gravel and in the rain.'],
    'german' => ['de', 'Viel Leistung auf trockenem Asphalt. Schwach auf Schotter und im Regen.'],
    'brazilian portuguese' => ['pt-BR', 'Muita potência no asfalto seco. Fraco no cascalho e na chuva.'],
]);
