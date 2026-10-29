<?php

declare(strict_types=1);

use App\Filament\Pages\GameOverview;
use App\Filament\Pages\NetworkDashboard;
use App\Filament\Resources\Players\Pages\ListPlayers;
use App\Filament\Resources\Players\PlayerResource;
use App\Games\CurrentGame;
use App\Http\Middleware\SetAdminGame;
use App\Models\AccountIdentity;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Car;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlayersCsv;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

// The accounts of a game in the panel: the list with its numbers, search, filters and order, the page of one account, the CSV file, and who may see any of it.

const PLAYERS_NOW = '2026-10-29 12:00:00';

beforeEach(function () {
    Carbon::setTestNow(PLAYERS_NOW);
    app()->setLocale('en');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A game of this installation with the pack of the repository live (or with none), which the panel is then switched to. */
function playersGame(string $slug = 'statki', bool $withPack = true): Game
{
    $game = Game::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'domain' => "{$slug}.example.test", 'locale' => 'pl', 'theme_color' => '#0b3a5b', 'status' => Game::STATUS_LIVE, 'is_default' => false]);

    if ($withPack) {
        app(CurrentGame::class)->within($game, fn () => contentPacks()->import(repositoryContent(), '2026.10.30', activate: true, checkTables: false));
    }

    app(CurrentGame::class)->set($game);

    return $game;
}

/**
 * An account of the game that is open, with a wallet.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, int>  $wallet
 */
function playersAccount(string $name, array $attributes = [], array $wallet = [], bool $secured = false): User
{
    $account = User::factory()->guest()->create(['name' => $name, 'created_at' => CarbonImmutable::parse(PLAYERS_NOW)->subDays(100), 'last_seen_at' => null, ...$attributes]);

    if ($secured) {
        $account->forceFill(['claimed_at' => $account->created_at])->save();
    }

    Wallet::query()->create(['user_id' => $account->id, 'cash_cents' => 0, 'xp' => 0, 'premium_coins' => 0, ...$wallet]);

    return $account;
}

function playersSeen(User $account, string $before): User
{
    $account->forceFill(['last_seen_at' => CarbonImmutable::parse(PLAYERS_NOW)->sub($before)])->save();

    return $account;
}

function playersPurchase(User $account, string $status = Purchase::STATUS_PAID, int $minor = 1999): Purchase
{
    return Purchase::query()->create(['user_id' => $account->id, 'product' => 'coins-100', 'coins' => 100, 'amount_minor' => $minor, 'currency' => 'PLN', 'provider' => 'stripe', 'provider_ref' => (string) Str::ulid(), 'status' => $status]);
}

/** @return list<array<int, string|null>> the lines of the file that the writer makes for the accounts of the game that is open, without the mark at the beginning */
function playersCsvLines(): array
{
    $stream = fopen('php://temp', 'w+');
    app(PlayersCsv::class)->write(PlayerResource::getEloquentQuery()->orderBy('users.id'), $stream);
    rewind($stream);
    $text = stream_get_contents($stream);
    fclose($stream);

    expect(substr($text, 0, 3))->toBe("\xEF\xBB\xBF");

    $lines = [];

    foreach (str_getcsv(substr($text, 3), "\n", '"', '') as $line) {
        $lines[] = str_getcsv($line, ',', '"', '');
    }

    return $lines;
}

function playersPublisher(): Admin
{
    return signedInAdmin(Admin::ROLE_PUBLISHER);
}

