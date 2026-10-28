<?php

declare(strict_types=1);

use App\Content\Definitions\DefinitionForm;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Widgets\LivePackWidget;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentDefinition;
use Livewire\Livewire;

// The cars and the parts in the panel: listed with their status and their drafts, made and changed as drafts through a form that the schema judges, and moved in
// their life by a publisher. The content of the repository is what is in the database.

/** The state of the form of a document of the repository, for another slug. @return array<string, mixed> */
function formOf(string $key, array $changes = []): array
{
    [$folder] = explode('/', $key);
    $state = DefinitionForm::toState($folder === 'vehicles' ? 'vehicle' : 'part', json_decode((string) repositoryContent()->raw($key), true));

    return [...$state, ...$changes];
}

function theDefinition(string $kind, string $slug): ContentDefinition
{
    return ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
}

// The panel speaks Polish to the people who run it (the middleware that sets it is not on the path of a component that is tested alone).
beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false);
    app()->setLocale('pl');
});

describe('the lists', function () {
    it('show the cars and the parts apart, with their status and whether a draft is waiting', function () {
        signedInAdmin();
        $withDraft = theDefinition('vehicle', 'rdzak-gt-4');
        $withDraft->forceFill(['draft' => (string) repositoryContent()->raw('vehicles/rdzak-gt-4'), 'draft_saved_at' => now()])->save();
        theDefinition('vehicle', 'orzel-v12')->forceFill(['status' => ContentDefinition::STATUS_WITHDRAWN])->save();

        Livewire::test(ListVehicles::class)
            ->set('tableRecordsPerPage', 100)
            ->assertCanSeeTableRecords(ContentDefinition::query()->where('kind', 'vehicle')->get())
            ->assertCanNotSeeTableRecords(ContentDefinition::query()->where('kind', 'part')->get())
            ->assertSee('Rdzak GT-4')
            ->assertSee('Wycofana')
            ->filterTable('draft', true)
            ->assertCanSeeTableRecords([$withDraft])
            ->assertCountTableRecords(1);

        Livewire::test(ListParts::class)
            ->set('tableRecordsPerPage', 100)
            ->assertCanSeeTableRecords(ContentDefinition::query()->where('kind', 'part')->get())
            ->assertCanNotSeeTableRecords(ContentDefinition::query()->where('kind', 'vehicle')->get())
            ->searchTable('street-tires-t2')
            ->assertSee('Opony uliczne');
    });

    it('are for any admin to look at, and the page of a new draft too', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/vehicles')->assertOk();
        $this->get('/admin/parts')->assertOk();
        $this->get('/admin/vehicles/create')->assertOk();
        $this->get('/admin/parts/create')->assertOk();
    });

    it('say how many drafts are waiting on the dashboard', function () {
        signedInAdmin();
        theDefinition('part', 'street-tires-t2')->forceFill(['draft' => (string) repositoryContent()->raw('parts/street-tires-t2')])->save();

        Livewire::test(LivePackWidget::class)->assertSee('1');
    });
});

