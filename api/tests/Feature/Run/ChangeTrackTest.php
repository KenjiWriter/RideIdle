<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/** The shipped rules have one track, so a test adds a longer one in the same region: a base lap of 90 s and $20.00. */
function rulesWithLongTrack(): void
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true);

    $data['tracks']['zlomowo-long'] = [
        ...$data['tracks']['zlomowo-street'],
        'name' => ['en' => 'Złomowo Long Run', 'pl' => 'Długi bieg Złomowo', 'fr' => 'Long run de Złomowo', 'de' => 'Złomowo Langstrecke', 'pt-BR' => 'Corrida longa de Złomowo'],
        'base_lap_ms' => 90_000,
        'fuel_per_lap_ml' => 1_800,
        'reward_cents_per_lap' => 2_000,
        'xp_per_lap' => 30,
    ];

    app()->instance(Rules::class, Rules::fromArray($data));
}

function changeTrack(User $driver, RaceRun $run, array $body = ['track' => 'zlomowo-long'], array $headers = []): TestResponse
{
    return test()->patchJson("/api/v1/runs/{$run->id}", $body, [...bearerFor($driver), ...$headers]);
}

afterEach(fn () => Carbon::setTestNow());

it('requires a token', function () {
    $this->patchJson('/api/v1/runs/1', ['track' => 'zlomowo-street'])->assertUnauthorized();
});

it('pays what the car earned, then freezes the new track and drives it from now', function () {
    rulesWithLongTrack();
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000]);
    clockAt(1_000_000);

    changeTrack($driver, $run, headers: ['X-Locale' => 'pl'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.track.slug', 'zlomowo-long')
        ->assertJsonPath('data.track.name', 'Długi bieg Złomowo')
        ->assertJsonPath('data.lap.ms.dry', 90_000)
        ->assertJsonPath('data.lap.cash_cents.dry', 2_000)
        ->assertJsonPath('data.lap.xp.dry', 30)
        ->assertJsonPath('data.lap.fuel_ml', 1_800)
        ->assertJsonPath('data.laps_done', 13) // driven and paid on the old track
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 1_000_000) // the lap in progress is dropped
        ->assertJsonPath('data.state_version', 2) // the settlement and the change
        ->assertJsonPath('wallet.cash_cents', 13 * 1_550)
        ->assertJsonPath('wallet.xp', 13 * 25);

    $fresh = $run->fresh();

    expect($fresh->track_slug)->toBe('zlomowo-long')
        ->and($fresh->config['lap_ms']['dry'])->toBe(90_000)
        ->and($fresh->seed)->toBe($run->seed);
});

it('can keep the same track and freeze it again', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000]);
    clockAt(1_000_000);

    changeTrack($driver, $run, ['track' => 'zlomowo-street'])
        ->assertOk()
        ->assertJsonPath('data.track.slug', 'zlomowo-street')
        ->assertJsonPath('data.lap.cash_cents.rain', 1_442)
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 1_000_000);
});

it('keeps a stopped car stopped where it stopped', function () {
    rulesWithLongTrack();
    [$driver, $run] = driverWithRun(['fuel_ml' => 3_000]);
    clockAt(3_600_000);

    changeTrack($driver, $run)
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.stop_reason', 'fuel_empty')
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 150_000)
        ->assertJsonPath('data.track.slug', 'zlomowo-long')
        ->assertJsonPath('wallet.cash_cents', 2 * 1_550);
});

it('changes nothing when the same change is sent twice at the same moment', function () {
    rulesWithLongTrack();
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000]);
    clockAt(1_000_000);

    changeTrack($driver, $run)->assertOk();
    changeTrack($driver, $run)->assertOk()->assertJsonPath('wallet.cash_cents', 13 * 1_550);

    expect(LedgerEntry::query()->count())->toBe(1);
});

it('refuses a track that does not exist or is missing', function (array $body) {
    [$driver, $run] = driverWithRun();

    changeTrack($driver, $run, $body)->assertUnprocessable()->assertJsonValidationErrors(['track']);

    expect($run->fresh()->track_slug)->toBe('zlomowo-street')
        ->and($run->fresh()->state_version)->toBe(0);
})->with([
    'unknown track' => [['track' => 'no-such-track']],
    'no track' => [[]],
    'not text' => [['track' => ['zlomowo-street']]],
]);

it('does not know the run of another driver', function () {
    [, $run] = driverWithRun();
    $other = User::factory()->guest()->create();

    changeTrack($other, $run, ['track' => 'zlomowo-street'])->assertNotFound()->assertJsonPath('code', 'no_run');

    expect($run->fresh()->state_version)->toBe(0);
});

it('does not take the car to a track that the level of the driver does not open', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000]);
    clockAt(1_000_000);

    changeTrack($driver, $run, ['track' => 'wilcza-przelecz-switchbacks'], ['X-Locale' => 'en'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'track_locked')
        ->assertJsonPath('message', 'Requires player level 8 (you are level 1). You get XP by driving.')
        ->assertJsonPath('details', ['required' => 8, 'current' => 1]);

    // Nothing was settled or changed: the car drives where it was.
    expect($run->fresh()->track_slug)->toBe('zlomowo-street')->and($run->fresh()->state_version)->toBe(0);
});
