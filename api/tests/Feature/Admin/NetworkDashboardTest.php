<?php

declare(strict_types=1);

use App\Filament\Pages\GameOverview;
use App\Filament\Pages\NetworkDashboard;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Support\AdminLevel;
use App\Filament\Widgets\GameStatsWidget;
use App\Filament\Widgets\GameTilesWidget;
use App\Filament\Widgets\NetworkOverviewWidget;
use App\Games\CurrentGame;
use App\Http\Middleware\SetAdminGame;
use App\Models\Admin;
use App\Models\ContentActivation;
use App\Models\Game;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserVisit;
use App\Services\ContentPacks;
use App\Services\NetworkStats;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

// The home of the panel: the games of the installation as tiles with the numbers of each, the numbers of all of them together, the menu of the network and the menu of a
// game, and the way into a game. The first game is the content of the repository; the others are made here.

const NETWORK_NOW = '2026-10-29 12:00:00';

function networkNow(): CarbonImmutable
{
    return CarbonImmutable::parse(NETWORK_NOW);
}

/** A game of this installation, live unless it is told otherwise. */
function networkGame(string $slug = 'statki', string $name = 'Statki', ?string $domain = 'statki.example.test', string $status = Game::STATUS_LIVE): Game
{
    return Game::query()->create(['slug' => $slug, 'name' => $name, 'domain' => $domain, 'locale' => 'pl', 'theme_color' => '#0b3a5b', 'status' => $status, 'is_default' => false]);
}

/** An account of a game, a guest unless it has been secured, made some days before the date of the tests. */
function networkAccount(Game|int $game, int $daysAgo = 100, bool $secured = false): User
{
    $account = User::factory()->guest()->create(['game_id' => $game instanceof Game ? $game->id : $game, 'created_at' => networkNow()->subDays($daysAgo)]);

    if ($secured) {
        $account->forceFill(['claimed_at' => networkNow()->subDays($daysAgo)])->save();
    }

    return $account;
}

function networkVisit(User $account, string $before): void
{
    UserVisit::query()->create(['user_id' => $account->id, 'at' => networkNow()->sub($before), 'gap_minutes' => null]);
}

function networkPurchase(User $account, int $minor, string $currency, string $before, string $status = Purchase::STATUS_PAID): void
{
    Purchase::query()->create([
        'user_id' => $account->id,
        'product' => 'coins-100',
        'coins' => 100,
        'amount_minor' => $minor,
        'currency' => $currency,
        'provider' => 'stripe',
        'provider_ref' => (string) Str::ulid(),
        'status' => $status,
        'created_at' => networkNow()->sub($before),
    ]);
}

/** An admin who may work in the games given (slugs), and in no other, signed in to the panel. */
function networkAdminOf(array $slugs): Admin
{
    $admin = signedInAdmin(Admin::ROLE_EDITOR, 'crew@example.com');
    $admin->forceFill(['all_games' => false])->save();

    foreach ($slugs as $slug) {
        $admin->games()->attach(Game::query()->where('slug', $slug)->value('id'), ['role' => null]);
    }

    return $admin->refresh();
}

/** What the home says of a game, as the tests read it. @return array<string, mixed> */
function networkStatsOf(Game $game): array
{
    return app(NetworkStats::class)->for(collect([$game]), networkNow())[$game->id];
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');

    // The widgets read the time of the day themselves, so the tests stand still in time and the rows are made relative to it.
    Carbon::setTestNow(networkNow());
    CarbonImmutable::setTestNow(networkNow());
});

