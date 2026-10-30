<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\ParameterCatalog;
use App\Content\Studio\SimpleMode;
use App\Filament\Pages\StudioPage;
use App\Models\ContentDefinition;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

// The simple mode of the studio: the numbers that set the pace and the money of the game, picked by their pointer; every section saying what changing it does; and the advanced
// mode with every number.

function simpleRules(): array
{
    return JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
});

describe('the pointers of the simple mode', function () {
    it('are matched whole, and a star stands for one name', function () {
        $mode = new SimpleMode(['/tracks/*/pay_cents_per_hour', '/fuel/price_cents_per_liter']);
        $numbers = ParameterCatalog::ofTheGame()->parameters(simpleRules(), 'tracks');
        $kept = array_map(fn ($parameter) => $parameter->pointer(), $mode->only($numbers));

        expect($kept)->not->toBeEmpty()
            ->and($kept)->each->toEndWith('/pay_cents_per_hour')
            ->and($kept)->each->toStartWith('/tracks/');
    });

    it('keep their place in the section, so that a number is saved where it was', function () {
        $numbers = ParameterCatalog::ofTheGame()->parameters(simpleRules(), 'fuel');
        $only = (new SimpleMode(['/fuel/price_cents_per_liter']))->only($numbers);

        expect(array_keys($only))->toBe([0])
            ->and($only[0]->pointer())->toBe('/fuel/price_cents_per_liter');
    });

    it('are all numbers that the rules have: a pointer that matches nothing is a typo or a number that was renamed', function () {
        $catalog = ParameterCatalog::ofTheGame();
        $document = simpleRules();
        $pointers = [];

        foreach (array_keys($catalog->sections($document)) as $section) {
            foreach ($catalog->parameters($document, $section) as $parameter) {
                $pointers[] = $parameter->pointer();
            }
        }

        foreach ((array) config('ride.studio.simple') as $pattern) {
            $matches = array_filter($pointers, fn (string $pointer): bool => preg_match('#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#', $pointer) === 1);

            expect($matches)->not->toBeEmpty("No number of the rules at {$pattern}.");
        }
    });
});

describe('the studio', function () {
    it('starts in the simple mode, with the numbers that matter and a line about how many there are', function () {
        signedInAdmin();

        Livewire::test(StudioPage::class)
            ->assertFormFieldExists('simple')
            ->assertFormFieldExists('values.p0')
            ->assertFormFieldDoesNotExist('values.p1')
            ->assertSee('Tryb prosty: 1 z 2 liczb tej sekcji');
    });

    it('shows every number of the section in the advanced mode', function () {
        signedInAdmin();

        Livewire::test(StudioPage::class)
            ->fillForm(['simple' => false])
            ->assertFormFieldExists('values.p0')
            ->assertFormFieldExists('values.p1')
            ->assertDontSee('Tryb prosty: ');
    });

    it('offers only the sections that have a number to show in the simple mode, and all of them in the advanced', function () {
        signedInAdmin();

        $simple = Livewire::test(StudioPage::class)->assertSee('Paliwo (1)');
        $advanced = Livewire::test(StudioPage::class)->fillForm(['simple' => false])->assertSee('Paliwo (2)');

        // The drivetrain has no number among the ones of the pace and the money, and the matrix of the tires neither.
        $simple->assertDontSee('Macierz opon (')->assertDontSee('Napęd (');
        $advanced->assertSee('Macierz opon (')->assertSee('Napęd (');
    });

    it('says what changing the numbers of a section does', function () {
        signedInAdmin();

        Livewire::test(StudioPage::class)->assertSee('Co to zmieni: koszt paliwa');
    });

    it('does not take what is not shown for a change: a number that was left out keeps what the document has', function () {
        signedInAdmin();
        $rules = simpleRules();

        Livewire::test(StudioPage::class)
            ->fillForm(['section' => 'fuel', 'values' => ['p0' => 3]])
            ->call('save')
            ->assertHasNoFormErrors();

        $draft = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail()->draftDocument();

        expect($draft['fuel']['price_cents_per_liter'])->toBe(300)
            ->and($draft['fuel']['reference_power_kw'])->toBe($rules['fuel']['reference_power_kw']);
    });

    it('does not put the mode or the section in the bar of the changes', function () {
        signedInAdmin();

        $fields = Livewire::test(StudioPage::class)->instance()->changesViewData()['fields'];

        expect($fields)->not->toHaveKey('simple')->not->toHaveKey('section')->not->toHaveKey('sliders');
    });
});

describe('the lines about what a section changes', function () {
    it('are there for every section of the rules, in every language of the panel', function () {
        $sections = array_keys(ParameterCatalog::ofTheGame()->sections(simpleRules()));

        foreach (['pl', 'en', 'fr', 'de', 'pt_BR'] as $locale) {
            foreach ($sections as $section) {
                expect(Lang::has("admin.studio.effects.{$section}", $locale))->toBeTrue("No line for {$section} in {$locale}.");
            }
        }
    });
});
