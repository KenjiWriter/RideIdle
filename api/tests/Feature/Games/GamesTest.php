<?php

declare(strict_types=1);

use App\Content\ContentException;
use App\Content\PackContent;
use App\Games\CurrentGame;
use App\Games\Games;
use App\Models\AccountIdentity;
use App\Models\ContentActivation;
use App\Models\ContentDefinition;
use App\Models\ContentPack;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

// Several games in one installation: each has its own content and its own players, and a request is for the game of its host. What belongs to a game is not seen
// from another, whatever the account or the token is, and what was unique in the whole database is unique in a game.

/** The second game, which answers on a domain of its own. */
function shipsGame(array $attributes = []): Game
{
    return Game::query()->create([...['slug' => 'ships', 'name' => 'Ships', 'domain' => 'ships.test', 'locale' => 'en', 'theme_color' => '#0b3d5c', 'status' => Game::STATUS_LIVE], ...$attributes]);
}

/** Does something in a game, whatever game is in play now. */
function inGame(Game $game, Closure $do): mixed
{
    return app(CurrentGame::class)->within($game, $do);
}

/** Puts the first game (the default one) in play again: a request leaves the game of its host in force. */
function backToTheFirstGame(): void
{
    app(CurrentGame::class)->set(null);
}

/** The content of the repository as the pack of a game, live. */
function liveInGame(Game $game, ?PackContent $content = null, string $number = '2026.10.30'): ContentPack
{
    return inGame($game, fn (): ContentPack => contentPacks()->import($content ?? repositoryContent(), $number, activate: true, checkTables: false)->pack);
}

/** @return array<string, string> the headers of a request to a game, in development */
function toGame(string $slug, array $headers = []): array
{
    return [...$headers, 'X-Game' => $slug];
}

describe('the games of an installation', function () {
    it('is one game after the migrations, the first, which answers on the domain of the installation and where nothing says which one is meant', function () {
        $first = Game::query()->firstOrFail();

        expect($first->id)->toBe(1)
            ->and($first->slug)->toBe('raceidle')
            ->and($first->is_default)->toBeTrue()
            ->and($first->domain)->toBe(parse_url((string) config('app.url'), PHP_URL_HOST))
            ->and(app(CurrentGame::class)->id())->toBe(1)
            ->and(app(CurrentGame::class)->isDefault())->toBeTrue();
    });

    it('puts a game in play for the work in it, and gives the one before back', function () {
        $ships = shipsGame();
        $current = app(CurrentGame::class);

        $inside = $current->within($ships, fn (): array => [$current->id(), $current->isDefault()]);

        expect($inside)->toBe([$ships->id, false])
            ->and($current->id())->toBe(1);
    });

    it('writes what belongs to a game in the game that is in play, and reads it there only', function () {
        $ships = shipsGame();

        $mine = User::factory()->guest()->create();
        $theirs = inGame($ships, fn (): User => User::factory()->guest()->create());

        expect($mine->game_id)->toBe(1)
            ->and($theirs->game_id)->toBe($ships->id)
            ->and(User::query()->pluck('id')->all())->toBe([$mine->id])
            ->and(inGame($ships, fn (): array => User::query()->pluck('id')->all()))->toBe([$theirs->id])
            ->and(User::query()->withoutGlobalScopes()->count())->toBe(2);
    });
});

