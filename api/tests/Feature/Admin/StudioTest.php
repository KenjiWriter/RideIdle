<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\ParameterCatalog;
use App\Content\Studio\Unit;
use App\Filament\Pages\StudioPage;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use App\Services\ContentPublisher;
use Livewire\Livewire;

// The studio: every number of the rules found by walking the document with its schema, shown in the unit a person thinks in, kept inside the limits of the schema, and
// saved as the draft of the rules like every other page's.

function studioRules(): array
{
    return JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
}

/** The position of a number in its section, as the form names it (`p3`). */
function studioPosition(string $section, string $pointer): int
{
    foreach (ParameterCatalog::ofTheGame()->parameters(studioRules(), $section) as $position => $parameter) {
        if ($parameter->pointer() === $pointer) {
            return $position;
        }
    }

    throw new RuntimeException("No number at {$pointer} in {$section}.");
}

function studioDraft(): array
{
    return ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail()->draftDocument();
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
});

describe('the units', function () {
    it('are told by the name: a percent, seconds, dollars, liters, and nothing for the rest', function () {
        expect(Unit::of('other_cars_xp_ppm'))->toBe(Unit::Percent)
            ->and(Unit::of('time_cut_ppm_per_level'))->toBe(Unit::Percent)
            ->and(Unit::of('base_time_ms'))->toBe(Unit::Seconds)
            ->and(Unit::of('price_cents'))->toBe(Unit::Dollars)
            ->and(Unit::of('fuel_cents_per_hour'))->toBe(Unit::Dollars)
            ->and(Unit::of('fuel_per_lap_ml'))->toBe(Unit::Liters)
            ->and(Unit::of('xp_per_lap'))->toBe(Unit::Plain)
            // A word that only contains the letters of a unit is not the unit.
            ->and(Unit::of('permission'))->toBe(Unit::Plain)
            ->and(Unit::of('items'))->toBe(Unit::Plain);
    });

    it('show a number as a person reads it, and give back the whole number of the document', function () {
        expect(Unit::Percent->display(1_300_000))->toBe(130)
            ->and(Unit::Percent->display(1_450_000))->toBe(145)
            ->and(Unit::Percent->display(12_500))->toBe(1.25)
            ->and(Unit::Percent->raw(145))->toBe(1_450_000)
            ->and(Unit::Percent->raw(1.25))->toBe(12_500)
            ->and(Unit::Seconds->display(8_200))->toBe(8.2)
            ->and(Unit::Seconds->raw(8.2))->toBe(8_200)
            ->and(Unit::Dollars->display(570))->toBe(5.7)
            ->and(Unit::Dollars->raw(5.7))->toBe(570)
            ->and(Unit::Liters->display(1_500))->toBe(1.5)
            ->and(Unit::Plain->display(30))->toBe(30)
            ->and(Unit::Plain->raw(30.4))->toBe(30)
            ->and(Unit::Plain->raw(2.35, integer: false))->toBe(2.35);
    });
});

