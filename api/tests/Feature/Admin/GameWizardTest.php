<?php

declare(strict_types=1);

use App\Filament\Pages\DomainPage;
use App\Filament\Pages\GameOverview;
use App\Filament\Pages\NetworkDashboard;
use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Widgets\GameTilesWidget;
use App\Games\CurrentGame;
use App\Http\Middleware\SetAdminGame;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentPack;
use App\Models\DomainJob;
use App\Models\Game;
use App\Services\ContentPacks;
use App\Services\DomainChecker;
use App\Services\GameCreator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// The way a game is made in the panel (three steps), the page of its domain and certificate, the badge of the certificate on the tile of the game, and the place of the page in
// the menu. What the server does with a request is the worker's, and is tested apart (DomainJobsTest).

const WIZARD_NOW = '2026-10-29 12:00:00';

/** A game with a domain, which is a draft, as the wizard makes it. */
function wizardGame(string $slug = 'kosmos', ?string $domain = 'kosmos.example.test'): Game
{
    return Game::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'domain' => $domain, 'locale' => 'en', 'theme_color' => '#101a3a', 'status' => Game::STATUS_DRAFT, 'is_default' => false]);
}

/** The DNS of the tests: each host resolves to the addresses given for it (to none when it is not named). */
function wizardDns(array $hosts): void
{
    app()->instance(DomainChecker::class, new DomainChecker(fn (string $host): array => $hosts[$host] ?? []));
}

/** The fields of the three steps of the wizard, with what is given on top. @param array<string, mixed> $fields @return array<string, mixed> */
function wizardForm(array $fields = []): array
{
    return ['name' => 'Kosmos', 'slug' => 'kosmos', 'domain' => 'kosmos.example.test', 'locale' => 'en', 'theme_color' => '#101a3a', 'start' => GameCreator::START_NONE, 'request_domain' => false, ...$fields];
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
    config(['ride.network.acme_email' => 'ops@example.com', 'ride.network.server_ips' => ['203.0.113.7']]);
    CarbonImmutable::setTestNow(WIZARD_NOW);
});