describe('the game of a request', function () {
    it('is the game of the host, and in development the one that a header names', function () {
        shipsGame();
        $games = app(Games::class);

        expect($games->forRequest(Request::create('http://ships.test/api/v1/meta/legal'))->slug)->toBe('ships')
            ->and($games->forRequest(Request::create('http://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/api/v1/meta/legal'))->slug)->toBe('raceidle')
            ->and($games->forRequest(Request::create('http://nobody.test/', server: ['HTTP_X_GAME' => 'ships']))->slug)->toBe('ships')
            ->and($games->forRequest(Request::create('http://nobody.test/', server: ['HTTP_X_GAME' => 'nope'])))->toBeNull()
            // A host that nobody claims is the default game in development.
            ->and($games->forRequest(Request::create('http://nobody.test/'))->slug)->toBe('raceidle');
    });

    it('is the only game when there is one, whatever the host is, and a draft game answers to nobody', function () {
        expect(app(Games::class)->forRequest(Request::create('http://anything.test/'))->slug)->toBe('raceidle');

        $draft = shipsGame(['status' => Game::STATUS_DRAFT]);
        $games = new Games;

        expect($games->forRequest(Request::create('http://ships.test/'))->slug)->toBe('raceidle')
            ->and($draft->isLive())->toBeFalse();
    });

    it('is nobody for a host that no game claims in production, which does not take a header either', function () {
        shipsGame();
        $this->app->detectEnvironment(fn (): string => 'production');

        $games = new Games;

        expect($games->forRequest(Request::create('http://nobody.test/')))->toBeNull()
            ->and($games->forRequest(Request::create('http://nobody.test/', server: ['HTTP_X_GAME' => 'ships'])))->toBeNull()
            ->and($games->forRequest(Request::create('http://ships.test/'))->slug)->toBe('ships');

        $this->app->detectEnvironment(fn (): string => 'testing');
    });

    it('is refused when there is no such game, and every route of the API is asked in the game of its host', function () {
        liveInGame(shipsGame());

        $this->getJson('/api/v1/meta/locales', toGame('nope'))->assertNotFound()->assertJsonPath('code', 'unknown_game');
        $this->getJson('http://ships.test/api/v1/meta/model')->assertOk();
        expect(app(CurrentGame::class)->id())->toBe(2);

        // (The client keeps the host of the last request for the addresses that are only a path, so the first game is asked by its own.)
        $this->getJson(config('app.url').'/api/v1/meta/model')->assertOk();
        expect(app(CurrentGame::class)->id())->toBe(1);
    });
});

describe('the accounts of the games', function () {
    it('are not seen from another game: a token of one game is no account in another', function () {
        $ships = shipsGame();
        liveInGame($ships);
        contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
        $driver = User::factory()->guest()->create();

        // The guard keeps the account of an earlier request, so each request has a token of its own.
        $this->getJson('/api/v1/starter-cars', bearerFor($driver))->assertOk();
        $this->getJson('/api/v1/starter-cars', toGame('ships', bearerFor($driver)))->assertUnauthorized();
        $this->getJson('/api/v1/starter-cars', bearerFor($driver))->assertOk();
    });

    it('may have the same driver name, the same e-mail and the same sign-in in two games, and not twice in one', function () {
        $ships = shipsGame();
        $first = User::factory()->guest()->create(['name' => 'Turbo', 'name_key' => 'turbo', 'email' => 'turbo@example.com']);
        $second = inGame($ships, fn (): User => User::factory()->guest()->create(['name' => 'Turbo', 'name_key' => 'turbo', 'email' => 'turbo@example.com']));

        expect($first->game_id)->not->toBe($second->game_id)
            ->and(fn () => User::factory()->guest()->create(['name' => 'Turbo', 'name_key' => 'turbo', 'email' => 'other@example.com']))->toThrow(QueryException::class);

        AccountIdentity::query()->create(['user_id' => $first->id, 'provider' => AccountIdentity::GOOGLE, 'subject' => 'google-1']);
        inGame($ships, fn () => AccountIdentity::query()->create(['user_id' => $second->id, 'provider' => AccountIdentity::GOOGLE, 'subject' => 'google-1']));

        expect(AccountIdentity::query()->withoutGlobalScopes()->where('subject', 'google-1')->pluck('game_id')->sort()->values()->all())->toBe([1, 2]);
    });

    it('takes a driver name in the game of the request: a free name there is taken only there', function () {
        $ships = shipsGame();
        liveInGame($ships);
        User::factory()->guest()->create(['name' => 'Turbo', 'name_key' => 'turbo']);

        $this->postJson('/api/v1/players', ['name' => 'Turbo', 'terms' => true, 'age' => true], toGame('ships'))->assertCreated();
        $this->postJson('/api/v1/players', ['name' => 'Turbo', 'terms' => true, 'age' => true], toGame('raceidle'))->assertUnprocessable();

        expect(User::query()->withoutGlobalScopes()->where('name_key', 'turbo')->pluck('game_id')->sort()->values()->all())->toBe([1, 2]);
    });
});

