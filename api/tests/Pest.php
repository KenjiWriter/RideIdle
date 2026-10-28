<?php

use App\Content\FileContentSource;
use App\Content\PackContent;
use App\Content\Rules;
use App\Domain\Economy\EconomyRules;
use App\Domain\Run\CarStats;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\Admin;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use App\Services\ContentPacks;
use App\Services\Ledger;
use App\Services\RunService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
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

// The suite reads the content from the files of the repository. TEST_CONTENT_SOURCE=database makes it read the same content from a pack in the database, to show
// that the two are the same game (the tests of the packs themselves, which count packs, are not meant for this).
pest()->beforeEach(function (): void {
    if (env('TEST_CONTENT_SOURCE') === 'database') {
        contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
        readFromDatabase();
    }
})->in('Feature');

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
    return new RunService(new Ledger);
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
        (new Ledger)->post($run->user_id, $cents, 'gift', 'seed-money:'.$run->id);
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

/**
 * The rules of this test are the real ones, but a workshop with no tire machine asks no fee of the vulcanizer. The tests of the queue and the prices of the
 * workshop are not about that fee, and a driver of theirs has no cash for it; WorkshopEquipmentTest has the real one.
 */
function withoutTireFee(): void
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
    unset($data['workshop']['equipment']['tire_machine']['levels'][0]['fee_cents']);

    app()->singleton(Rules::class, fn () => Rules::fromArray($data));
}

/**
 * The rules with every drive as neutral as the reference one and none with a power limit, which is what the game was before the drives: for the tests that
 * check the numbers of the tracks and of the economy apart from them. @return array<string, mixed>
 */
function rulesWithoutDrives(): array
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach (array_keys($data['drivetrain']['types']) as $kind) {
        $data['drivetrain']['types'][$kind] = $data['drivetrain']['types']['fwd'];
        unset($data['drivetrain']['types'][$kind]['power_limit']);
    }

    return $data;
}

/** A run with a constant 75 s lap that pays $15.50 and 25 XP, started at T0 with 45 l (30 laps). */
function driverWithRun(array $attributes = []): array
{
    clockAt(0);
    $driver = User::factory()->guest()->create();
    $run = RaceRun::factory()->create([...['user_id' => $driver->id, 'cursor_ms' => RUN_T0], ...$attributes]);

    return [$driver, $run];
}

function getRun(User $driver, string $path = 'current', array $headers = []): TestResponse
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

function askForEmailLink(User $driver, array $body = [], array $headers = []): TestResponse
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

function confirmLink(string $secret, array $headers = []): TestResponse
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

/** Gives a provider the keys it needs, and takes them from the others. */
function withProviders(array $keys): void
{
    foreach (AccountIdentity::SOCIAL as $provider) {
        config(["services.{$provider}" => [...($keys[$provider] ?? []), 'redirect' => "https://api.example/api/v1/auth/{$provider}/callback"]]);
    }
}

function providerKeys(): array
{
    return [
        'google' => ['client_id' => 'google-id', 'client_secret' => 'google-secret'],
        'facebook' => ['client_id' => 'facebook-id', 'client_secret' => 'facebook-secret'],
        'apple' => ['client_id' => 'com.example.ride', 'client_secret' => 'apple-signed-token'],
    ];
}

/** Starts linking a provider to the account of the driver. */
function startProviderLink(User $driver, string $provider, array $body = [], array $headers = []): TestResponse
{
    $body = ['accept_terms' => true, 'confirm_age' => true, ...$body];

    return test()->postJson("/api/v1/account/identities/{$provider}/start", $body, [...bearerFor($driver), ...$headers]);
}

/** Starts signing in with a provider on a device that has no account. */
function startProviderLogin(string $provider, array $headers = []): TestResponse
{
    return test()->postJson("/api/v1/auth/{$provider}/start", [], $headers);
}

/** The state a provider is sent back with, read from the address the browser was sent to. */
function stateOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

/** What the provider will say about the person who comes back from it. */
function providerSays(string $provider, array $person = []): void
{
    Socialite::fake($provider, SocialiteUser::fake($person));
}

