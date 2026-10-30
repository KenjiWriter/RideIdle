<?php

declare(strict_types=1);

use App\Content\Definitions\WorldForm;
use App\Filament\Pages\StudioPage;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Support\FieldHelp;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

// The help catalog as the forms use it: a field is given its label and its text by its key, a choice shows readable names, the studio takes its numbers' texts by the pattern of
// their pointer, and a number that people enter as a percent is stored as the whole number the document keeps.

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
    FieldHelp::flush();
});

describe('the entry of a key', function () {
    it('is read in the language in use, and English stands in for a language that has none', function () {
        $pl = FieldHelp::entry('vehicle.power_kw', 'pl');
        $en = FieldHelp::entry('vehicle.power_kw', 'en');

        expect($pl['label'])->not->toBe($en['label'])
            ->and(FieldHelp::entry('vehicle.power_kw', 'xx'))->toBe($en)
            ->and(FieldHelp::own('vehicle.power_kw', 'xx'))->toBeNull()
            ->and(FieldHelp::entry('vehicle.no_such_field'))->toBeNull();
    });

    it('is found by the area before the first dot, and by the pattern of a pointer for a number of the rules', function () {
        expect(FieldHelp::entry('guide.vehicle', 'en'))->not->toBeNull()
            ->and(FieldHelp::forPattern('/fuel/price_cents_per_liter'))->not->toBeNull()
            ->and(FieldHelp::forPattern(''))->toBeNull();
    });

    it('is made into the text under a field: what it changes, an example, the range and a line for each option, with the words put in place', function () {
        $html = (string) FieldHelp::html([
            'help' => 'Kwadrat o boku co najmniej :min px.',
            'example' => 'np. :min',
            'range' => ':min – :max',
            'options' => ['a' => ['label' => 'Opcja A', 'help' => 'Pierwsza.'], 'b' => ['label' => 'B', 'help' => '']],
        ], ['min' => 192, 'max' => 1024]);

        expect($html)->toContain('Kwadrat o boku co najmniej 192 px.')
            ->and($html)->toContain('<strong>Przykład:</strong> np. 192')
            ->and($html)->toContain('192 – 1024')
            ->and($html)->toContain('<li><strong>Opcja A</strong>: Pierwsza.</li>')
            // An option with no line says nothing, rather than an empty bullet.
            ->and($html)->not->toContain('<li><strong>B</strong>');
    });

    it('is escaped, so that a text of the catalog cannot put markup in the page', function () {
        expect((string) FieldHelp::html(['help' => '<script>alert(1)</script>']))->toContain('&lt;script&gt;')->not->toContain('<script>');
    });

    it('says the labels of the options of a choice, and the value where the catalog has none', function () {
        $labels = FieldHelp::options('part.stat_name', ['grip', 'no_such_stat']);

        expect($labels['grip'])->toBe(FieldHelp::entry('part.stat_name')['options']['grip']['label'])
            ->and($labels['grip'])->not->toBe('grip')
            ->and($labels['no_such_stat'])->toBe('no_such_stat');
    });
});

describe('a field', function () {
    it('takes its label and its text from the catalog by its key, and is marked with the key', function () {
        $field = TextInput::make('power_kw')->helpKey('vehicle.power_kw');

        expect($field->getExtraAttributes()['data-help'])->toBe('vehicle.power_kw')
            ->and($field->getLabel())->toBe(FieldHelp::entry('vehicle.power_kw')['label'])
            ->and((string) FieldHelp::helper('vehicle.power_kw'))->toContain(e(FieldHelp::entry('vehicle.power_kw')['help']));
    });

    it('is left as the form made it when the catalog has nothing for its key, and still marked', function () {
        $field = TextInput::make('x')->label('Mine')->helpKey('vehicle.no_such_field');

        expect($field->getLabel())->toBe('Mine')
            ->and($field->getExtraAttributes()['data-help'])->toBe('vehicle.no_such_field');
    });

    it('speaks the language of the panel when the panel is drawn', function () {
        app()->setLocale('en');
        $field = TextInput::make('power_kw')->helpKey('vehicle.power_kw');

        expect($field->getLabel())->toBe(FieldHelp::entry('vehicle.power_kw', 'en')['label']);

        app()->setLocale('pl');

        expect($field->getLabel())->toBe(FieldHelp::entry('vehicle.power_kw', 'pl')['label']);
    });
});

describe('the pages of the panel', function () {
    it('draw the form of a car with the labels and the texts of the catalog, and its guide', function () {
        signedInAdmin();

        $this->get(CreateVehicle::getUrl())
            ->assertOk()
            ->assertSee(FieldHelp::entry('vehicle.power_kw')['label'])
            ->assertSee(e(FieldHelp::entry('vehicle.power_kw')['help']), false)
            ->assertSee(FieldHelp::entry('guide.vehicle')['label'])
            ->assertSee('data-help="vehicle.power_kw"', false);
    });

    it('show a choice with readable names and a line for each option', function () {
        signedInAdmin();

        $this->get(CreateVehicle::getUrl())
            ->assertOk()
            ->assertSee(FieldHelp::entry('vehicle.drivetrain')['options']['awd']['label'])
            ->assertSee(e(FieldHelp::entry('vehicle.drivetrain')['options']['awd']['help']), false);
    });

    it('offer the statistics of a part by their names, and not by their keys', function () {
        signedInAdmin();

        $page = Livewire::test(CreatePart::class)->set('data.stats', [['key' => 'grip', 'value' => 10]])->instance();
        $field = collect($page->form->getFlatFields(withHidden: true))->first(fn ($field): bool => ($field->getExtraAttributes()['data-help'] ?? null) === 'part.stat_name');

        expect($field->getOptions()['grip'])->toBe(FieldHelp::entry('part.stat_name')['options']['grip']['label'])
            ->and($field->getOptions()['grip'])->not->toBe('grip');
    });

    it('give the studio the label and the text of a number from the catalog, by the pattern of its pointer', function () {
        signedInAdmin();

        $label = FieldHelp::forPattern('/fuel/price_cents_per_liter')['label'];

        Livewire::test(StudioPage::class)->assertSee($label)->assertSee('data-help="rules./fuel/price_cents_per_liter"', false);
    });
});

describe('a number that people enter as a percent', function () {
    it('is stored as the whole number of the document, and shown as the percent it is', function () {
        $rules = json_decode((string) repositoryContent()->raw('rules/starter'), true);
        $region = array_key_first($rules['regions']);
        $stored = $rules['regions'][$region]['weather_ppm'];

        $state = WorldForm::toState($rules);
        $line = $state['regions'][0]['weather'][0];

        expect($line)->toHaveKey('percent')->not->toHaveKey('ppm')
            ->and($line['percent'])->toEqual(array_values($stored)[0] / 10_000);

        // The weather is given as 12.5 %: the document keeps 125 000.
        $state['regions'][0]['weather'][0]['percent'] = '12.5';

        expect(array_values(WorldForm::apply($rules, $state)['regions'][$region]['weather_ppm'])[0])->toBe(125_000);
    });

    it('comes back the same when nothing was touched', function () {
        $rules = json_decode((string) repositoryContent()->raw('rules/starter'), true);

        expect(WorldForm::apply($rules, WorldForm::toState($rules))['regions'])->toEqual($rules['regions']);
    });
});
