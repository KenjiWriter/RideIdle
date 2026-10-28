<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Filament\Pages\EffectPreviewPage;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use Livewire\Livewire;

// The preview of what a part does to a car, in the panel: a part and a car are chosen, and what it does to the stats and to the laps on every track is counted
// on the content as it is being worked on.

beforeEach(function () {
    contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
    app()->setLocale('pl');
});

it('is for any admin to open', function () {
    signedInAdmin(Admin::ROLE_EDITOR);

    $this->get('/admin/preview')->assertOk();
});

it('counts what a part does to the test car on every track', function () {
    signedInAdmin();

    Livewire::test(EffectPreviewPage::class)
        ->assertSee(__('admin.preview.nothing_yet'))
        ->assertFormSet(['car' => 'rdzak-gt-4', 'rarity' => 1])
        ->fillForm(['part' => 'street-tires-t2'])
        ->call('count')
        ->assertHasNoFormErrors()
        ->assertSee(__('admin.preview.baseline.stock'))
        ->assertSee(__('admin.preview.dry'))
        ->assertSee('Sprint uliczny Złomowo');
});

it('counts a part that is only a draft against the part that the car has in that slot', function () {
    $admin = signedInAdmin();
    $tires = JsonDocument::decode((string) repositoryContent()->raw('parts/street-tires-t2'));
    $tires['stats'] = ['grip' => 140, 'handling' => 138, 'braking' => 140];
    app(ContentDrafts::class)->create('part', [...$tires, 'slug' => 'street-tires-t9'], $admin);

    Livewire::test(EffectPreviewPage::class)
        ->fillForm(['part' => 'street-tires-t9', 'rarity' => 3])
        ->call('count')
        ->assertSee(__('admin.preview.baseline.shop', ['part' => 'street-tires-t2']))
        ->assertSee('street-tires-t9');

    expect(ContentDefinition::query()->where('slug', 'street-tires-t9')->first()->status)->toBe(ContentDefinition::STATUS_DRAFT);
});

it('needs a part', function () {
    signedInAdmin();

    Livewire::test(EffectPreviewPage::class)->fillForm(['part' => null])->call('count')->assertHasFormErrors(['part']);
});