/** The browser comes back from the provider: Google and Facebook with a GET, Apple with a form post. */
function comesBackFrom(string $provider, string $state, array $query = [], string $method = 'get'): TestResponse
{
    $params = ['state' => $state, 'code' => 'code-from-provider', ...$query];

    return $method === 'post'
        ? test()->post("/api/v1/auth/{$provider}/callback", $params)
        : test()->get("/api/v1/auth/{$provider}/callback?".http_build_query($params));
}

/** The fields the web app finds in the fragment of the address the browser was sent on to. */
function fragmentOf(TestResponse $response): array
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_FRAGMENT), $fields);

    return $fields;
}

/**
 * Goes through the provider as far as the callback and returns what the browser holds then: the ticket from the
 * fragment and the verifier it was given when it started. A driver given links the provider to their account, none
 * signs in.
 *
 * @param  array<string, mixed>  $person  what the provider says about the person
 * @return array{ticket: string, verifier: string}
 */
function providerTicket(string $provider, ?User $driver = null, array $person = []): array
{
    withProviders(providerKeys());
    config(['ride.web_url' => 'https://web.example']);

    $start = $driver === null ? startProviderLogin($provider) : startProviderLink($driver, $provider);
    $start->assertOk();

    providerSays($provider, $person);

    return ['ticket' => fragmentOf(comesBackFrom($provider, stateOf($start->json('url'))))['ticket'], 'verifier' => $start->json('verifier')];
}

/** The browser finishes the flow. It may be signed in as a driver, or have no account. */
function finishWith(array $held, ?User $driver = null, array $body = []): TestResponse
{
    // A request without a token must not find the driver of an earlier request of the test in a cached guard.
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/complete', [...$held, ...$body], $driver === null ? [] : bearerFor($driver));
}