describe('what a game has in it, for the home of the panel', function () {
    it('counts the accounts, the guests among them, and the ones made in the last week', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();

        networkAccount($first, daysAgo: 2);
        networkAccount($first, daysAgo: 20);
        networkAccount($first, daysAgo: 40, secured: true);
        networkAccount($second, daysAgo: 0);

        expect(networkStatsOf($first))->toMatchArray(['accounts' => 3, 'guests' => 2, 'secured' => 1, 'new_week' => 1])
            ->and(networkStatsOf($second))->toMatchArray(['accounts' => 1, 'guests' => 1, 'secured' => 0, 'new_week' => 1]);
    });

    it('counts who came back, each account once: the ones with a visit in the last week, and the ones with a visit in the last day', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();

        $often = networkAccount($first);
        networkVisit($often, '3 hours');
        networkVisit($often, '2 days');
        networkVisit(networkAccount($first), '5 days');
        networkVisit(networkAccount($first), '10 days');
        networkAccount($second);

        expect(networkStatsOf($first))->toMatchArray(['active_day' => 1, 'active_week' => 2])
            ->and(networkStatsOf($second))->toMatchArray(['active_day' => 0, 'active_week' => 0]);
    });

    it('adds up what was paid in the currency it was paid in, the last month apart, and leaves out what was paid back', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();
        $buyer = networkAccount($first);

        networkPurchase($buyer, 1500, 'PLN', '3 days');
        networkPurchase($buyer, 2000, 'PLN', '60 days');
        networkPurchase($buyer, 500, 'USD', '1 day');
        networkPurchase($buyer, 900, 'PLN', '2 days', Purchase::STATUS_REFUNDED);
        networkPurchase(networkAccount($second), 700, 'EUR', '5 days');

        expect(networkStatsOf($first)['revenue_total'])->toBe(['PLN' => 3500, 'USD' => 500])
            ->and(networkStatsOf($first)['revenue_month'])->toBe(['PLN' => 1500, 'USD' => 500])
            ->and(networkStatsOf($second)['revenue_total'])->toBe(['EUR' => 700]);
    });

    it('is zeros for a game with nothing in it, and for no games at all', function () {
        $empty = networkGame();

        expect(networkStatsOf($empty))->toBe(['accounts' => 0, 'guests' => 0, 'secured' => 0, 'new_week' => 0, 'active_day' => 0, 'active_week' => 0, 'revenue_total' => [], 'revenue_month' => [], 'pack' => null, 'from_files' => false])
            ->and(app(NetworkStats::class)->for(collect()))->toBe([]);
    });

    it('knows the pack that each game plays: none for a game that has no live pack', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();
        $third = networkGame('rejsy', 'Rejsy', 'rejsy.example.test');

        app(CurrentGame::class)->within($third, fn () => contentPacks()->import(repositoryContent(), checkTables: false, activate: true));

        $live = fn (Game $game): ?string => app(CurrentGame::class)->within($game, fn (): ?string => app(ContentPacks::class)->active()?->number);

        expect($live($first))->not->toBeNull()
            ->and(networkStatsOf($first)['pack'])->toBe($live($first))
            ->and(networkStatsOf($second)['pack'])->toBeNull()
            ->and(networkStatsOf($third)['pack'])->toBe($live($third));
    });

    it('says that the content of the default game is the files of the repository, and of no other game', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();

        expect(networkStatsOf($first)['from_files'])->toBeTrue()
            ->and(networkStatsOf($second)['from_files'])->toBeFalse();

        config(['ride.content_source' => 'database']);

        expect(networkStatsOf($first)['from_files'])->toBeFalse();
    });

    it('adds the games up, the money by currency, and says the money as a line', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();
        networkAccount($first, daysAgo: 1);
        networkAccount($second, daysAgo: 1, secured: true);
        networkPurchase(networkAccount($first), 1250, 'PLN', '1 day');
        networkPurchase(networkAccount($second), 300, 'USD', '1 day');
        networkPurchase(networkAccount($second), 50, 'PLN', '1 day');

        $totals = NetworkStats::totals(app(NetworkStats::class)->for(collect([$first, $second]), networkNow()));

        expect($totals)->toMatchArray(['accounts' => 5, 'guests' => 4, 'secured' => 1, 'new_week' => 2])
            ->and($totals['revenue_month'])->toBe(['PLN' => 1300, 'USD' => 300])
            ->and(NetworkStats::money($totals['revenue_month'], 'none'))->toBe('13,00 PLN + 3,00 USD')
            ->and(NetworkStats::money(['PLN' => 123456, 'USD' => 0], 'none'))->toBe('1 234,56 PLN')
            ->and(NetworkStats::money([], 'none'))->toBe('none');
    });
});

