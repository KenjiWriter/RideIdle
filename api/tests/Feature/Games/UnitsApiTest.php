<?php

declare(strict_types=1);

use App\Content\Units\Measures;
use App\Games\CurrentGame;
use App\Models\Game;

// The units of a game reach the app with the game (`GET /api/v1/meta/game`), and the length of a track reaches it with the track (`GET /api/v1/map`): the numbers of
// the API stay metres, kilometres and km/h, and the app turns them.

/** A game with the rules of the repository and the units of a preset in them. */
function gameCountingIn(string $slug, string $preset): Game
{
    $game = Game::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'domain' => "{$slug}.test", 'locale' => 'en', 'theme_color' => '#0b3d5c', 'status' => Game::STATUS_LIVE, 'is_default' => false]);
    $content = contentWith(['rules/starter' => fn (array $rules): array => [...$rules, 'units' => Measures::preset($preset)->toRules()]]);

    app(CurrentGame::class)->within($game, fn () => contentPacks()->import($content, '2026.10.30', activate: true, checkTables: false));

    return $game;
}

describe('the units of the game in the answer of the game', function () {
    it('are the metres, the kilometres and the km/h of the car game, with their names in every language, when the rules say nothing', function () {
        $units = $this->getJson('/api/v1/meta/game')->assertOk()->json('units');

        expect($units['lap'])->toMatchArray(['metres' => 1, 'decimals' => 0])
            ->and($units['lap']['short'])->toBe(['pl' => 'm', 'en' => 'm', 'fr' => 'm', 'de' => 'm', 'pt-BR' => 'm'])
            ->and($units['lap']['name']['pl'])->toBe('metr')
            ->and($units['travel'])->toMatchArray(['metres' => 1000, 'decimals' => 0])
            ->and($units['speed'])->toMatchArray(['kmh' => 1, 'decimals' => 0])
            ->and($units['speed']['short']['en'])->toBe('km/h')
            ->and($units['fuel_economy_per'])->toBe(100);
    });

    it('are the ones that the rules of the game say: nautical miles and knots for a game of ships, with the fuel per mile', function () {
        gameCountingIn('ships', 'nautical');

        $units = $this->getJson('/api/v1/meta/game', ['X-Game' => 'ships'])->assertOk()->json('units');

        expect($units['lap'])->toMatchArray(['metres' => 1852, 'decimals' => 2])
            ->and($units['lap']['short']['en'])->toBe('nm')
            ->and($units['lap']['name']['pl'])->toBe('mila morska')
            ->and($units['travel'])->toMatchArray(['metres' => 1852, 'decimals' => 0])
            ->and($units['speed'])->toMatchArray(['kmh' => 1.852, 'decimals' => 0])
            ->and($units['speed']['short']['en'])->toBe('kn')
            ->and($units['fuel_economy_per'])->toBe(1);
    });

    it('are the car game for a game that has no pack live yet: the units are asked for without breaking', function () {
        $game = Game::query()->create(['slug' => 'bare', 'name' => 'Bare', 'domain' => 'bare.test', 'locale' => 'en', 'theme_color' => '#0b3d5c', 'status' => Game::STATUS_DRAFT, 'is_default' => false]);

        expect(app(CurrentGame::class)->within($game, fn (): Measures => Measures::ofTheGame()))->toEqual(Measures::metric());
    });
});

describe('the length of a track in the answer of the map', function () {
    it('is said in metres for every track of the city, and for the track that is shown before the list', function () {
        [$driver] = driverWithRun(['seed' => str_repeat('0', 32)]);

        $cities = collect($this->getJson('/api/v1/map', bearerFor($driver))->assertOk()->json('data.cities'))->keyBy('slug');
        $tracks = collect($cities['zlomowo']['tracks'])->keyBy('slug');

        expect($tracks['zlomowo-street']['length_m'])->toBe(2_600)
            ->and($tracks['zlomowo-yard-dash']['length_m'])->toBe(420)
            ->and($tracks['zlomowo-ring-road']['length_m'])->toBe(10_400)
            ->and($cities['zlomowo']['track']['length_m'])->toBe(2_600);
    });
});
