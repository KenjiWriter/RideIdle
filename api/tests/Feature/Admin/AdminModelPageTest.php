<?php

declare(strict_types=1);

use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\ModelForm;
use App\Content\Definitions\VocabularyForm;
use App\Content\GameModel;
use App\Content\Vocabulary\MessageCatalog;
use App\Filament\Pages\ModelPage;
use App\Filament\Pages\VocabularyPage;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use Livewire\Livewire;

// The model of the game in the panel: the slots with their roles and the statistics, kept as the draft of the document rules/model when the content accepts it. What
// a part has depends on the role of its slot, and the vocabulary can name the slots of the model that the app has no name for. The content of the repository is what
// is in the database.

function modelDefinition(): ?ContentDefinition
{
    return ContentDefinition::query()->where('kind', 'rules')->where('slug', 'model')->first();
}

/** @return array<string, mixed> the document of the model that the repository has */
function publishedModel(): array
{
    return JsonDocument::decode((string) repositoryContent()->raw('rules/model'));
}

/**
 * The model that the panel is working on: the document of the repository changed by a function, kept as a draft.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $change
 */
function workOnModel(Closure $change): void
{
    modelDefinition()->forceFill(['draft' => JsonDocument::encode($change(publishedModel()))])->save();
}

/**
 * The state of the form of the model, as the page shows it, changed by a function.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>|null  $change
 * @return array<string, mixed>
 */
function modelState(?Closure $change = null): array
{
    $state = ModelForm::toState(publishedModel());

    return $change === null ? $state : $change($state);
}

// The panel speaks Polish to the people who run it (the middleware that sets it is not on the path of a component that is tested alone).
beforeEach(fn () => app()->setLocale('pl'));

/** The content of the repository is what is in the database, and it is live. */
function theRepositoryIsLive(): void
{
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
}