/** The export of an account, as the command prints it. */
function exported(array $options): array
{
    expect(Artisan::call('accounts:export', $options))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** An account that has a little of everything the game keeps. */
function accountWithHistory(): User
{
    $user = User::factory()->create([
        'name' => 'Maciek_Turbo',
        'name_key' => 'maciek_turbo',
        'email' => 'turbo@example.com',
        'locale' => 'pl',
        'terms_accepted_at' => now()->subDays(3),
        'terms_version' => '2026-10-23',
        'age_confirmed_at' => now()->subDays(3),
        'recovery_code_hash' => str_repeat('a', 64),
        'recovery_code_set_at' => now()->subDay(),
    ]);
    identityOf($user, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');
    identityOf($user, AccountIdentity::GOOGLE, '1098765', 'turbo@gmail.com');
    $user->createToken('google-login');

    $run = aRun(['user_id' => $user->id]);

    // A part on the car, a spare one in the inventory, and a job that waits for the mechanic.
    Part::factory()->state(['part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust'])->mountedOn($run->car)->create();
    $spare = Part::factory()->create(['user_id' => $user->id]);
    WorkshopJob::factory()->create(['user_id' => $user->id, 'car_id' => $run->car_id, 'part_id' => $spare->id]);
    TutorialProgress::factory()->create(['user_id' => $user->id]);
    CityGarage::query()->create(['user_id' => $user->id, 'region' => 'zlomowo', 'capacity' => 3]);

    $settlement = Settlement::query()->create([
        'race_run_id' => $run->id,
        'state_version' => 1,
        'cursor_from_ms' => 0,
        'cursor_to_ms' => 75_000,
        'now_ms' => 75_000,
        'laps' => 1,
        'cash_cents' => 1_550,
        'xp' => 25,
        'created_at' => now(),
    ]);
    SettlementEvent::query()->create(['settlement_id' => $settlement->id, 'seq' => 0, 'type' => 'lap_batch', 'at_ms' => 75_000, 'payload' => ['laps' => 1]]);
    (new Ledger)->post($user->id, 1_550, 'laps', 'export-test:'.$user->id);

    return $user;
}

/** What a table holds for the people it is about. */
function rowsOf(): array
{
    return [
        'users' => User::query()->count(),
        'identities' => AccountIdentity::query()->count(),
        'devices' => PersonalAccessToken::query()->count(),
        'links' => AccountToken::query()->count(),
        'wallets' => Wallet::query()->count(),
        'cars' => Car::query()->count(),
        'parts' => Part::query()->count(),
        'garages' => CityGarage::query()->count(),
        'jobs' => WorkshopJob::query()->count(),
        'runs' => RaceRun::query()->count(),
        'settlements' => Settlement::query()->count(),
        'events' => SettlementEvent::query()->count(),
        'ledger' => LedgerEntry::query()->count(),
    ];
}

function cashOf(RaceRun $run): int
{
    return (new Ledger)->balance($run->user_id);
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

/**
 * The anchors of the economy as the repository keeps them (content/rules/economy.json), read straight from the file so
 * that a test of the pure domain needs no framework.
 *
 * @return array<string, mixed>
 */
function economyData(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/content/rules/economy.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The ruleset of the economy with some of its values changed: a value is set by the keys of the path, so that
 * `economyRules(['knobs', 'reward_mult'], 0.7)` turns the knob of the rewards.
 *
 * @param  list<string|int>  $path
 */
function economyRules(array $path = [], mixed $value = null): EconomyRules
{
    $data = economyData();

    if ($path !== []) {
        $node = &$data;

        foreach ($path as $key) {
            $node = &$node[$key];
        }

        $node = $value;
    }

    return EconomyRules::fromArray($data);
}

// The content of the game as documents: the repository, a pack, and the packs of the database (ContentSourceTest, ContentPacksTest, ContentCommandsTest).

function repositoryContent(): FileContentSource
{
    return new FileContentSource((string) config('ride.content_path'));
}

/**
 * The text of every document of the repository by key, the manifest as `pack`: what a pack holds.
 *
 * @return array<string, string>
 */
function repositoryDocuments(): array
{
    $files = repositoryContent();
    $documents = ['pack' => (string) $files->raw('pack')];

    foreach ($files->keys() as $key) {
        $documents[$key] = (string) $files->raw($key);
    }

    return $documents;
}

/**
 * The content of the repository with some documents changed: each change is a function from the decoded document to the new one, and a key that is not there
 * yet is made out of nothing.
 *
 * @param  array<string, Closure(array<string, mixed>): array<string, mixed>>  $changes
 * @param  list<string>  $removed  the keys of the documents to leave out
 */
function contentWith(array $changes, array $removed = []): PackContent
{
    $documents = array_diff_key(repositoryDocuments(), array_flip($removed));

    foreach ($changes as $key => $change) {
        $document = isset($documents[$key]) ? contentArray(json_decode($documents[$key])) : [];
        $documents[$key] = json_encode($change($document), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    return new PackContent('2026.10.0', $documents);
}

/** A decoded document as arrays, except the empty objects, which stay objects so that they are written back as {} and not as []. */
function contentArray(mixed $value): mixed
{
    if (is_object($value)) {
        return (array) $value === [] ? $value : array_map('contentArray', (array) $value);
    }

    return is_array($value) ? array_map('contentArray', $value) : $value;
}

function contentPacks(): ContentPacks
{
    return app(ContentPacks::class);
}

/** The Rdzak GT-4 with another power: a change that every lap of a run shows. */
function stronger(int $powerKw = 150): array
{
    return ['vehicles/rdzak-gt-4' => fn (array $vehicle): array => [...$vehicle, 'power_kw' => $powerKw]];
}

/** The game reads from the database from here on, and the container is asked again for what it has read. */
function readFromDatabase(): void
{
    config(['ride.content_source' => 'database']);
    app()->forgetScopedInstances();
}

// The panel of the people who run the content (AdminAccountsTest, AdminPanelTest).

function anAdmin(array $attributes = []): Admin
{
    return Admin::query()->create([
        'name' => 'Boss',
        'email' => 'boss@example.com',
        'password' => 'a-long-enough-password',
        ...$attributes,
    ]);
}

/** An admin who has set up the authenticator app, which is what the panel asks of everyone before it shows anything. */
function signedInAdmin(string $role = Admin::ROLE_EDITOR, string $email = 'boss@example.com'): Admin
{
    $admin = anAdmin(['email' => $email, 'role' => $role]);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $admin->saveAppAuthenticationRecoveryCodes(['abcd-1234']);
    test()->actingAs($admin, 'admin');
    Filament::setCurrentPanel('admin');

    return $admin;
}

/** @return array<string, mixed> a document of the repository */
function repositoryDocument(string $key): array
{
    return json_decode((string) repositoryContent()->raw($key), true);
}