describe('the content of the games', function () {
    it('is made, lives and is numbered in a game: the same pack number and the same slugs are in two games', function () {
        $ships = shipsGame();

        $first = contentPacks()->import(repositoryContent(), '2026.10.30', activate: true, checkTables: false)->pack;
        $second = liveInGame($ships);

        expect($first->game_id)->toBe(1)
            ->and($second->game_id)->toBe($ships->id)
            ->and($first->number)->toBe($second->number)
            ->and(ContentDefinition::query()->withoutGlobalScopes()->where('slug', 'rdzak-gt-4')->pluck('game_id')->sort()->values()->all())->toBe([1, 2])
            ->and(ContentPack::query()->count())->toBe(1)
            ->and(contentPacks()->active()->id)->toBe($first->id)
            ->and(inGame($ships, fn () => contentPacks()->active()->id))->toBe($second->id);
    });

    it('is live in a game without being live in another: an activation changes the game it is made in', function () {
        $ships = shipsGame();
        $first = contentPacks()->import(repositoryContent(), '2026.10.30', activate: true, checkTables: false)->pack;
        $other = inGame($ships, fn () => contentPacks()->import(contentWith(stronger(160)), '2026.10.31', activate: true, checkTables: false)->pack);

        expect(contentPacks()->active()->id)->toBe($first->id)
            ->and(ContentActivation::query()->count())->toBe(1)
            ->and(inGame($ships, fn () => ContentActivation::query()->count()))->toBe(1)
            ->and(inGame($ships, fn () => contentPacks()->active()->number))->toBe($other->number);
    });

    it('is read by each game for itself: the cars, the vocabulary and the model that a game says are the ones of its pack', function () {
        $ships = shipsGame();
        contentPacks()->import(repositoryContent(), activate: true, checkTables: false);

        $theme = json_decode((string) file_get_contents(config('ride.content_path').'/themes/ships.json'), true);
        $content = contentWith([
            'rules/vocabulary' => fn (array $vocabulary): array => [...$vocabulary, 'messages' => $theme['messages']],
            'rules/model' => fn (array $model): array => [...$model, 'stats' => ['top_speed', 'accel']],
            'vehicles/rdzak-gt-4' => fn (array $car): array => [...$car, 'name' => ['en' => 'Ship GT-4', 'pl' => 'Statek GT-4']],
        ]);
        liveInGame($ships, $content);
        readFromDatabase();

        $names = fn (string $game): array => array_column($this->getJson('/api/v1/starter-cars', [...bearerFor(inGame(Game::query()->where('slug', $game)->firstOrFail(), fn (): User => User::factory()->guest()->create())), 'X-Game' => $game])->assertOk()->json('data'), 'name');

        expect($names('raceidle'))->toContain('Rdzak GT-4')
            ->and($names('ships'))->toContain('Ship GT-4')->not->toContain('Rdzak GT-4');

        $this->getJson('/api/v1/meta/model', toGame('raceidle'))->assertJsonPath('stats', ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']);
        $this->getJson('/api/v1/meta/model', toGame('ships'))->assertJsonPath('stats', ['top_speed', 'accel']);

        expect($this->getJson('/api/v1/meta/vocabulary', toGame('ships'))->assertOk()->json('messages.pl'))->toHaveKey('slot.tires')
            ->and($this->getJson('/api/v1/meta/vocabulary', toGame('raceidle'))->assertOk()->json('messages'))->toBe([]);
    });

    it('has the files of the repository for the default game only: another game has its pack in the database or none', function () {
        $ships = shipsGame();

        // The files are the first game's whatever the configuration is, and a game with no pack has nothing to read.
        expect(contentPacks()->configured()->rulesetVersion())->not->toBe('')
            ->and(fn () => inGame($ships, fn () => contentPacks()->configured()))->toThrow(ContentException::class, 'No content pack is live in this game');

        liveInGame($ships, contentWith(stronger(170)), '2026.10.32');

        expect(inGame($ships, fn () => contentPacks()->configured()->rulesetVersion()))->toBe('2026.10.32');
    });

    it('is imported and made live in the game that the command names', function () {
        shipsGame();

        $code = Artisan::call('content:import', ['--activate' => true, '--game' => 'ships']);

        // (The command leaves the game in force, as the end of a process would.)
        backToTheFirstGame();

        expect($code)->toBe(0)
            ->and(inGame(Game::query()->where('slug', 'ships')->firstOrFail(), fn () => ContentPack::query()->count()))->toBe(1)
            ->and(ContentPack::query()->count())->toBe(0)
            ->and(Artisan::call('content:status', ['--game' => 'nope']))->toBe(1);
    });
});