describe('the page of the model', function () {
    beforeEach(fn () => theRepositoryIsLive());

    it('opens for any admin, with the model of the car game: its slots with their roles and its statistics', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/model')->assertOk()->assertSee('Model gry')->assertSee('Sloty');

        $state = Livewire::test(ModelPage::class)->assertHasNoFormErrors()->get('data');

        expect(array_column($state['slots'], 'key'))->toBe(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank', 'ecu'])
            ->and(array_values(array_column($state['slots'], 'role')))->toBe(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', null, 'cooling', 'tank', null])
            ->and(array_column($state['slots'], 'hidden'))->toBe([false, false, false, false, false, false, false, false, false, true])
            ->and(array_column($state['stats'], 'key'))->toBe(['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']);
    });

    it('keeps what is changed as the draft, with the order of the lists, a role only where there is one and hidden only where it is on', function () {
        signedInAdmin();

        Livewire::test(ModelPage::class)
            ->fillForm(modelState(function (array $state): array {
                // The exhaust is hidden, and the Off-road statistic and the order of the rest are changed.
                $state['slots'][6]['hidden'] = true;
                $state['stats'] = [['key' => 'grip'], ['key' => 'accel'], ['key' => 'top_speed']];

                return $state;
            }))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.definitions.saved'));

        $draft = modelDefinition()->draftDocument();

        expect($draft['stats'])->toBe(['grip', 'accel', 'top_speed'])
            ->and($draft['slots'][6])->toBe(['key' => 'exhaust', 'hidden' => true])
            ->and($draft['slots'][0])->toBe(['key' => 'tires', 'role' => 'tires'])
            ->and($draft['slots'][9])->toBe(['key' => 'ecu', 'hidden' => true])
            ->and($draft['version'])->toBe(publishedModel()['version']);
    });

    it('keeps no draft when what the form holds is what was published', function () {
        signedInAdmin();

        Livewire::test(ModelPage::class)->fillForm(modelState())->call('save')->assertHasNoFormErrors();

        expect(modelDefinition()->hasDraft())->toBeFalse();
    });

    it('refuses a role that two slots have, and says what is wrong', function () {
        signedInAdmin();

        Livewire::test(ModelPage::class)
            ->fillForm(modelState(function (array $state): array {
                $state['slots'][6]['role'] = 'tires';

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.invalid'));

        expect(modelDefinition()->hasDraft())->toBeFalse();
    });

    it('refuses a game without a slot that is the tires', function () {
        signedInAdmin();

        Livewire::test(ModelPage::class)
            ->fillForm(modelState(function (array $state): array {
                $state['slots'][0]['role'] = null;

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.invalid'));

        expect(modelDefinition()->hasDraft())->toBeFalse();
    });

    it('does not let a slot be listed twice', function () {
        signedInAdmin();

        Livewire::test(ModelPage::class)
            ->fillForm(modelState(function (array $state): array {
                $state['slots'][6]['key'] = 'tires';

                return $state;
            }))
            ->call('save')
            ->assertHasFormErrors();

        expect(modelDefinition()->hasDraft())->toBeFalse();
    });

    it('refuses to take out a slot that parts and rules still name', function () {
        signedInAdmin();

        Livewire::test(ModelPage::class)
            ->fillForm(modelState(function (array $state): array {
                // The gearbox has parts, and the rules give it a life and a time of a job.
                $state['slots'] = array_values(array_filter($state['slots'], fn (array $slot): bool => $slot['key'] !== 'gearbox'));

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.invalid'));

        expect(modelDefinition()->hasDraft())->toBeFalse();
    });
});

describe('a database that was made before the model', function () {
    // A pack that has no model: the database has no such definition.
    beforeEach(fn () => contentPacks()->import(contentWith([], removed: ['rules/model']), checkTables: false, activate: true));

    it('starts from the model of the car game in a database that was made before the model, and the first draft makes the document', function () {
        signedInAdmin();

        expect(modelDefinition())->toBeNull();

        $page = Livewire::test(ModelPage::class);

        expect(array_column($page->get('data.slots'), 'key'))->toHaveCount(10);

        $page->fillForm(modelState(function (array $state): array {
            $state['stats'] = [['key' => 'accel'], ['key' => 'grip']];

            return $state;
        }))->call('save')->assertHasNoFormErrors();

        expect(modelDefinition()->draftDocument())->toMatchArray(['schema' => 0, 'stats' => ['accel', 'grip']]);
    });
});

describe('a part of a game whose tires are another slot', function () {
    beforeEach(fn () => theRepositoryIsLive());

    /** The model that the panel works on: the slot hull is the tires, and the slot tires has no role (a game with a sail where the car game has tires, say). */
    function hullIsTheTires(): void
    {
        workOnModel(function (array $model): array {
            $model['slots'] = array_map(fn (array $slot): array => $slot['key'] === 'tires' ? ['key' => 'tires'] : $slot, $model['slots']);
            $model['slots'][] = ['key' => 'hull', 'role' => 'tires'];

            return $model;
        });
    }

    /** @return array<string, mixed> the state of the form of the street tires, for another slug and slot */
    function partState(string $slot, string $slug): array
    {
        $document = JsonDocument::decode((string) repositoryContent()->raw('parts/street-tires-t2'));

        return [...DefinitionForm::toState('part', $document), 'slug' => $slug, 'slot' => $slot];
    }

    it('has the kind of tires, which the form asks for, and the document has it', function () {
        signedInAdmin();
        hullIsTheTires();

        Livewire::test(CreatePart::class)
            ->fillForm([...partState('hull', 'hull-t9'), 'tire_kind' => null])
            ->call('create')
            ->assertHasFormErrors(['tire_kind']);

        Livewire::test(CreatePart::class)->fillForm([...partState('hull', 'hull-t9'), 'tire_kind' => 'street'])->call('create')->assertHasNoFormErrors();

        expect(ContentDefinition::query()->where('kind', 'part')->where('slug', 'hull-t9')->firstOrFail()->draftDocument())->toMatchArray(['slot' => 'hull', 'tire_kind' => 'street']);
    });

    it('has no kind of tires in the slot that is not the tires, though it was the tires in the car game', function () {
        signedInAdmin();
        hullIsTheTires();

        Livewire::test(CreatePart::class)->fillForm([...partState('tires', 'tires-t9'), 'tire_kind' => 'street'])->call('create')->assertHasNoFormErrors();

        expect(ContentDefinition::query()->where('kind', 'part')->where('slug', 'tires-t9')->firstOrFail()->draftDocument())->not->toHaveKey('tire_kind');
    });

    it('is made with the roles of the model it is made for, whichever slot is which', function () {
        $model = GameModel::fromArray(['slots' => [['key' => 'hull', 'role' => 'tires'], ['key' => 'reactor', 'role' => 'engine'], ['key' => 'tires']], 'stats' => ['accel']]);
        $state = partState('hull', 'x') + ['engine_layout' => 'V8', 'engine_displacement_l' => 4.0];

        expect(DefinitionForm::toDocument('part', $state, $model))->toHaveKey('tire_kind')->not->toHaveKey('engine')
            ->and(DefinitionForm::toDocument('part', [...$state, 'slot' => 'reactor'], $model))->toHaveKey('engine')->not->toHaveKey('tire_kind')
            ->and(DefinitionForm::toDocument('part', [...$state, 'slot' => 'tires'], $model))->not->toHaveKey('tire_kind')->not->toHaveKey('engine')
            // Without a model it is the car game: the slot tires has the kind, and the slot hull nothing.
            ->and(DefinitionForm::toDocument('part', [...$state, 'slot' => 'tires']))->toHaveKey('tire_kind')
            ->and(DefinitionForm::toDocument('part', $state))->not->toHaveKey('tire_kind');
    });

    it('is judged against the model that is being worked on', function () {
        hullIsTheTires();
        $document = DefinitionForm::toDocument('part', [...partState('hull', 'hull-t9'), 'tire_kind' => 'street'], app(ContentDrafts::class)->workingModel());

        expect(app(ContentDrafts::class)->validate('part', $document, 'hull-t9')->isValid())->toBeTrue()
            ->and(app(ContentDrafts::class)->validate('part', [...$document, 'slot' => 'wheel'], 'hull-t9')->isValid())->toBeFalse();
    });
});

describe('the vocabulary of a game with a slot that the app has no name for', function () {
    beforeEach(fn () => theRepositoryIsLive());

    /** The model of the working content with a slot that has no role, and the life and the time of a job that the rules give every slot. */
    function sailIsASlot(): void
    {
        workOnModel(function (array $model): array {
            $model['slots'][] = ['key' => 'sail'];

            return $model;
        });

        $starter = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail();
        $rules = $starter->workingDocument();
        $rules['wear']['life_km']['sail'] = 40_000;
        $rules['workshop']['base_time_ms']['sail'] = 60_000;
        $starter->forceFill(['draft' => JsonDocument::encode($rules)])->save();
    }

    it('lists the name of the slot as a message of the pack, and the names of the slots that the app has are the messages of the app', function () {
        signedInAdmin();
        sailIsASlot();

        Livewire::test(VocabularyPage::class)
            ->fillForm(['area' => 'slot'])
            ->assertSee('slot.sail')
            ->assertSee('slot.tires')
            ->assertSee(__('admin.vocabulary.slot_of_pack'));
    });

    it('keeps the name that was written for it as a word of the pack', function () {
        signedInAdmin();
        sailIsASlot();

        // The box of the message is the place of its key among the messages that the page shows for the area.
        $position = array_search('slot.sail', (new VocabularyForm(MessageCatalog::fromConfig(), app(ContentDrafts::class)->workingModel()))->visible([], 'slot', '', false)['keys'], true);

        Livewire::test(VocabularyPage::class)
            ->fillForm(['area' => 'slot', 'values' => ["m{$position}" => ['en' => 'Sail', 'pl' => 'Żagiel']]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(ContentDefinition::query()->where('kind', 'rules')->where('slug', 'vocabulary')->firstOrFail()->draftDocument()['messages'])->toBe(['slot.sail' => ['en' => 'Sail', 'pl' => 'Żagiel']]);
    });

    it('does not list a hidden slot, which is not shown and needs no name', function () {
        signedInAdmin();

        Livewire::test(VocabularyPage::class)->fillForm(['area' => 'slot'])->assertDontSee('slot.ecu');
    });
});
