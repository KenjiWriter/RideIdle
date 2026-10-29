<?php

declare(strict_types=1);

use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Games\CurrentGame;
use App\Mail\AccountLinkMail;
use App\Models\Admin;
use App\Models\Game;
use App\Models\User;
use App\Services\ContentImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

// The app of a game: what the app asks about its game before it draws anything (its name, its colour, its icons), the manifest that a browser puts it on a home screen
// by, and the addresses that e-mails and other services lead back to. Each is the one of the game of the host.

/** A PNG that is whole and square (the game looks at the header of a picture, not at what is drawn). */
function squarePng(int $side, string $seed = ''): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\x00".str_repeat("\x20\x40\x80", $side), $side);

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 2, 0, 0, 0)).$chunk('tEXt', "seed\x00{$seed}").$chunk('IDAT', (string) gzcompress($rows)).$chunk('IEND', '');
}

function gameWithContent(array $attributes = []): Game
{
    $game = Game::query()->create([...['slug' => 'ships', 'name' => 'Star Ships', 'domain' => 'ships.test', 'locale' => 'pl', 'theme_color' => '#0b3d5c', 'status' => Game::STATUS_LIVE], ...$attributes]);
    app(CurrentGame::class)->within($game, fn () => contentPacks()->import(repositoryContent(), '2026.10.30', activate: true, checkTables: false));

    return $game;
}

describe('the game as the app asks for it', function () {
    it('is the first game for the host of the installation: its name and colour, and no icons, because the app brings its own', function () {
        $response = $this->getJson('/api/v1/meta/game')->assertOk();

        expect($response->json())->toBe([
            'slug' => 'raceidle',
            'name' => 'RideIdle',
            'locale' => 'en',
            'theme_color' => '#0e1013',
            'icon_url' => null,
            'icon_maskable_url' => null,
            'is_default' => true,
        ])
            ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');

        $this->getJson('/api/v1/meta/game', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    });

    it('is the game of the host, with the icons that it has', function () {
        $icon = app(ContentImages::class)->store(squarePng(512));
        $game = gameWithContent(['icon' => $icon]);

        $this->getJson('/api/v1/meta/game', ['X-Game' => 'ships'])->assertOk()
            ->assertJsonPath('slug', 'ships')
            ->assertJsonPath('name', 'Star Ships')
            ->assertJsonPath('locale', 'pl')
            ->assertJsonPath('theme_color', '#0b3d5c')
            ->assertJsonPath('icon_url', "/api/v1/media/{$icon}")
            ->assertJsonPath('icon_maskable_url', null)
            ->assertJsonPath('is_default', false);

        expect($game->fresh()->is_default)->toBeFalse();
    });
});

describe('the manifest of the app', function () {
    it('is the one of the first game as it was a static file: its icons, its colours, and the garage as the start', function () {
        $response = $this->getJson('/api/v1/meta/manifest')->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('application/manifest+json')
            ->and($response->json())->toMatchArray([
                'id' => '/',
                'name' => 'RideIdle',
                'short_name' => 'RideIdle',
                'start_url' => '/garage',
                'scope' => '/',
                'display' => 'standalone',
                'background_color' => '#0e1013',
                'theme_color' => '#0e1013',
                'lang' => 'en',
            ])
            ->and(array_map(fn (array $icon): string => "{$icon['sizes']} {$icon['purpose']}", $response->json('icons')))->toBe(['192x192 any', '512x512 any', '512x512 maskable'])
            ->and(array_column($response->json('icons'), 'src'))->toBe(['/icons/icon-192.png', '/icons/icon-512.png', '/icons/icon-maskable-512.png']);
    });

    it('is the one of the game of the host: its name, its colour, its language, and its own icons with their real size', function () {
        $images = app(ContentImages::class);
        $icon = $images->store(squarePng(512, 'any'));
        $maskable = $images->store(squarePng(256, 'maskable'));
        gameWithContent(['icon' => $icon, 'icon_maskable' => $maskable, 'name' => 'Star Ships Of The Deep']);

        $manifest = $this->getJson('/api/v1/meta/manifest', ['X-Game' => 'ships'])->assertOk()->json();

        expect($manifest)->toMatchArray(['name' => 'Star Ships Of The Deep', 'short_name' => 'Star Ships', 'theme_color' => '#0b3d5c', 'background_color' => '#0b3d5c', 'lang' => 'pl', 'description' => 'Star Ships Of The Deep'])
            ->and($manifest['icons'])->toBe([
                ['src' => "/api/v1/media/{$icon}", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/api/v1/media/{$maskable}", 'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'maskable'],
            ]);
    });

    it('keeps the icons of the first game for a game that has none, and has an ETag', function () {
        gameWithContent();

        $response = $this->getJson('/api/v1/meta/manifest', ['X-Game' => 'ships'])->assertOk();

        expect(array_column($response->json('icons'), 'src'))->toBe(['/icons/icon-192.png', '/icons/icon-512.png', '/icons/icon-maskable-512.png'])
            ->and($response->headers->get('ETag'))->not->toBeNull();
    });
});

describe('the addresses that lead back to the app of a game', function () {
    it('are the one of the installation for the first game, and the domain of the game for another', function () {
        $current = app(CurrentGame::class);
        $ships = gameWithContent();

        expect($current->webUrl())->toBe(config('ride.web_url'))
            ->and($current->callbackUrl('google'))->toBeNull()
            ->and($current->within($ships, fn (): string => $current->webUrl()))->toBe(parse_url((string) config('ride.web_url'), PHP_URL_SCHEME).'://ships.test')
            ->and($current->within($ships, fn (): ?string => $current->callbackUrl('google')))->toBe(parse_url((string) config('ride.web_url'), PHP_URL_SCHEME).'://ships.test/api/v1/auth/google/callback')
            // A game that has no domain has the address of the installation.
            ->and($current->within(Game::query()->create(['slug' => 'nodomain', 'name' => 'No Domain']), fn (): string => $current->webUrl()))->toBe(config('ride.web_url'));
    });

    it('lead the link of an e-mail to the app of the game of the account', function () {
        Mail::fake();
        $ships = gameWithContent();
        $driver = app(CurrentGame::class)->within($ships, fn (): User => User::factory()->guest()->create());

        askForEmailLink($driver, [], ['X-Game' => 'ships'])->assertStatus(202);

        Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail): bool => str_starts_with($mail->url, parse_url((string) config('ride.web_url'), PHP_URL_SCHEME).'://ships.test/account/confirm?token='));
    });
});