describe('making a game in three steps', function () {
    it('makes the game, a draft, with its slug from the name, and takes the admin to the page of its domain, in the game that was made', function () {
        signedInAdmin();

        Livewire::test(CreateGame::class)
            ->fillForm(['name' => 'Gwiezdne Statki'])
            ->assertFormSet(['slug' => 'gwiezdne-statki'])
            ->fillForm(wizardForm(['name' => 'Gwiezdne Statki', 'slug' => 'statki-kosmiczne']))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(DomainPage::getUrl());

        $game = Game::query()->where('slug', 'statki-kosmiczne')->firstOrFail();

        expect($game->name)->toBe('Gwiezdne Statki')
            ->and($game->status)->toBe(Game::STATUS_DRAFT)
            ->and($game->is_default)->toBeFalse()
            ->and(session(SetAdminGame::SESSION_KEY))->toBe('statki-kosmiczne')
            ->and(AdminAuditLog::query()->where('action', 'game_created')->count())->toBe(1);
    });

    it('starts from the car game by default: the game has a live pack at once', function () {
        signedInAdmin();

        Livewire::test(CreateGame::class)->fillForm(wizardForm(['start' => GameCreator::START_TEMPLATE]))->call('create')->assertHasNoFormErrors();

        $game = Game::query()->where('slug', 'kosmos')->firstOrFail();

        expect(app(CurrentGame::class)->within($game, fn (): ?ContentPack => app(ContentPacks::class)->active()))->not->toBeNull();
    });

    it('starts from a copy of another game, which has to be chosen when that is the start', function () {
        $source = wizardGame('statki', 'statki.example.test');
        app(CurrentGame::class)->within($source, fn () => contentPacks()->import(repositoryContent(), checkTables: false, activate: true));
        signedInAdmin();

        Livewire::test(CreateGame::class)->fillForm(wizardForm(['start' => GameCreator::START_COPY]))->call('create')->assertHasFormErrors(['copy_from' => 'required']);

        Livewire::test(CreateGame::class)->fillForm(wizardForm(['start' => GameCreator::START_COPY, 'copy_from' => 'statki']))->call('create')->assertHasNoFormErrors();

        $game = Game::query()->where('slug', 'kosmos')->firstOrFail();

        expect(app(CurrentGame::class)->within($game, fn (): ?string => app(ContentPacks::class)->active()?->number))->not->toBeNull();
    });

    it('starts with no content when it is told to, and makes a game that has none', function () {
        signedInAdmin();

        Livewire::test(CreateGame::class)->fillForm(wizardForm())->call('create')->assertHasNoFormErrors();

        $game = Game::query()->where('slug', 'kosmos')->firstOrFail();

        expect(app(CurrentGame::class)->within($game, fn (): ?ContentPack => app(ContentPacks::class)->active()))->toBeNull();
    });

    it('asks the server for the domain when that is chosen, and asks nothing when it is not, and a game with no domain asks nothing either', function () {
        signedInAdmin();

        Livewire::test(CreateGame::class)->fillForm(wizardForm(['request_domain' => true]))->call('create')->assertHasNoFormErrors();

        expect(DomainJob::query()->where('status', DomainJob::STATUS_REQUESTED)->where('domain', 'kosmos.example.test')->count())->toBe(1);

        Livewire::test(CreateGame::class)->fillForm(wizardForm(['name' => 'Rejsy', 'slug' => 'rejsy', 'domain' => 'rejsy.example.test']))->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateGame::class)->fillForm(wizardForm(['name' => 'Statki', 'slug' => 'statki', 'domain' => '', 'request_domain' => true]))->call('create')->assertHasNoFormErrors();

        expect(DomainJob::query()->count())->toBe(1)->and(Game::query()->where('slug', 'statki')->value('domain'))->toBeNull();
    });

    it('says what it could not do and makes the game all the same: here, a copy of a game that has no live pack', function () {
        wizardGame('statki', 'statki.example.test');
        signedInAdmin();

        Livewire::test(CreateGame::class)
            ->fillForm(wizardForm(['start' => GameCreator::START_COPY, 'copy_from' => 'statki']))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.games.wizard.warning.copy_empty'));

        $game = Game::query()->where('slug', 'kosmos')->firstOrFail();

        expect(app(CurrentGame::class)->within($game, fn (): ?ContentPack => app(ContentPacks::class)->active()))->toBeNull();
    });

    it('looks at the DNS of the domain in the form when it is asked to, and keeps what it found for the step to show', function () {
        wizardDns(['kosmos.example.test' => ['203.0.113.7'], 'rejsy.example.test' => ['198.51.100.9']]);
        signedInAdmin();

        Livewire::test(CreateGame::class)
            ->assertSet('dnsCheck', null)
            ->fillForm(wizardForm())
            ->call('checkDns')
            ->assertSet('dnsCheck.status', 'ok')
            ->assertSet('dnsCheck.domain', 'kosmos.example.test')
            ->fillForm(['domain' => 'rejsy.example.test'])
            ->call('checkDns')
            ->assertSet('dnsCheck.status', 'wrong')
            ->assertSet('dnsCheck.found', ['198.51.100.9']);
    });

    it('refuses a domain that a certificate cannot be asked for, and one with capitals is written in lower case', function () {
        signedInAdmin();

        Livewire::test(CreateGame::class)->fillForm(wizardForm(['domain' => 'kosmos']))->call('create')->assertHasFormErrors(['domain' => 'regex']);
        Livewire::test(CreateGame::class)->fillForm(wizardForm(['domain' => 'Kosmos.Example.test']))->assertFormSet(['domain' => 'kosmos.example.test']);

        expect(Game::query()->where('slug', 'kosmos')->exists())->toBeFalse();
    });

    it('is only for an admin who may work in every game', function () {
        wizardGame('statki', 'statki.example.test');
        $admin = signedInAdmin(Admin::ROLE_EDITOR, 'crew@example.com');
        $admin->forceFill(['all_games' => false])->save();
        $admin->games()->attach(Game::query()->where('slug', 'statki')->value('id'), ['role' => null]);

        $this->get('/admin/games/create')->assertForbidden();
    });
});

