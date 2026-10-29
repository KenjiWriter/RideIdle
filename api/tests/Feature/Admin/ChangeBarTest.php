<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\ParameterCatalog;
use App\Filament\Pages\StudioPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\ContentDefinition;
use App\Models\Game;
use Livewire\Livewire;

// The bar of the changes that are not saved yet, and the preview of a game that follows its form. The bar and the preview are drawn in the browser from the form as it is typed;
// what the server gives them (the values as they were saved, the labels, the lines about what a change does) is what these tests look at.

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
});

/** The position of a number in its section, as the form names it (`p3`). */
function changesPosition(string $section, string $pointer): int
{
    $rules = JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));

    foreach (ParameterCatalog::ofTheGame()->parameters($rules, $section) as $position => $parameter) {
        if ($parameter->pointer() === $pointer) {
            return $position;
        }
    }

    throw new RuntimeException("No number at {$pointer} in {$section}.");
}

function changesGame(): Game
{
    return Game::query()->create(['slug' => 'statki', 'name' => 'Statki', 'domain' => 'statki.example.test', 'locale' => 'pl', 'theme_color' => '#0b3a5b', 'status' => Game::STATUS_LIVE, 'is_default' => false]);
}

describe('the page of a game', function () {
    it('has the bar of the changes and the preview, and the preview starts from the name of the game', function () {
        signedInAdmin();
        $game = changesGame();

        $this->get(GameResource::getUrl('edit', ['record' => $game]))
            ->assertOk()
            ->assertSee('data-test="changes-bar"', false)
            ->assertSee('data-test="game-preview"', false)
            ->assertSee('Tak to będzie wyglądać');
    });

    it('gives the bar the values as they were saved, the label of each field and what a change of it does', function () {
        signedInAdmin();
        $game = changesGame();

        $data = Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])->instance()->changesViewData();

        expect($data['baseline'])->toMatchArray(['name' => 'Statki', 'domain' => 'statki.example.test', 'theme_color' => '#0b3a5b', 'status' => Game::STATUS_LIVE])
            ->and($data['fields']['name']['label'])->not->toBe('')
            ->and($data['fields']['name']['effect'])->toContain('karcie przeglądarki')
            ->and($data['fields']['status']['options'])->toHaveKey(Game::STATUS_LIVE)
            ->and($data['fields']['domain']['effect'])->toContain('certyfikat')
            // A field that nothing is said about has no line.
            ->and($data['fields']['slug']['effect'] ?? null)->toBeNull();
    });

    it('takes what was saved for the new baseline, and the bar is made again from it', function () {
        signedInAdmin();
        $game = changesGame();

        $page = Livewire::test(EditGame::class, ['record' => $game->getRouteKey()]);
        $first = $page->instance()->changesViewData();

        $page->fillForm(['name' => 'Statki Kosmiczne'])->call('save')->assertHasNoFormErrors();

        $second = $page->instance()->changesViewData();

        expect($first['baseline']['name'])->toBe('Statki')
            ->and($second['baseline']['name'])->toBe('Statki Kosmiczne')
            ->and($second['version'])->toBeGreaterThan($first['version']);
    });
});

describe('the pages of the rules', function () {
    it('give the studio a bar from the document that the form starts from', function () {
        signedInAdmin();

        $page = Livewire::test(StudioPage::class);
        $data = $page->instance()->changesViewData();

        expect($data['version'])->toBe(1)
            ->and($data['fields'])->not->toBeEmpty()
            ->and(array_keys($data['fields']))->each->toStartWith('values.p');

        // Another section starts the form again, and with it the baseline.
        $page->fillForm(['section' => 'workshop']);
        $page->set('data.section', 'player');

        expect($page->instance()->changesViewData()['version'])->toBeGreaterThan(1);
    });

    it('make the bar again from a draft that was saved', function () {
        signedInAdmin();
        $position = changesPosition('premium', '/premium/speedup/minutes_per_coin');

        $page = Livewire::test(StudioPage::class)->fillForm(['section' => 'premium', 'values' => ["p{$position}" => 10]]);
        $before = $page->instance()->changesViewData()['version'];

        $page->call('save')->assertHasNoFormErrors();

        $after = $page->instance()->changesViewData();

        expect($after['version'])->toBeGreaterThan($before)
            ->and($after['baseline']["values.p{$position}"])->toEqual(10);
    });

    it('are on the page of a car as well', function () {
        signedInAdmin();
        $car = ContentDefinition::query()->where('kind', ContentDefinition::KIND_VEHICLE)->orderBy('id')->firstOrFail();

        $this->get(VehicleResource::getUrl('edit', ['record' => $car]))->assertOk()->assertSee('data-test="changes-bar"', false);
    });
});
