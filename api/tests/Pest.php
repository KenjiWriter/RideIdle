<?php

use App\Domain\Run\CarStats;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;
use App\Services\RunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests commit real rows for other processes to see, so no wrapping transaction.
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function runService(): RunService
{
    return new RunService(new Ledger());
}

/** @param  array<string, mixed>  $attributes */
function aRun(array $attributes = []): RaceRun
{
    return RaceRun::factory()->create($attributes);
}

/**
 * A run whose driver already has some money to spend.
 *
 * @param  array<string, mixed>  $attributes
 */
function fundedRun(int $cents, array $attributes = []): RaceRun
{
    $run = aRun($attributes);

    if ($cents > 0) {
        (new Ledger())->post($run->user_id, $cents, 'gift', 'seed-money:'.$run->id);
    }

    return $run;
}

/**
 * The headers of a device token for a driver, to call the API as that driver.
 * The guards forget the user of an earlier request, which a test running several drivers in one process needs.
 *
 * @return array<string, string>
 */
function bearerFor(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

/** A moment on a segment boundary of the weather, so the numbers are those of the settlement vector W1. */
const RUN_T0 = 1_792_692_000_000;

function clockAt(int $msAfterT0): void
{
    Carbon::setTestNow(Carbon::createFromTimestampMs(RUN_T0 + $msAfterT0));
}

/** A run with a constant 75 s lap that pays $15.50 and 25 XP, started at T0 with 45 l (30 laps). */
function driverWithRun(array $attributes = []): array
{
    clockAt(0);
    $driver = User::factory()->guest()->create();
    $run = RaceRun::factory()->create([...['user_id' => $driver->id, 'cursor_ms' => RUN_T0], ...$attributes]);

    return [$driver, $run];
}

function getRun(User $driver, string $path = 'current', array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->getJson("/api/v1/runs/{$path}", [...bearerFor($driver), ...$headers]);
}

function identityOf(User $user, string $provider, string $subject, ?string $email = null): AccountIdentity
{
    return AccountIdentity::query()->create(['user_id' => $user->id, 'provider' => $provider, 'subject' => $subject, 'email' => $email]);
}

function tokenWith(array $attributes = []): AccountToken
{
    return AccountToken::query()->create([
        'purpose' => AccountToken::CLAIM,
        'token_hash' => hash('sha256', bin2hex(random_bytes(8))),
        'expires_at' => now()->addMinutes(30),
        ...$attributes,
    ]);
}

function askForEmailLink(User $driver, array $body = [], array $headers = []): Illuminate\Testing\TestResponse
{
    $body = ['email' => 'turbo@example.com', 'accept_terms' => true, 'confirm_age' => true, ...$body];

    return test()->postJson('/api/v1/account/email', $body, [...bearerFor($driver), ...$headers]);
}

/** The secret in the link of the e-mail that was sent. */
function secretOf(AccountLinkMail $mail): string
{
    parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

    return $query['token'];
}

function confirmLink(string $secret, array $headers = []): Illuminate\Testing\TestResponse
{
    // Public: the link is opened in any browser, with no device token.
    return test()->postJson('/api/v1/account/email/confirm', ['token' => $secret], $headers);
}

/** Asks for a claim link for the guest and returns its secret. */
function claimSecretFor(User $guest, string $email = 'turbo@example.com'): string
{
    askForEmailLink($guest, ['email' => $email])->assertStatus(202);

    return secretOf(Mail::queued(AccountLinkMail::class)->last());
}

function cashOf(RaceRun $run): int
{
    return (new Ledger())->balance($run->user_id);
}

/** Dry weather only, so the lap time is the same everywhere and the arithmetic stays readable. */
function dryInputs(float $stat = 100.0, int $rewardCents = 2_000, int $breakChancePpm = 0): RunInputs
{
    return new RunInputs(
        car: new CarStats(['grip' => $stat, 'power' => $stat]),
        track: new TrackProfile(['grip' => 0.5, 'power' => 0.5], ['grip' => 100.0, 'power' => 100.0], 75_000, 1_500, $rewardCents, 30),
        weatherChancesPpm: ['dry' => 1_000_000],
        weatherEffects: [],
        climateSeed: 'region-zlomowo',
        breakChancePerLapPpm: $breakChancePpm,
    );
}

function lapMsOf(RaceRun $run): int
{
    return $run->config['lap_ms']['dry'];
}