describe('the list', function () {
    it('shows the accounts of the game that is open and no others', function () {
        playersPublisher();
        $first = playersAccount('Firstdriver');
        $ships = playersGame();
        $own = playersAccount('Shipdriver');

        Livewire::test(ListPlayers::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$first]);

        app(CurrentGame::class)->set(Game::query()->where('is_default', true)->firstOrFail());

        Livewire::test(ListPlayers::class)
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$own]);

        expect($ships->slug)->toBe('statki');
    });

    it('gives the level of the experience, the cash, the cars, the paid purchases and the e-mail of a secured account', function () {
        playersPublisher();
        playersGame();
        $driver = playersAccount('Anna', ['email' => null], ['xp' => 0, 'cash_cents' => 123456, 'premium_coins' => 7], secured: true);
        AccountIdentity::query()->create(['user_id' => $driver->id, 'provider' => AccountIdentity::EMAIL, 'subject' => 'anna@example.test', 'email' => 'anna@example.test']);
        Car::factory()->count(2)->create(['user_id' => $driver->id]);
        playersPurchase($driver);
        playersPurchase($driver, Purchase::STATUS_REFUNDED);
        $guest = playersAccount('Guestie');

        Livewire::test(ListPlayers::class)
            ->assertTableColumnStateSet('email', 'anna@example.test', $driver)
            ->assertTableColumnStateSet('level', 1, $driver)
            ->assertTableColumnStateSet('cash_cents', '$1 234,56', $driver)
            ->assertTableColumnStateSet('premium_coins', 7, $driver)
            ->assertTableColumnStateSet('cars_count', 2, $driver)
            ->assertTableColumnStateSet('paid_purchases_count', 1, $driver)
            ->assertTableColumnStateSet('account', 'secured', $driver)
            ->assertTableColumnStateSet('account', 'guest', $guest)
            ->assertTableColumnStateSet('email', null, $guest);
    });

    it('searches by name, by the e-mail of the account and by the e-mail of a way of signing in', function () {
        playersPublisher();
        playersGame();
        $zed = playersAccount('Zedrunner', ['email' => 'zed@example.test'], secured: true);
        $ida = playersAccount('Idadriver', ['email' => null], secured: true);
        AccountIdentity::query()->create(['user_id' => $ida->id, 'provider' => AccountIdentity::GOOGLE, 'subject' => 'g-1', 'email' => 'ida@google.test']);
        $other = playersAccount('Otherguy');

        Livewire::test(ListPlayers::class)
            ->searchTable('Zedrun')->assertCanSeeTableRecords([$zed])->assertCanNotSeeTableRecords([$ida, $other])
            ->searchTable('zed@example')->assertCanSeeTableRecords([$zed])->assertCanNotSeeTableRecords([$ida, $other])
            ->searchTable('ida@google')->assertCanSeeTableRecords([$ida])->assertCanNotSeeTableRecords([$zed, $other]);
    });

    it('filters by the kind of the account', function () {
        playersPublisher();
        playersGame();
        $secured = playersAccount('Secure', secured: true);
        $guest = playersAccount('Guestie');

        Livewire::test(ListPlayers::class)
            ->filterTable('account', 'guest')->assertCanSeeTableRecords([$guest])->assertCanNotSeeTableRecords([$secured])
            ->filterTable('account', 'secured')->assertCanSeeTableRecords([$secured])->assertCanNotSeeTableRecords([$guest]);
    });

    it('filters by how long ago the account was seen: the last day, the last week, or not for thirty days', function () {
        playersPublisher();
        playersGame();
        $today = playersSeen(playersAccount('Today'), '3 hours');
        $week = playersSeen(playersAccount('Thisweek'), '5 days');
        $old = playersSeen(playersAccount('Longago'), '45 days');
        $never = playersAccount('Never');

        Livewire::test(ListPlayers::class)
            ->filterTable('activity', 'day')->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$week, $old, $never])
            ->filterTable('activity', 'week')->assertCanSeeTableRecords([$today, $week])->assertCanNotSeeTableRecords([$old, $never])
            ->filterTable('activity', 'inactive')->assertCanSeeTableRecords([$old, $never])->assertCanNotSeeTableRecords([$today, $week]);
    });

    it('filters the accounts made in the last seven days, those with a paid purchase, and those of a language', function () {
        playersPublisher();
        playersGame();
        $fresh = playersAccount('Fresh', ['created_at' => CarbonImmutable::parse(PLAYERS_NOW)->subDays(2), 'locale' => 'de']);
        $payer = playersAccount('Payer', ['locale' => 'pl']);
        playersPurchase($payer);
        $refunded = playersAccount('Refunded', ['locale' => 'pl']);
        playersPurchase($refunded, Purchase::STATUS_REFUNDED);

        Livewire::test(ListPlayers::class)
            ->filterTable('new_week', true)->assertCanSeeTableRecords([$fresh])->assertCanNotSeeTableRecords([$payer, $refunded])
            ->resetTableFilters()
            ->filterTable('paid', true)->assertCanSeeTableRecords([$payer])->assertCanNotSeeTableRecords([$fresh, $refunded])
            ->filterTable('paid', false)->assertCanSeeTableRecords([$fresh, $refunded])->assertCanNotSeeTableRecords([$payer])
            ->resetTableFilters()
            ->filterTable('locale', 'de')->assertCanSeeTableRecords([$fresh])->assertCanNotSeeTableRecords([$payer, $refunded]);
    });

    it('sorts by level (the experience), by cash and by the number of cars', function () {
        playersPublisher();
        playersGame();
        $low = playersAccount('Lowxp', wallet: ['xp' => 10, 'cash_cents' => 900]);
        $high = playersAccount('Highxp', wallet: ['xp' => 90_000, 'cash_cents' => 100]);
        Car::factory()->count(3)->create(['user_id' => $low->id]);
        Car::factory()->create(['user_id' => $high->id]);

        Livewire::test(ListPlayers::class)
            ->sortTable('level', 'desc')->assertCanSeeTableRecords([$high, $low], inOrder: true)
            ->sortTable('level', 'asc')->assertCanSeeTableRecords([$low, $high], inOrder: true)
            ->sortTable('cash_cents', 'desc')->assertCanSeeTableRecords([$low, $high], inOrder: true)
            ->sortTable('cars_count', 'desc')->assertCanSeeTableRecords([$low, $high], inOrder: true);
    });
});