describe('a new car', function () {
    it('is made through the form as a draft, and written to the trail', function () {
        $admin = signedInAdmin();

        Livewire::test(CreateVehicle::class)
            ->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'rdzak-gt-9', 'name' => ['en' => 'Rdzak GT-9', 'pl' => 'Rdzak GT-9', 'fr' => null, 'de' => null, 'pt-BR' => null], 'price' => 30000]))
            ->call('create')
            ->assertHasNoFormErrors();

        $definition = theDefinition('vehicle', 'rdzak-gt-9');

        expect($definition->status)->toBe(ContentDefinition::STATUS_DRAFT)
            ->and($definition->draftDocument()['price_cents'])->toBe(3_000_000)
            ->and($definition->draftDocument()['name'])->toBe(['en' => 'Rdzak GT-9', 'pl' => 'Rdzak GT-9'])
            ->and($definition->draft_admin_id)->toBe($admin->id)
            ->and(AdminAuditLog::query()->where('action', 'definition_created')->value('subject'))->toBe('vehicle:rdzak-gt-9');
    });

    it('is not made when the schema does not accept what the form holds, and says nothing is kept', function () {
        signedInAdmin();

        Livewire::test(CreateVehicle::class)
            ->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'rdzak-gt-9', 'power_kw' => 5]))
            ->call('create')
            ->assertNotified();

        expect(ContentDefinition::query()->where('slug', 'rdzak-gt-9')->exists())->toBeFalse();
    });

    it('is not made for a slug that is taken, and the form does not accept one that is not a slug', function () {
        signedInAdmin();

        Livewire::test(CreateVehicle::class)
            ->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'rdzak-gt-4']))
            ->call('create')
            ->assertNotified();

        Livewire::test(CreateVehicle::class)
            ->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'Not A Slug']))
            ->call('create')
            ->assertHasFormErrors(['slug']);

        expect(ContentDefinition::query()->where('kind', 'vehicle')->count())->toBe(23);
    });

    it('needs a name in English and in Polish and the fields that the car is made of', function () {
        signedInAdmin();

        Livewire::test(CreateVehicle::class)
            ->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'rdzak-gt-9', 'name' => ['en' => 'Rdzak', 'pl' => null], 'power_kw' => null]))
            ->call('create')
            ->assertHasFormErrors(['name.pl', 'power_kw']);
    });

    it('is an electric car with its own fields, and without the ones of a combustion car', function () {
        signedInAdmin();

        Livewire::test(CreateVehicle::class)
            ->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'volt-1', 'powertrain' => 'ev', 'engine_motors' => 2, 'battery_kwh' => 60, 'max_charge_kw' => 150]))
            ->call('create')
            ->assertHasNoFormErrors();

        $document = theDefinition('vehicle', 'volt-1')->draftDocument();

        expect($document['powertrain'])->toBe('ev')->and($document['engine'])->toBe(['motors' => 2])->and($document)->not->toHaveKey('tank_l')
            ->and($document['battery_kwh'])->toBe(60);
    });
});

describe('a car that was published', function () {
    it('is opened with what it is, and saving a change keeps a draft and leaves what is published as it is', function () {
        signedInAdmin();
        $definition = theDefinition('vehicle', 'rdzak-gt-4');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])
            ->assertFormSet(['slug' => 'rdzak-gt-4', 'power_kw' => 97, 'price' => 21500, 'name.pl' => 'Rdzak GT-4'])
            ->fillForm(['power_kw' => 105])
            ->call('save')
            ->assertHasNoFormErrors();

        $definition->refresh();

        expect($definition->draftDocument()['power_kw'])->toBe(105)
            ->and($definition->publishedDocument()['power_kw'])->toBe(97)
            ->and(AdminAuditLog::query()->where('action', 'definition_draft_saved')->count())->toBe(1);

        // Opened again, it starts from the draft.
        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])->assertFormSet(['power_kw' => 105]);
    });

    it('keeps no draft when what the form holds is what was published', function () {
        signedInAdmin();
        $definition = theDefinition('vehicle', 'rdzak-gt-4');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])->call('save')->assertHasNoFormErrors();

        expect($definition->refresh()->hasDraft())->toBeFalse();
    });

    it('does not keep a draft the schema does not accept, and the slug is not for changing', function () {
        signedInAdmin();
        $definition = theDefinition('vehicle', 'rdzak-gt-4');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])
            ->fillForm(['power_kw' => 99999])
            ->call('save')
            ->assertNotified();

        expect($definition->refresh()->hasDraft())->toBeFalse();

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])->assertFormFieldIsDisabled('slug');
    });

    it('can have its draft discarded', function () {
        signedInAdmin();
        $definition = theDefinition('vehicle', 'rdzak-gt-4');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])
            ->assertActionHidden('discard')
            ->fillForm(['power_kw' => 105])
            ->call('save')
            ->assertActionVisible('discard')
            ->callAction('discard')
            ->assertFormSet(['power_kw' => 97]);

        expect($definition->refresh()->hasDraft())->toBeFalse();
    });
});