describe('the page of the domain of a game', function () {
    it('is for an admin who may work in every game', function () {
        wizardGame();
        signedInAdmin();

        expect(DomainPage::canAccess())->toBeTrue();

        $this->withSession([SetAdminGame::SESSION_KEY => 'kosmos'])->get(DomainPage::getUrl())->assertOk();
    });

    it('is not for an admin who was given games: they do not see it, and are refused when they ask for it', function () {
        $game = wizardGame();
        $limited = signedInAdmin(Admin::ROLE_EDITOR, 'crew@example.com');
        $limited->forceFill(['all_games' => false])->save();
        $limited->games()->attach($game->id, ['role' => null]);

        expect(DomainPage::canAccess())->toBeFalse();

        $this->get(DomainPage::getUrl())->assertForbidden();

        Livewire::test(DomainPage::class)->assertForbidden();
    });

    it('says that the game has no domain, and where to give it one', function () {
        wizardGame('kosmos', null);
        signedInAdmin();
        app(CurrentGame::class)->set(Game::query()->where('slug', 'kosmos')->first());

        Livewire::test(DomainPage::class)
            ->assertSeeHtml('data-test="no-domain"')
            ->assertSee(__('admin.domain.domain_none'))
            ->assertDontSeeHtml('data-test="request-provision"');
    });

    it('shows the domain, and looks at its DNS when asked', function () {
        wizardGame();
        wizardDns(['kosmos.example.test' => ['203.0.113.7']]);
        signedInAdmin();
        app(CurrentGame::class)->set(Game::query()->where('slug', 'kosmos')->first());

        Livewire::test(DomainPage::class)
            ->assertSee('kosmos.example.test')
            ->assertDontSeeHtml('data-test="dns-result"')
            ->call('checkDns')
            ->assertSeeHtml('data-test="dns-result"')
            ->assertSeeHtml('data-status="ok"')
            ->assertSee(__('admin.domain.dns.ok'));
    });

    it('tells where to point the domain when it points elsewhere', function () {
        wizardGame();
        wizardDns(['kosmos.example.test' => ['198.51.100.9']]);
        signedInAdmin();
        app(CurrentGame::class)->set(Game::query()->where('slug', 'kosmos')->first());

        Livewire::test(DomainPage::class)
            ->call('checkDns')
            ->assertSeeHtml('data-status="wrong"')
            ->assertSee(__('admin.domain.dns.instruction', ['domain' => 'kosmos.example.test', 'addresses' => '203.0.113.7']));
    });

    it('asks the server to set the domain up, writes it to the trail, and does not ask twice while the server is at it', function () {
        $game = wizardGame();
        $admin = signedInAdmin();
        app(CurrentGame::class)->set($game);

        Livewire::test(DomainPage::class)
            ->call('requestProvision')
            ->assertNotified(__('admin.domain.requested'))
            ->call('requestProvision')
            ->assertNotified(__('admin.domain.refused.busy'));

        $job = DomainJob::query()->firstOrFail();
        $line = AdminAuditLog::query()->where('action', 'domain_requested')->firstOrFail();

        expect(DomainJob::query()->count())->toBe(1)
            ->and($job->requested_by)->toBe($admin->id)
            ->and($job->status)->toBe(DomainJob::STATUS_REQUESTED)
            ->and($line->subject)->toBe('game:kosmos')
            ->and($line->context)->toMatchArray(['action' => 'provision', 'domain' => 'kosmos.example.test']);
    });

    it('asks nothing without an address for Let\'s Encrypt, and says so, but may still look at a certificate', function () {
        config(['ride.network.acme_email' => null]);
        $game = wizardGame();
        signedInAdmin();
        app(CurrentGame::class)->set($game);

        Livewire::test(DomainPage::class)
            ->assertSeeHtml('data-test="no-email"')
            ->call('requestProvision')
            ->assertNotified(__('admin.domain.refused.email'));

        expect(DomainJob::query()->count())->toBe(0);

        Livewire::test(DomainPage::class)->call('requestCheck')->assertNotified(__('admin.domain.requested'));

        expect(DomainJob::query()->where('action', 'check')->count())->toBe(1);
    });

    it('shows how the last request went: its state, who asked, the code and the log of the server', function () {
        $game = wizardGame();
        $admin = signedInAdmin();
        app(CurrentGame::class)->set($game);
        DomainJob::query()->create([
            'game_id' => $game->id, 'domain' => 'kosmos.example.test', 'action' => 'provision', 'status' => DomainJob::STATUS_FAILED, 'email' => 'ops@example.com',
            'requested_by' => $admin->id, 'exit_code' => 3, 'log' => "game-site: the domain does not point here\n", 'finished_at' => CarbonImmutable::parse(WIZARD_NOW),
        ]);

        Livewire::test(DomainPage::class)
            ->assertSee(__('admin.domain.job.status.failed'))
            ->assertSee(__('admin.domain.job.action.provision'))
            ->assertSee($admin->name)
            ->assertSee(__('admin.domain.job.exit', ['code' => 3]))
            ->assertSeeHtml('data-test="job-log"')
            ->assertSee('game-site: the domain does not point here')
            ->assertDontSeeHtml('wire:poll');
    });

    it('asks the server how it goes while a request is open, and says that the worker is slow when it has not taken it for a few minutes', function () {
        $game = wizardGame();
        signedInAdmin();
        app(CurrentGame::class)->set($game);
        $job = DomainJob::query()->create(['game_id' => $game->id, 'domain' => 'kosmos.example.test', 'action' => 'provision', 'status' => DomainJob::STATUS_REQUESTED, 'email' => 'ops@example.com']);

        Livewire::test(DomainPage::class)->assertSeeHtml('wire:poll')->assertDontSeeHtml('data-test="worker-slow"');

        $job->forceFill(['created_at' => CarbonImmutable::parse(WIZARD_NOW)->subMinutes(5)])->save();

        Livewire::test(DomainPage::class)->assertSeeHtml('data-test="worker-slow"')->assertSee('rideidle-domains.timer');
    });

    it('says how long the certificate holds: until when, that it ends soon, or that it has ended', function (string $expires, string $state) {
        $game = wizardGame();
        signedInAdmin();
        app(CurrentGame::class)->set($game);
        DomainJob::query()->create(['game_id' => $game->id, 'domain' => 'kosmos.example.test', 'action' => 'provision', 'status' => DomainJob::STATUS_DONE, 'exit_code' => 0, 'certificate_expires_at' => $expires]);

        Livewire::test(DomainPage::class)->assertSeeHtml('data-test="certificate" data-state="'.$state.'"')->assertSee($expires);
    })->with([
        'valid' => ['2026-12-20', 'valid'],
        'ends soon' => ['2026-11-05', 'soon'],
        'ends today' => ['2026-10-29', 'soon'],
        'ended' => ['2026-10-28', 'expired'],
    ]);

    it('says that it knows nothing of a certificate before one was looked at', function () {
        $game = wizardGame();
        signedInAdmin();
        app(CurrentGame::class)->set($game);

        Livewire::test(DomainPage::class)->assertSeeHtml('data-test="certificate" data-state="none"')->assertSee(__('admin.domain.job.none'));
    });

    it('is in the menu of a game, for an admin of every game', function () {
        wizardGame();
        signedInAdmin();

        $this->withSession([SetAdminGame::SESSION_KEY => 'kosmos'])->get(GameOverview::getUrl())->assertOk()->assertSeeHtml('href="'.DomainPage::getUrl().'"');
    });

    it('is not in the menu of the network', function () {
        wizardGame();
        signedInAdmin();

        $this->get(NetworkDashboard::getUrl())->assertOk()->assertDontSeeHtml('href="'.DomainPage::getUrl().'"');
    });
});