describe('the levels of the panel', function () {
    it('has a network for an admin who may work in every game, and for one who may work in several, and for no other', function () {
        networkGame();
        networkGame('rejsy', 'Rejsy', 'rejsy.example.test');

        signedInAdmin();
        expect(AdminLevel::hasNetwork())->toBeTrue();

        networkAdminOf(['statki', 'rejsy']);
        expect(AdminLevel::hasNetwork())->toBeTrue();

        $one = Admin::query()->where('email', 'crew@example.com')->firstOrFail();
        $one->games()->detach(Game::query()->where('slug', 'rejsy')->value('id'));
        expect(AdminLevel::hasNetwork())->toBeFalse();
    });

    it('puts the home and the games in the network, and everything else in a game', function () {
        signedInAdmin();

        expect(AdminLevel::isNetwork('filament.admin.pages.network-dashboard'))->toBeTrue()
            ->and(AdminLevel::isNetwork('filament.admin.resources.games.index'))->toBeTrue()
            ->and(AdminLevel::isNetwork('filament.admin.resources.games.edit'))->toBeTrue()
            ->and(AdminLevel::isNetwork('filament.admin.pages.game-overview'))->toBeFalse()
            ->and(AdminLevel::isNetwork('filament.admin.resources.vehicles.index'))->toBeFalse()
            ->and(AdminLevel::isNetwork('filament.admin.pages.studio'))->toBeFalse();
    });

    it('has no network level at all for an admin of one game: every page is a page of the game', function () {
        networkGame();
        networkAdminOf(['statki']);

        expect(AdminLevel::isNetwork('filament.admin.pages.network-dashboard'))->toBeFalse()
            ->and(AdminLevel::isNetwork('filament.admin.resources.games.index'))->toBeFalse();
    });

    it('shows the menu of the network on the home: the games are in it and the content of a game is not, and the sidebar says it is the network', function () {
        networkGame();
        signedInAdmin();

        $page = $this->get(NetworkDashboard::getUrl())->assertOk();

        $page->assertSeeHtml('data-test="sidebar-network"')
            ->assertSeeHtml('href="'.GameResource::getUrl().'"')
            ->assertDontSeeHtml('href="'.VehicleResource::getUrl().'"')
            ->assertDontSeeHtml('href="'.GameOverview::getUrl().'"');
    });

    it('shows the menu of the game on its overview: the content is in it, with the way back to the network, and the games are not; the sidebar names the game', function () {
        networkGame();
        signedInAdmin();

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get(GameOverview::getUrl())
            ->assertOk()
            ->assertSeeHtml('data-test="sidebar-game"')
            ->assertSee('Statki')
            ->assertSeeHtml('href="'.VehicleResource::getUrl().'"')
            ->assertSeeHtml('href="'.NetworkDashboard::getUrl().'"')
            ->assertSee(__('admin.network.back'))
            ->assertDontSeeHtml('href="'.GameResource::getUrl().'"');
    });

    it('has no way back to the network in the menu of an admin of one game, who has no network', function () {
        networkGame();
        networkAdminOf(['statki']);

        $this->get(GameOverview::getUrl())
            ->assertOk()
            ->assertSeeHtml('href="'.VehicleResource::getUrl().'"')
            ->assertDontSee(__('admin.network.back'))
            ->assertDontSeeHtml('data-test="sidebar-network"');
    });

    it('takes an admin of one game from the home to the overview of the game', function () {
        networkGame();
        networkAdminOf(['statki']);

        $this->get(NetworkDashboard::getUrl())->assertRedirect(GameOverview::getUrl());
    });
});