describe('a part', function () {
    it('is made through the form, with its statistics as lines, and a tire has its kind', function () {
        signedInAdmin();

        Livewire::test(CreatePart::class)
            ->fillForm(formOf('parts/street-tires-t2', ['slug' => 'street-tires-t9', 'tier' => 3, 'stats' => [['key' => 'grip', 'value' => 105], ['key' => 'handling', 'value' => 99], ['key' => 'braking', 'value' => 101]]]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect(theDefinition('part', 'street-tires-t9')->draftDocument())->toBe([
            'slug' => 'street-tires-t9',
            'name' => ['en' => 'Street tires', 'pl' => 'Opony uliczne', 'fr' => 'Pneus routiers', 'de' => 'Straßenreifen', 'pt-BR' => 'Pneus de rua'],
            'slot' => 'tires',
            'tire_kind' => 'street',
            'tier' => 3,
            'price_cents' => 57000,
            'stats' => ['grip' => 105, 'handling' => 99, 'braking' => 101],
        ]);
    });

    it('is not made when its slot is tires and it has no kind of tires, which the form asks for', function () {
        signedInAdmin();

        Livewire::test(CreatePart::class)
            ->fillForm(formOf('parts/street-tires-t2', ['slug' => 'street-tires-t9', 'tire_kind' => null]))
            ->call('create')
            ->assertHasFormErrors(['tire_kind']);
    });

    it('needs its statistics to have names that are not repeated', function () {
        signedInAdmin();

        Livewire::test(CreatePart::class)
            ->fillForm(formOf('parts/street-tires-t2', ['slug' => 'street-tires-t9', 'stats' => [['key' => 'grip', 'value' => 100], ['key' => 'grip', 'value' => 90]]]))
            ->call('create')
            ->assertHasFormErrors();

        expect(ContentDefinition::query()->where('slug', 'street-tires-t9')->exists())->toBeFalse();
    });

    it('is changed as a draft, and an engine keeps its layout and displacement', function () {
        signedInAdmin();
        $definition = theDefinition('part', 'engine-r4-2-5-t5');

        Livewire::test(EditPart::class, ['record' => $definition->getRouteKey()])
            ->assertFormSet(['slot' => 'engine', 'engine_layout' => 'R4', 'engine_displacement_l' => 2.5])
            ->fillForm(['price' => 5605.50])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($definition->refresh()->draftDocument()['price_cents'])->toBe(560_550)
            ->and($definition->draftDocument()['engine'])->toBe(['layout' => 'R4', 'displacement_l' => 2.5])
            ->and($definition->draftDocument()['stats'])->toBe(['power_kw' => 162.5, 'engine_mass_kg' => 117.5]);
    });
});

describe('the life of a definition in the panel', function () {
    it('is moved by a publisher from the page of the definition, with a refusal said and nothing changed', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);
        $definition = theDefinition('vehicle', 'rdzak-gt-4');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])
            ->assertActionVisible('withdraw')
            ->assertActionHidden('restore')
            ->assertActionHidden('archive')
            ->callAction('withdraw')
            ->assertActionVisible('restore')
            ->assertActionVisible('archive')
            // It is named by the rules of the pack that is live, so it cannot be archived.
            ->callAction('archive')
            ->assertNotified();

        expect($definition->refresh()->status)->toBe(ContentDefinition::STATUS_WITHDRAWN);

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])->callAction('restore');

        expect($definition->refresh()->status)->toBe(ContentDefinition::STATUS_ACTIVE);
    });

    it('is not moved by an editor, who is not shown how', function () {
        signedInAdmin(Admin::ROLE_EDITOR);
        $definition = theDefinition('vehicle', 'rdzak-gt-4');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])
            ->assertActionHidden('withdraw')
            ->assertActionHidden('restore')
            ->assertActionHidden('archive')
            ->assertActionHidden('delete');
    });

    it('is deleted when it is a draft that was never published, from its own page', function () {
        signedInAdmin();

        Livewire::test(CreateVehicle::class)->fillForm(formOf('vehicles/rdzak-gt-4', ['slug' => 'rdzak-gt-9']))->call('create');
        $definition = theDefinition('vehicle', 'rdzak-gt-9');

        Livewire::test(EditVehicle::class, ['record' => $definition->getRouteKey()])
            ->assertActionVisible('delete')
            ->callAction('delete');

        expect(ContentDefinition::query()->where('slug', 'rdzak-gt-9')->exists())->toBeFalse();
    });

    it('is not edited when it is archived, and not shown the delete of a definition that was published', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);
        $definition = theDefinition('vehicle', 'furgon-z-5');
        $definition->forceFill(['status' => ContentDefinition::STATUS_ARCHIVED])->save();

        $this->get("/admin/vehicles/{$definition->getRouteKey()}/edit")->assertForbidden();

        $published = theDefinition('vehicle', 'furgon-z-3');

        Livewire::test(EditVehicle::class, ['record' => $published->getRouteKey()])->assertActionHidden('delete');
    });
});
