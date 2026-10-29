<?php

declare(strict_types=1);

use App\Filament\Livewire\GameSwitcher;
use App\Filament\Pages\GameOverview;
use App\Filament\Resources\AdminAuditLogs\Pages\ManageAdminAuditLogs;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Widgets\LivePackWidget;
use App\Games\CurrentGame;
use App\Http\Middleware\SetAdminGame;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Game;
use App\Services\AdminAudit;
use Livewire\Livewire;

// One panel for several games: who may work in which, which one the panel is working in, what it lists and saves there, the games themselves, and what is written
// down. The content of the repository is the first game; the second one is made here.

/** A second game, live, with a domain of its own. */
function theSecondGame(array $attributes = []): Game
{
    return Game::query()->create([
        'slug' => 'statki',
        'name' => 'Statki',
        'domain' => 'statki.example.test',
        'locale' => 'pl',
        'theme_color' => '#0b3a5b',
        'status' => Game::STATUS_LIVE,
        'is_default' => false,
        ...$attributes,
    ]);
}

/** The content of the repository as the second game has it, with the first car under another name, live in that game. */
function contentOfTheSecondGame(Game $game): void
{
    app(CurrentGame::class)->within($game, fn () => contentPacks()->import(
        contentWith(['vehicles/rdzak-gt-4' => fn (array $car): array => [...$car, 'name' => ['en' => 'Ship One', 'pl' => 'Statek Jeden']]]),
        checkTables: false,
        activate: true,
    ));
}

/** An admin who may work in the games that are given only (slug => role in it, or null for their own), signed in to the panel. */
function anAdminOf(array $games, string $role = Admin::ROLE_EDITOR, string $email = 'crew@example.com'): Admin
{
    $admin = signedInAdmin($role, $email);
    $admin->forceFill(['all_games' => false])->save();

    foreach ($games as $slug => $roleInGame) {
        $admin->games()->attach(Game::query()->where('slug', $slug)->value('id'), ['role' => $roleInGame]);
    }

    return $admin->refresh();
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
});

describe('the games an admin may work in', function () {
    it('are all of them for an admin who may work in every game, and the ones that were given for any other', function () {
        $second = theSecondGame();
        $everywhere = signedInAdmin();
        $crew = anAdminOf(['statki' => Admin::ROLE_PUBLISHER], email: 'crew@example.com');

        expect($everywhere->allowedGames()->pluck('slug')->all())->toBe(['raceidle', 'statki'])
            ->and($everywhere->canEnter($second))->toBeTrue()
            ->and($crew->allowedGames()->pluck('slug')->all())->toBe(['statki'])
            ->and($crew->canEnter($second))->toBeTrue()
            ->and($crew->canEnter(1))->toBeFalse();
    });

    it('have the role that the admin was given for them, else the one of the admin, and a publisher is judged in the game that is in play', function () {
        $second = theSecondGame();
        $crew = anAdminOf(['raceidle' => null, 'statki' => Admin::ROLE_PUBLISHER]);

        expect($crew->roleIn(1))->toBe(Admin::ROLE_EDITOR)
            ->and($crew->roleIn($second))->toBe(Admin::ROLE_PUBLISHER)
            ->and($crew->isPublisher())->toBeFalse();

        app(CurrentGame::class)->set($second);

        expect($crew->isPublisher())->toBeTrue()
            // An admin who may work in every game has the role of their own in every one of them.
            ->and(signedInAdmin(Admin::ROLE_PUBLISHER, 'boss2@example.com')->isPublisher())->toBeTrue();
    });
});