describe('the icons of a game in the panel', function () {
    beforeEach(function () {
        app()->setLocale('pl');
        signedInAdmin(Admin::ROLE_EDITOR);
    });

    it('are kept when they are square and large enough: the file goes to the store of images and its name is the icon', function () {
        $png = squarePng(512);

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'ships', 'name' => 'Ships', 'locale' => 'en', 'theme_color' => '#0b3d5c', 'status' => 'draft', 'icon_upload' => UploadedFile::fake()->createWithContent('icon.png', $png)])
            ->call('create')
            ->assertHasNoFormErrors();

        $game = Game::query()->where('slug', 'ships')->firstOrFail();

        expect($game->icon)->toBe(hash('sha256', $png).'.png')
            ->and(app(ContentImages::class)->find((string) $game->icon)['bytes'])->toBe($png)
            ->and($game->icon_maskable)->toBeNull();
    });

    it('are refused when the picture is not square or too small, and nothing is kept', function () {
        foreach ([squarePng(100), (function (): string {
            // 300 x 200: not square.
            $png = squarePng(300);

            return substr_replace($png, pack('N', 200), 20, 4);
        })()] as $bad) {
            Livewire::test(CreateGame::class)
                ->fillForm(['slug' => 'ships', 'name' => 'Ships', 'locale' => 'en', 'theme_color' => '#0b3d5c', 'status' => 'draft', 'icon_upload' => UploadedFile::fake()->createWithContent('icon.png', $bad)])
                ->call('create')
                ->assertNotified();

            expect(Game::query()->where('slug', 'ships')->exists())->toBeFalse();
        }
    });

    it('can be taken off a game', function () {
        $icon = app(ContentImages::class)->store(squarePng(512));
        $game = Game::query()->create(['slug' => 'ships', 'name' => 'Ships', 'icon' => $icon]);

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['icon_remove' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($game->fresh()->icon)->toBeNull();
    });
});
