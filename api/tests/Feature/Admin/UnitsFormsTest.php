<?php

declare(strict_types=1);

use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\JsonDocument;
use App\Content\Units\Measures;
use App\Filament\Pages\RulesJsonPage;
use App\Filament\Pages\TutorialPage;
use App\Filament\Pages\WorldPage;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Models\ContentDefinition;
use Livewire\Livewire;

// The forms of the panel in a game that counts in nautical miles and knots: what a person writes is in the units of the game, and the document keeps kilometres and km/h.

// The panel speaks Polish to the people who run it (the middleware that sets it is not on the path of a component that is tested alone).
beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false);
    app()->setLocale('pl');
});

/** The rules of the repository with the units of a game of ships, as a draft of the rules (what the world page works on). */
function draftOfShips(): void
{
    $rules = JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
    $rules['units'] = Measures::preset('nautical')->toRules();

    Livewire::test(RulesJsonPage::class)->set('data.json', JsonDocument::encode($rules))->call('save')->assertHasNoFormErrors();
}

describe('the world page', function () {
    it('shows the roads and the tracks in the units of the game, and keeps kilometres and metres in the draft', function () {
        signedInAdmin();
        draftOfShips();

        $page = Livewire::test(WorldPage::class);
        $road = array_values($page->get('data.roads'))[0];
        $track = array_values($page->get('data.tracks'))[0];

        // 38 km is 20.518359 nautical miles, 2 600 m is 1.403888 of them and 124.8 km/h is 67.386609 knots: written so that they come back to the same number.
        expect($road['km'])->toBe(20.518359)->and($track['length'])->toBe(1.403888)->and($track['speed'])->toBe(67.386609);

        $page->call('save')->assertHasNoFormErrors();

        $draft = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail()->draftDocument();

        expect($draft['roads'][0]['km'])->toBe(38)
            ->and($draft['tracks']['zlomowo-street'])->toMatchArray(['length_m' => 2_600, 'speed_kmh' => 124.8])
            ->and($draft['units']['lap']['metres'])->toBe(1852);
    });

    it('takes what a person writes in nautical miles and knots: a road of 20 miles is 37 km, a lap of 1.4 miles 2 593 m and 20 knots 37.04 km/h', function () {
        signedInAdmin();
        draftOfShips();

        $page = Livewire::test(WorldPage::class);
        // The items of a repeater are kept under keys of their own, not 0, 1, 2.
        $road = array_key_first($page->get('data.roads'));
        $track = array_key_first($page->get('data.tracks'));

        $page->set("data.roads.{$road}.km", 20)
            ->set("data.tracks.{$track}.length", 1.4)
            ->set("data.tracks.{$track}.speed", 20)
            ->call('save')
            ->assertHasNoFormErrors();

        $draft = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail()->draftDocument();

        expect($draft['roads'][0]['km'])->toBe(37)
            ->and($draft['tracks']['zlomowo-street'])->toMatchArray(['length_m' => 2_593, 'speed_kmh' => 37.04]);
    });
});

describe('the form of a car', function () {
    it('takes the top speed in the unit of speed of the game that is live, and keeps whole km/h in the draft', function () {
        signedInAdmin();
        contentPacks()->import(contentWith(['rules/starter' => fn (array $rules): array => [...$rules, 'units' => Measures::preset('nautical')->toRules()]]), '2026.10.31', activate: true, checkTables: false);
        readFromDatabase();

        $state = DefinitionForm::toState('vehicle', json_decode((string) repositoryContent()->raw('vehicles/rdzak-gt-4'), true));

        // 94.5 knots written in the box are 175.014 km/h, and the document keeps whole km/h: 175.
        Livewire::test(CreateVehicle::class)->fillForm([...$state, 'slug' => 'rdzak-gt-9'])->set('data.top_speed_kmh', 94.5)->call('create')->assertHasNoFormErrors();

        $definition = ContentDefinition::query()->where('kind', 'vehicle')->where('slug', 'rdzak-gt-9')->firstOrFail();

        expect($definition->draftDocument()['top_speed_kmh'])->toBe(175);

        // The form of the saved car shows it in knots again (175 km/h are 94.492441 of them), and saving it as it is changes nothing.
        $edit = Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()]);

        expect($edit->get('data.top_speed_kmh'))->toBe(94.492441);

        $edit->call('save')->assertHasNoFormErrors();

        expect($definition->fresh()->draftDocument()['top_speed_kmh'])->toBe(175);
    });
});

describe('the tutorial page', function () {
    it('shows the mileage of the car of the exchange in the unit of travel of the game, keeps no draft when it is saved as it is, and takes what is written in it', function () {
        signedInAdmin();
        contentPacks()->import(contentWith(['rules/starter' => fn (array $rules): array => [...$rules, 'units' => Measures::preset('nautical')->toRules()]]), '2026.10.31', activate: true, checkTables: false);
        readFromDatabase();

        $page = Livewire::test(TutorialPage::class);

        // 280 000 km are 151 187.041037 nautical miles.
        expect($page->get('data.listing_mileage_km'))->toBe(Measures::preset('nautical')->travel->inBox(280_000_000));

        $page->call('save')->assertHasNoFormErrors();

        $definition = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'tutorial')->firstOrFail();

        expect($definition->hasDraft())->toBeFalse();

        // 100 nautical miles are 185.2 km, and the document keeps whole kilometres.
        $page->set('data.listing_mileage_km', 100)->call('save')->assertHasNoFormErrors();

        expect($definition->fresh()->draftDocument()['script']['exchange_listing']['mileage_km'])->toBe(185);
    });
});