describe('the game switcher', function () {
    it('lists the games that the admin may enter, with the one in play chosen', function () {
        theSecondGame();
        signedInAdmin();

        Livewire::test(GameSwitcher::class)
            ->assertSet('slug', 'raceidle')
            ->assertSeeHtml('data-test="game-select"')
            ->assertSee('RideIdle')
            ->assertSee('Statki');
    });

    it('lists only the games that an admin was given, and shows the name and no choice when there is one', function () {
        theSecondGame();
        anAdminOf(['statki' => null]);
        app(CurrentGame::class)->set(Game::query()->where('slug', 'statki')->first());

        Livewire::test(GameSwitcher::class)
            ->assertSet('slug', 'statki')
            ->assertSeeHtml('data-test="game-name"')
            ->assertDontSeeHtml('data-test="game-select"')
            ->assertSee('Statki')
            ->assertDontSee('RideIdle');
    });

    it('keeps the slug of the game that was chosen in the session and takes the admin to the home of the panel', function () {
        theSecondGame();
        signedInAdmin();

        Livewire::test(GameSwitcher::class)->set('slug', 'statki')->assertRedirect();

        expect(session(SetAdminGame::SESSION_KEY))->toBe('statki');
    });

    it('does not take an admin into a game that they may not enter', function () {
        theSecondGame();
        anAdminOf(['statki' => null]);

        Livewire::test(GameSwitcher::class)->set('slug', 'raceidle')->assertForbidden();

        expect(session(SetAdminGame::SESSION_KEY))->toBeNull();
    });

    it('is in the top bar of every page of the panel', function () {
        theSecondGame();
        signedInAdmin();

        $this->get('/admin')->assertOk()->assertSee('data-test="game-select"', escape: false);
    });
});

describe('the game that a request of the panel is in', function () {
    it('is the one in the session when the admin may enter it, and the first game otherwise', function () {
        $second = theSecondGame();
        signedInAdmin();

        $this->get('/admin')->assertOk();
        expect(app(CurrentGame::class)->id())->toBe(1);

        $this->withSession([SetAdminGame::SESSION_KEY => 'statki'])->get('/admin')->assertOk();
        expect(app(CurrentGame::class)->id())->toBe($second->id);

        $this->withSession([SetAdminGame::SESSION_KEY => 'no-such-game'])->get('/admin')->assertOk();
        expect(app(CurrentGame::class)->id())->toBe(1);
    });

    it('is never one that the admin may not enter: they get the first one of theirs', function () {
        $second = theSecondGame();
        anAdminOf(['statki' => null]);

        // An admin of one game has no network, so the home of the panel is the overview of that game.
        $this->withSession([SetAdminGame::SESSION_KEY => 'raceidle'])->get('/admin')->assertRedirect(GameOverview::getUrl());

        expect(app(CurrentGame::class)->id())->toBe($second->id);
    });

    it('is refused to an admin who may enter no game, who sees nothing of the panel', function () {
        theSecondGame();
        anAdminOf([]);

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/vehicles')->assertForbidden();
    });
});

describe('what the panel lists and says in a game', function () {
    it('is that game\'s: the cars of one game are not in the list of the other', function () {
        $second = theSecondGame();
        contentOfTheSecondGame($second);
        signedInAdmin();

        app(CurrentGame::class)->set($second);
        Livewire::test(ListVehicles::class)->set('tableRecordsPerPage', 100)->assertSee('Statek Jeden')->assertDontSee('Rdzak GT-4');

        app(CurrentGame::class)->set(Game::query()->find(1));
        Livewire::test(ListVehicles::class)->set('tableRecordsPerPage', 100)->assertSee('Rdzak GT-4')->assertDontSee('Statek Jeden');
    });

    it('is told by the widget of the live pack: the pack of the game, read from the database whatever the configuration says', function () {
        $second = theSecondGame();
        contentOfTheSecondGame($second);
        signedInAdmin();
        config(['ride.content_source' => 'files']);

        app(CurrentGame::class)->set(Game::query()->find(1));
        Livewire::test(LivePackWidget::class)->assertSee(__('admin.widget.files'))->assertDontSee(__('admin.widget.database'));

        app(CurrentGame::class)->set($second);
        Livewire::test(LivePackWidget::class)->assertSee(__('admin.widget.database'));
    });

    it('is written to the trail with the game that it was done in, and a line with no game in force has none', function () {
        $second = theSecondGame();
        signedInAdmin(Admin::ROLE_PUBLISHER);

        app(AdminAudit::class)->record('logout');
        app(CurrentGame::class)->set($second);
        app(AdminAudit::class)->record('login');

        expect(AdminAuditLog::query()->where('action', 'logout')->value('game_id'))->toBeNull()
            ->and(AdminAuditLog::query()->where('action', 'login')->value('game_id'))->toBe($second->id);

        Livewire::test(ManageAdminAuditLogs::class)->assertSee('Statki')->filterTable('game_id', $second->id)->assertCountTableRecords(1);
    });
});