describe('the tiles of the games', function () {
    it('are one for each game the admin may enter, with the numbers of the game, and a tile to make a new game for an admin who may work in every game', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();
        networkAccount($first, daysAgo: 3);
        networkAccount($second, daysAgo: 1);
        networkAccount($second, daysAgo: 30);
        networkVisit(networkAccount($second, daysAgo: 2), '1 hour');
        signedInAdmin();

        Livewire::test(GameTilesWidget::class)
            ->assertSeeHtml('data-test="game-tile-raceidle"')
            ->assertSeeHtml('data-test="game-tile-statki"')
            ->assertSeeHtml('data-test="game-tile-create"')
            ->assertSee('statki.example.test')
            ->assertSeeHtml('data-test="enter-statki"')
            ->assertSee(__('admin.network.tile.no_pack'))
            ->assertSee(__('admin.network.stats.no_revenue'));
    });

    it('show the numbers of each game in its own tile, and the status of a game that is only a draft', function () {
        $second = networkGame(status: Game::STATUS_DRAFT);
        networkAccount($second);
        networkAccount($second);
        networkAccount($second, secured: true);
        signedInAdmin();

        Livewire::test(GameTilesWidget::class)
            ->assertSeeInOrder(['game-tile-statki', 'data-test="tile-accounts">3<'], escape: false)
            ->assertSee(__('admin.games.status_draft'));
    });

    it('say where the content of a game comes from when it has no live pack: the files of the repository for the first game, nothing for the others', function () {
        networkGame();
        signedInAdmin();
        ContentActivation::query()->delete();

        Livewire::test(GameTilesWidget::class)
            ->assertSee(__('admin.network.tile.from_files'))
            ->assertSee(__('admin.network.tile.no_pack'));
    });

    it('are only the games that an admin may enter, and there is no tile to make a game for an admin who may not', function () {
        networkGame();
        networkGame('rejsy', 'Rejsy', 'rejsy.example.test');
        networkAdminOf(['statki']);

        Livewire::test(GameTilesWidget::class)
            ->assertSeeHtml('data-test="game-tile-statki"')
            ->assertDontSeeHtml('data-test="game-tile-raceidle"')
            ->assertDontSeeHtml('data-test="game-tile-rejsy"')
            ->assertDontSeeHtml('data-test="game-tile-create"');
    });

    it('take the admin into a game: it is the game of the panel from then on, and its overview is what they see first', function () {
        networkGame();
        signedInAdmin();

        Livewire::test(GameTilesWidget::class)->call('enter', 'statki')->assertRedirect(GameOverview::getUrl());

        expect(session(SetAdminGame::SESSION_KEY))->toBe('statki');
    });

    it('refuse a game that the admin may not enter, and one that is not there, and leave the game of the panel as it was', function () {
        networkGame();
        networkGame('rejsy', 'Rejsy', 'rejsy.example.test');
        networkAdminOf(['statki', 'rejsy']);
        Admin::query()->where('email', 'crew@example.com')->firstOrFail()->games()->detach(Game::query()->where('slug', 'rejsy')->value('id'));

        Livewire::test(GameTilesWidget::class)->call('enter', 'rejsy')->assertForbidden();
        Livewire::test(GameTilesWidget::class)->call('enter', 'no-such-game')->assertForbidden();

        expect(session(SetAdminGame::SESSION_KEY))->toBeNull();
    });
});

describe('the numbers on top of the home and of the overview', function () {
    it('say how many games there are, how many are live, the accounts and the guests, who came back, and what was paid in the last month', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame(status: Game::STATUS_DRAFT);
        $buyer = networkAccount($first, daysAgo: 4);
        networkAccount($second, daysAgo: 60, secured: true);
        networkVisit($buyer, '2 hours');
        networkPurchase($buyer, 4200, 'PLN', '2 days');
        signedInAdmin();

        Livewire::test(NetworkOverviewWidget::class)
            ->assertSee(__('admin.network.stats.games_hint', ['live' => 1, 'draft' => 1]))
            ->assertSee(__('admin.network.stats.accounts_hint', ['guests' => 1]))
            ->assertSee(__('admin.network.stats.active_hint', ['day' => 1]))
            ->assertSee('42,00 PLN');
    });

    it('are the numbers of the game in play on its overview, and not the ones of the others', function () {
        $first = Game::query()->where('is_default', true)->firstOrFail();
        $second = networkGame();

        foreach (range(1, 13) as $number) {
            networkAccount($second, daysAgo: 90);
        }

        networkAccount($first, daysAgo: 90);
        signedInAdmin();
        app(CurrentGame::class)->set($second);

        Livewire::test(GameStatsWidget::class)
            ->assertSee(__('admin.overview.stats.accounts'))
            ->assertSee('13')
            ->assertSee(__('admin.overview.stats.retention_d1'))
            ->assertSee(__('admin.overview.stats.retention_d7'));
    });
});