describe('a game that has no live pack yet', function () {
    it('lists its accounts without a level, because it has no rules to say one', function () {
        playersPublisher();
        playersGame('empty', withPack: false);
        $driver = playersAccount('Anna', wallet: ['xp' => 500]);

        Livewire::test(ListPlayers::class)
            ->assertCanSeeTableRecords([$driver])
            ->assertTableColumnStateSet('level', '-', $driver);
    });

    it('has a page for an account and a file, which leave out what only the rules could say', function () {
        playersPublisher();
        playersGame('empty', withPack: false);
        $driver = playersAccount('Anna', wallet: ['xp' => 500]);
        // The factory of a car asks the rules for the level a car starts at, and this game has none.
        Car::query()->create(['user_id' => $driver->id, 'vehicle_slug' => 'rdzak-gt-4', 'level' => 1, 'xp' => 0]);

        $this->withSession([SetAdminGame::SESSION_KEY => 'empty'])->get(PlayerResource::getUrl('view', ['record' => $driver]))->assertOk()->assertSee('rdzak-gt-4');

        expect(array_column(playersCsvLines(), 4))->toBe(['level', '']);
    });
});

describe('who sees it', function () {
    it('is for a publisher of the game, and not for an editor', function () {
        playersGame();
        signedInAdmin(Admin::ROLE_EDITOR);

        expect(PlayerResource::canViewAny())->toBeFalse();

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(PlayerResource::getUrl())->assertForbidden();
    });

    it('is in the menu of a game', function () {
        playersGame();
        playersPublisher();

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(GameOverview::getUrl())->assertOk()->assertSeeHtml('href="'.PlayerResource::getUrl().'"');
    });

    it('is not in the menu of the network', function () {
        playersGame();
        playersPublisher();

        $this->get(NetworkDashboard::getUrl())->assertOk()->assertDontSeeHtml('href="'.PlayerResource::getUrl().'"');
    });

    it('lets nobody change an account', function () {
        playersGame();
        playersPublisher();
        $driver = playersAccount('Anna');

        expect(PlayerResource::canCreate())->toBeFalse()
            ->and(PlayerResource::canEdit($driver))->toBeFalse()
            ->and(PlayerResource::canDelete($driver))->toBeFalse()
            ->and(PlayerResource::canDeleteAny())->toBeFalse();
    });
});

describe('the page of an account', function () {
    it('tells who it is, how far it got, and lists its cars, its purchases and its ledger', function () {
        playersGame();
        playersPublisher();
        $driver = playersAccount('Anna', ['email' => 'anna@example.test', 'recovery_code_hash' => 'SECRET-HASH-VALUE'], ['xp' => 0, 'cash_cents' => 5000, 'premium_coins' => 12], secured: true);
        $car = Car::factory()->create(['user_id' => $driver->id, 'mileage_m' => 12_345, 'value_cents' => 150_000]);
        $driver->forceFill(['selected_car_id' => $car->id])->save();
        playersPurchase($driver, minor: 4999);
        LedgerEntry::query()->create(['user_id' => $driver->id, 'currency' => 'cash', 'delta' => 2500, 'balance_after' => 5000, 'reason' => 'lap', 'idem_key' => 'players-1']);
        LedgerEntry::query()->create(['user_id' => $driver->id, 'currency' => 'premium', 'delta' => -3, 'balance_after' => 12, 'reason' => 'speedup', 'idem_key' => 'players-2']);

        $page = $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(PlayerResource::getUrl('view', ['record' => $driver]))->assertOk();

        $page->assertSee('Anna')
            ->assertSee('anna@example.test')
            ->assertSee('$50,00')
            ->assertSee('12,3 km')
            ->assertSee('$1 500,00')
            ->assertSee('49,99 PLN')
            ->assertSee('lap')
            ->assertSee('+$25,00', false)
            ->assertSee('-3', false)
            ->assertSee('data-test="player-cars"', false)
            ->assertSee('data-test="player-purchases"', false)
            ->assertSee('data-test="player-ledger"', false)
            ->assertDontSee('SECRET-HASH-VALUE');
    });

    it('says so when the account has no cars, purchases or postings', function () {
        playersGame();
        playersPublisher();
        $driver = playersAccount('Emptyone');

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(PlayerResource::getUrl('view', ['record' => $driver]))
            ->assertOk()
            ->assertSee('data-test="no-cars"', false)
            ->assertSee('data-test="no-purchases"', false)
            ->assertSee('data-test="no-ledger"', false);
    });

    it('is not there for an account of another game', function () {
        $first = playersAccount('Firstdriver');
        playersGame();
        playersPublisher();

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(PlayerResource::getUrl('view', ['record' => $first]))->assertNotFound();
    });

    it('is not for an editor', function () {
        playersGame();
        signedInAdmin(Admin::ROLE_EDITOR);
        $driver = playersAccount('Anna');

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(PlayerResource::getUrl('view', ['record' => $driver]))->assertForbidden();
    });
});