describe('the page of the games', function () {
    it('is for an admin who may work in every game: they list the games, and no admin deletes one', function () {
        $second = theSecondGame();
        signedInAdmin();

        expect(GameResource::canViewAny())->toBeTrue()
            ->and(GameResource::canDelete($second))->toBeFalse();

        Livewire::test(ListGames::class)->assertCanSeeTableRecords(Game::query()->get())->assertSee('statki.example.test');
        $this->get('/admin/games')->assertOk();
    });

    it('is not for an admin who was given games: they do not see it, and are refused when they ask for it', function () {
        theSecondGame();
        anAdminOf(['raceidle' => null, 'statki' => null]);

        expect(GameResource::canViewAny())->toBeFalse()->and(GameResource::canCreate())->toBeFalse();

        $this->get('/admin/games')->assertForbidden();
        $this->get('/admin/games/create')->assertForbidden();
    });

    it('makes a game that is a draft, and writes it to the trail', function () {
        signedInAdmin();

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'kosmos', 'name' => 'Kosmos', 'domain' => 'kosmos.example.test', 'locale' => 'en', 'theme_color' => '#101a3a', 'status' => Game::STATUS_DRAFT])
            ->call('create')
            ->assertHasNoFormErrors();

        $game = Game::query()->where('slug', 'kosmos')->firstOrFail();
        $line = AdminAuditLog::query()->where('action', 'game_created')->firstOrFail();

        expect($game->status)->toBe(Game::STATUS_DRAFT)
            ->and($game->is_default)->toBeFalse()
            ->and($line->subject)->toBe('game:kosmos')
            ->and($line->context)->toBe(['name' => 'Kosmos', 'domain' => 'kosmos.example.test', 'status' => 'draft']);
    });

    it('does not make a game whose slug is not a slug or is taken, or whose domain is not a host in lower case or is taken, or whose colour is not one', function () {
        theSecondGame();
        signedInAdmin();
        $form = ['slug' => 'kosmos', 'name' => 'Kosmos', 'domain' => 'kosmos.example.test', 'locale' => 'en', 'theme_color' => '#101a3a', 'status' => Game::STATUS_DRAFT];

        Livewire::test(CreateGame::class)->fillForm([...$form, 'slug' => 'Bad Slug'])->call('create')->assertHasFormErrors(['slug' => 'regex']);
        Livewire::test(CreateGame::class)->fillForm([...$form, 'slug' => 'statki'])->call('create')->assertHasFormErrors(['slug' => 'unique']);
        Livewire::test(CreateGame::class)->fillForm([...$form, 'domain' => 'statki.example.test'])->call('create')->assertHasFormErrors(['domain' => 'unique']);
        Livewire::test(CreateGame::class)->fillForm([...$form, 'domain' => 'kosmos'])->call('create')->assertHasFormErrors(['domain' => 'regex']);
        // A domain with capitals is not refused: the form writes it in lower case when it is left.
        Livewire::test(CreateGame::class)->fillForm([...$form, 'domain' => 'Kosmos.Example.test'])->assertFormSet(['domain' => 'kosmos.example.test']);
        Livewire::test(CreateGame::class)->fillForm([...$form, 'theme_color' => 'blue'])->call('create')->assertHasFormErrors(['theme_color' => 'regex']);

        expect(Game::query()->where('slug', 'kosmos')->exists())->toBeFalse();
    });

    it('changes the name, the domain and the status of a game and writes what changed, and never its slug or the default', function () {
        $second = theSecondGame(['status' => Game::STATUS_DRAFT]);
        signedInAdmin();

        Livewire::test(EditGame::class, ['record' => $second->getRouteKey()])
            ->assertFormSet(['slug' => 'statki', 'is_default' => false])
            ->fillForm(['name' => 'Statki i okręty', 'status' => Game::STATUS_LIVE])
            ->set('data.slug', 'hacked')
            ->set('data.is_default', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $second->refresh();

        expect($second->slug)->toBe('statki')
            ->and($second->name)->toBe('Statki i okręty')
            ->and($second->status)->toBe(Game::STATUS_LIVE)
            ->and($second->is_default)->toBeFalse()
            ->and(AdminAuditLog::query()->where('action', 'game_updated')->value('context'))->toBe(['changes' => ['name', 'status']]);
    });

    it('lets the domain of a game stay when it is saved, and does not take the domain of another', function () {
        $second = theSecondGame();
        signedInAdmin();
        $first = Game::query()->find(1);

        Livewire::test(EditGame::class, ['record' => $second->getRouteKey()])->fillForm(['name' => 'Statki 2'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditGame::class, ['record' => $second->getRouteKey()])->fillForm(['domain' => (string) $first->domain])->call('save')->assertHasFormErrors(['domain' => 'unique']);
    });
});