describe('the catalog', function () {
    it('finds every number of the rules (and only numbers), in the sections of the document', function () {
        $catalog = ParameterCatalog::ofTheGame();
        $rules = studioRules();
        $sections = $catalog->sections($rules);

        // The version of the schema is not a rule; the other 768 numbers of the file are.
        expect($sections)->not->toHaveKey('schema')
            ->and(array_sum($sections))->toBe(768)
            ->and(array_keys($sections))->toContain('player', 'workshop', 'tracks', 'premium', 'travel');

        foreach ($catalog->parameters($rules, 'workshop') as $parameter) {
            $at = $rules;

            foreach ($parameter->path as $key) {
                $at = $at[$key];
            }

            expect($at)->toBe($parameter->value);
        }
    });

    it('knows the limits the schema gives a number, and what it says of it', function () {
        $parameters = collect(ParameterCatalog::ofTheGame()->parameters(studioRules(), 'workshop'))->keyBy(fn ($parameter): string => $parameter->pointer());

        expect($parameters['/workshop/max_queue']->min)->toBe(1)
            ->and($parameters['/workshop/max_queue']->max)->toBe(20)
            ->and($parameters['/workshop/upgrade/base_time_ms']->unit)->toBe(Unit::Seconds)
            ->and($parameters['/workshop/upgrade/base_time_ms']->display())->toBe(8.2)
            ->and($parameters['/workshop/upgrade/time_growth_ppm']->display())->toBe(130)
            ->and($parameters['/workshop/upgrade/base_time_ms']->groupDescription)->toContain('Upgrading a part');
    });

    it('names the numbers of a list by their place, and groups them by where they are', function () {
        $parameters = collect(ParameterCatalog::ofTheGame()->parameters(studioRules(), 'workshop'))->keyBy(fn ($parameter): string => $parameter->pointer());

        expect($parameters['/workshop/bays/price_cents/1']->label)->toBe('Price 2')
            ->and($parameters['/workshop/bays/price_cents/1']->unit)->toBe(Unit::Dollars)
            ->and($parameters['/workshop/bays/price_cents/1']->group)->toBe('Workshop › Bays › Price')
            ->and($parameters['/workshop/equipment/tire_machine/levels/1/time_ppm']->group)->toContain('Manual');
    });

    it('leaves out what is not a number of the rules: texts, a key the schema does not know, an empty object', function () {
        $rules = studioRules();
        $rules['mystery'] = ['number' => 5];
        $rules['player']['unknown_number'] = 7;
        $rules['premium']['empty'] = new stdClass;

        $catalog = ParameterCatalog::ofTheGame();

        expect($catalog->sections($rules))->not->toHaveKey('mystery')
            ->and(collect($catalog->parameters($rules, 'player'))->pluck('key')->all())->not->toContain('unknown_number')
            ->and(array_sum($catalog->sections($rules)))->toBe(768);
    });

    it('sets a number to what a person wrote, in their unit, and keeps it inside the limits', function () {
        $catalog = ParameterCatalog::ofTheGame();
        $rules = studioRules();
        $max = studioPosition('player', '/player/max_level');
        $share = studioPosition('player', '/player/other_cars_xp_ppm');

        $changed = $catalog->apply($rules, 'player', [$max => 40, $share => 25]);

        expect($changed['player']['max_level'])->toBe(40)->and($changed['player']['other_cars_xp_ppm'])->toBe(250_000);

        // Only those two differ.
        $same = $changed;
        $same['player']['max_level'] = $rules['player']['max_level'];
        $same['player']['other_cars_xp_ppm'] = $rules['player']['other_cars_xp_ppm'];

        expect($same)->toEqual($rules);

        $beyond = $catalog->apply($rules, 'player', [$max => 5_000, $share => 250]);

        expect($beyond['player']['max_level'])->toBe(1_000)->and($beyond['player']['other_cars_xp_ppm'])->toBe(1_000_000);
    });

    it('does not touch a number it was given nothing for, or a text', function () {
        $rules = studioRules();

        expect(ParameterCatalog::ofTheGame()->apply($rules, 'player', [0 => 'abc', 1 => null]))->toEqual($rules);
    });

    it('says which numbers are not what the live pack has', function () {
        $catalog = ParameterCatalog::ofTheGame();
        $live = studioRules();
        $rules = $catalog->apply($live, 'workshop', [studioPosition('workshop', '/workshop/max_queue') => 6]);

        $changed = $catalog->differences($rules, $live);

        expect($changed)->toHaveCount(1)
            ->and($changed[0]->pointer())->toBe('/workshop/max_queue')
            ->and($changed[0]->display())->toBe(6)
            ->and($changed[0]->liveDisplay())->toBe(4)
            ->and($catalog->differences($live, $live))->toBe([]);
    });
});