describe('the CSV file', function () {
    it('has a header that is the same in every language and one line for each account of the game', function () {
        playersGame();
        $driver = playersAccount('Anna', ['email' => 'anna@example.test', 'locale' => 'pl'], ['xp' => 0, 'cash_cents' => 123456, 'premium_coins' => 7], secured: true);
        Car::factory()->create(['user_id' => $driver->id]);
        playersPurchase($driver);
        app()->setLocale('de');

        $lines = playersCsvLines();

        expect($lines[0])->toBe(PlayersCsv::HEADER)
            ->and($lines)->toHaveCount(2)
            ->and($lines[1])->toBe([(string) $driver->id, 'Anna', 'secured', 'anna@example.test', '1', '0', '1234.56', '7', '1', '1', 'pl', '2026-07-21T12:00:00Z', '']);
    });

    it('has nothing in it with which an account could be signed in to', function () {
        expect(PlayersCsv::HEADER)->not->toContain('password')->not->toContain('recovery_code_hash')->not->toContain('remember_token');
    });

    it('does not carry the accounts of another game', function () {
        $first = playersAccount('Firstdriver');
        playersGame();
        $own = playersAccount('Shipdriver');

        $names = array_column(array_slice(playersCsvLines(), 1), 1);

        expect($names)->toBe(['Shipdriver'])->and($names)->not->toContain($first->name);
        expect($own->id)->not->toBe($first->id);
    });

    it('does not let a name or an e-mail be taken for a formula by a spreadsheet', function () {
        playersGame();
        playersAccount('=1+1', ['email' => '+x@example.test']);
        playersAccount('@sum', ['email' => '-y@example.test']);
        playersAccount('Plain', ['email' => 'plain@example.test']);

        $lines = array_slice(playersCsvLines(), 1);

        expect(array_column($lines, 1))->toBe(["'=1+1", "'@sum", 'Plain'])
            ->and(array_column($lines, 3))->toBe(["'+x@example.test", "'-y@example.test", 'plain@example.test']);
    });

    it('is taken from the list: the file has the accounts that the filters leave, and the trail says who took it', function () {
        $admin = playersPublisher();
        playersGame();
        playersAccount('Secure', secured: true);
        playersAccount('Guestie');
        playersAccount('Guestino');

        Livewire::test(ListPlayers::class)
            ->filterTable('account', 'guest')
            ->callAction('csv')
            ->assertFileDownloaded('players-statki-20261029-120000.csv');

        $entry = AdminAuditLog::query()->where('action', 'players_exported')->sole();

        expect($entry->email)->toBe($admin->email)
            ->and($entry->subject)->toBe('game:statki')
            ->and($entry->context)->toBe(['players' => 2, 'filters' => ['account' => 'guest'], 'searched' => false]);
    });

    it('writes in the trail that a search was used, and not what was searched for', function () {
        playersPublisher();
        playersGame();
        playersAccount('Anna', ['email' => 'anna@example.test']);

        Livewire::test(ListPlayers::class)->searchTable('anna@example')->callAction('csv');

        $entry = AdminAuditLog::query()->where('action', 'players_exported')->sole();

        expect($entry->context['searched'])->toBeTrue()
            ->and(json_encode($entry->context))->not->toContain('anna@example');
    });
});