describe('the admins of the games, from the commands', function () {
    it('makes an admin of every game unless games are given, and says so', function () {
        theSecondGame();

        $this->artisan('admin:create', ['email' => 'all@example.com'])->expectsOutputToContain('They may work in: every game.')->assertSuccessful();

        expect(Admin::query()->where('email', 'all@example.com')->first()->all_games)->toBeTrue();
    });

    it('makes an admin of the games that are given, with the role in each when it is not their own', function () {
        theSecondGame();

        $this->artisan('admin:create', ['email' => 'crew@example.com', '--game' => ['raceidle', 'statki:publisher']])
            ->expectsOutputToContain('raceidle (editor), statki (publisher)')
            ->assertSuccessful();

        $admin = Admin::query()->where('email', 'crew@example.com')->firstOrFail();

        expect($admin->all_games)->toBeFalse()
            ->and($admin->allowedGames()->pluck('slug')->all())->toBe(['raceidle', 'statki'])
            ->and($admin->roleIn(Game::query()->where('slug', 'statki')->first()))->toBe(Admin::ROLE_PUBLISHER)
            ->and(AdminAuditLog::query()->where('action', 'admin_created')->value('context'))->toBe(['role' => 'editor', 'games' => 'raceidle (editor), statki (publisher)', 'by' => 'cli']);
    });

    it('refuses a game that is not there, a role that is not one, and every game together with some', function () {
        theSecondGame();

        $this->artisan('admin:create', ['email' => 'a@example.com', '--game' => ['nope']])->expectsOutputToContain('There is no game nope.')->assertFailed();
        $this->artisan('admin:create', ['email' => 'b@example.com', '--game' => ['statki:boss']])->expectsOutputToContain('The role in a game is')->assertFailed();
        $this->artisan('admin:create', ['email' => 'c@example.com', '--all-games' => true, '--game' => ['statki']])->expectsOutputToContain('not both')->assertFailed();

        expect(Admin::query()->whereIn('email', ['a@example.com', 'b@example.com', 'c@example.com'])->count())->toBe(0);
    });

    it('changes the games of an admin: the ones that are given replace the ones before, and every game takes them off', function () {
        theSecondGame();
        anAdminOf(['raceidle' => null], email: 'crew@example.com');

        $this->artisan('admin:update', ['email' => 'crew@example.com', '--game' => ['statki:publisher']])->expectsOutputToContain('games: statki (publisher)')->assertSuccessful();

        $admin = Admin::query()->where('email', 'crew@example.com')->firstOrFail();

        expect($admin->allowedGames()->pluck('slug')->all())->toBe(['statki']);

        $this->artisan('admin:update', ['email' => 'crew@example.com', '--all-games' => true])->expectsOutputToContain('games: every game')->assertSuccessful();

        expect($admin->refresh()->all_games)->toBeTrue()->and($admin->games()->count())->toBe(0);
    });
});