describe('the badge of the certificate on the tile of a game', function () {
    it('says where the certificate stands, by what the server was last asked and told', function (?array $job, string $state, ?string $text) {
        $game = wizardGame();
        signedInAdmin();

        if ($job !== null) {
            DomainJob::query()->create(['game_id' => $game->id, 'domain' => 'kosmos.example.test', 'action' => 'provision', ...$job]);
        }

        $tile = Livewire::test(GameTilesWidget::class)->assertSeeHtml('data-test="tile-ssl" data-state="'.$state.'"');

        if ($text !== null) {
            $tile->assertSee($text);
        }
    })->with([
        'nothing asked' => [null, 'none', null],
        'waiting' => [['status' => 'requested'], 'pending', null],
        'running' => [['status' => 'running'], 'pending', null],
        'failed' => [['status' => 'failed', 'exit_code' => 1], 'failed', null],
        'done, no date' => [['status' => 'done', 'exit_code' => 0], 'ok', null],
        'valid' => [['status' => 'done', 'exit_code' => 0, 'certificate_expires_at' => '2026-12-20'], 'ok', '2026-12-20'],
        'ends soon' => [['status' => 'done', 'exit_code' => 0, 'certificate_expires_at' => '2026-11-05'], 'soon', '2026-11-05'],
        'ended' => [['status' => 'done', 'exit_code' => 0, 'certificate_expires_at' => '2026-10-01'], 'expired', '2026-10-01'],
    ]);

    it('is the answer of the last request, and the date of the last certificate that was seen: a check that finds none is a failure', function () {
        $game = wizardGame();
        signedInAdmin();
        DomainJob::query()->create(['game_id' => $game->id, 'domain' => 'kosmos.example.test', 'action' => 'provision', 'status' => 'done', 'exit_code' => 0, 'certificate_expires_at' => '2026-12-20']);

        Livewire::test(GameTilesWidget::class)->assertSeeHtml('data-state="ok"');

        DomainJob::query()->create(['game_id' => $game->id, 'domain' => 'kosmos.example.test', 'action' => 'check', 'status' => 'failed', 'exit_code' => 1]);

        Livewire::test(GameTilesWidget::class)->assertSeeHtml('data-state="failed"');
    });

    it('is not on the tile of a game that has no domain', function () {
        wizardGame('kosmos', null);
        Game::query()->where('is_default', true)->update(['domain' => null]);
        signedInAdmin();

        Livewire::test(GameTilesWidget::class)->assertSeeHtml('data-test="game-tile-kosmos"')->assertDontSeeHtml('data-test="tile-ssl"');
    });
});