describe('the page', function () {
    it('is for any admin to open, and starts on the first section with its numbers', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        // The simple mode is the way in: the section that has two numbers shows the one of them that sets the money of the game.
        $this->get('/admin/studio')->assertOk()->assertSee('Studio parametrów')->assertSee('Paliwo (1)');
    });

    it('keeps the number that was changed as a draft of the rules, in the whole number of the document, and nothing else', function () {
        signedInAdmin();
        $rules = studioRules();
        $position = studioPosition('premium', '/premium/speedup/minutes_per_coin');

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'premium', 'sliders' => true, 'values' => ["p{$position}" => 10]])
            ->call('save')
            ->assertHasNoFormErrors();

        $draft = studioDraft();

        expect($draft['premium']['speedup']['minutes_per_coin'])->toBe(10);

        $draft['premium']['speedup']['minutes_per_coin'] = 5;

        expect($draft)->toEqual($rules);
    });

    it('takes a percent as a percent: 25 is a share of 250 000', function () {
        signedInAdmin();
        $position = studioPosition('player', '/player/other_cars_xp_ppm');

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'player', 'sliders' => false, 'values' => ["p{$position}" => 25]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(studioDraft()['player']['other_cars_xp_ppm'])->toBe(250_000);
    });

    it('keeps a number inside the limits of the schema', function () {
        signedInAdmin();
        $position = studioPosition('workshop', '/workshop/max_queue');

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'workshop', 'sliders' => false, 'values' => ["p{$position}" => 500]]);

        // The form states the limit (a field with a limit refuses a number beyond it), and a number that gets to the document is cut to it all the same.
        $document = ParameterCatalog::ofTheGame()->apply(studioRules(), 'workshop', [$position => 500]);

        expect($document['workshop']['max_queue'])->toBe(20);
    });

    it('says what the live pack has where the number is not that, and the draft shows it', function () {
        signedInAdmin();
        $position = studioPosition('premium', '/premium/speedup/minutes_per_coin');

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'premium', 'values' => ["p{$position}" => 8]])
            ->call('save');

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'premium'])
            ->assertSee('żywa paczka: 5');
    });

    it('can be given the numbers as fields instead of sliders', function () {
        signedInAdmin();

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'workshop', 'sliders' => false])
            ->assertFormSet(['sliders' => false, 'section' => 'workshop']);
    });

    it('shows the sections in the language of the panel', function () {
        signedInAdmin();

        expect(StudioPage::sectionName('workshop'))->toBe('Warsztat')
            ->and(StudioPage::sectionName('no_such_section'))->toBe('No Such Section');
    });
});

describe('the preview of the balance', function () {
    it('tells what the economy does with the drafts and beside it what it does with the live pack, and with no draft they are the same', function () {
        $preview = app(ContentPublisher::class)->preview();

        expect($preview['errors'])->toBe([])
            ->and($preview['changes'])->toBe(0)
            ->and(array_column($preview['gates'], 'passed'))->each->toBeTrue()
            ->and($preview['now']['pace'])->not->toBe([])
            ->and($preview['now']['ladder'])->not->toBe([])
            ->and($preview['now'])->toBe($preview['live']);
    });

    it('shows a change of a number as a change of the pace', function () {
        $admin = signedInAdmin();
        $definition = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail();
        $position = studioPosition('fuel', '/fuel/price_cents_per_liter');

        // A liter of fuel five times as dear: the cars earn less, so the cars are bought later.
        app(ContentDrafts::class)->save($definition, ParameterCatalog::ofTheGame()->apply(studioRules(), 'fuel', [$position => 10]), $admin);

        $preview = app(ContentPublisher::class)->preview();

        expect($preview['changes'])->toBe(1)
            // The days the cars are bought on may not move (the pace is bound by the level of the player), but what a level earns an hour does.
            ->and($preview['now']['ladder'])->not->toBe($preview['live']['ladder'])
            ->and(collect($preview['now']['ladder'])->sum('game'))->toBeLessThan(collect($preview['live']['ladder'])->sum('game'));
    });

    it('says why a draft cannot be measured instead of measuring it', function () {
        $admin = signedInAdmin();
        $definition = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail();
        $rules = studioRules();
        // A track that is in a city that does not exist: the rules of the world refuse it, and a draft like that is not even kept, so the document is put in by hand.
        $rules['tracks'][array_key_first($rules['tracks'])]['region'] = 'nowhere';
        $definition->forceFill(['draft' => JsonDocument::encode($rules), 'draft_saved_at' => now()])->save();

        $preview = app(ContentPublisher::class)->preview();

        expect($preview['errors'])->not->toBe([])
            ->and($preview['gates'])->toBe([])
            ->and($preview['now'])->toBe([]);

        expect($admin)->not->toBeNull();
    });

    it('is an action of the page, and its view shows the gates, the pace and the ladder of the live pack beside those of the draft', function () {
        signedInAdmin();

        Livewire::test(StudioPage::class)->assertActionExists('preview');

        $html = view('filament.studio.preview', ['report' => app(ContentPublisher::class)->preview()])->render();

        expect($html)->toContain('Bramki symulatorów')
            ->and($html)->toContain('tracks:simulate --strict')
            ->and($html)->toContain('przechodzi')
            ->and($html)->toContain('Tempo kupowania aut')
            ->and($html)->toContain('Zarobek na godzinę')
            ->and($html)->not->toContain('NIE przechodzi');
    });
});
