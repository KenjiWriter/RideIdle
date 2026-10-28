<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\TutorialForm;
use App\Content\Definitions\WorldForm;
use App\Filament\Pages\RulesJsonPage;
use App\Filament\Pages\TutorialPage;
use App\Filament\Pages\WorldPage;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentDefinition;
use App\Models\TutorialProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// The world, the tutorial and the rules as text in the panel: each starts from the draft or from what was published, keeps a draft only when the schema and the
// rules of the world accept the document it makes, and says what is wrong when they do not. The content of the repository is what is in the database.

function publishedRules(string $slug = 'starter'): array
{
    return JsonDocument::decode((string) repositoryContent()->raw("rules/{$slug}"));
}

function theRulesDefinition(string $slug = 'starter'): ContentDefinition
{
    return ContentDefinition::query()->where('kind', 'rules')->where('slug', $slug)->firstOrFail();
}

/** The state of the form of the world, as the page shows it, changed by a function. @return array<string, mixed> */
function worldState(?Closure $change = null): array
{
    $state = WorldForm::toState(publishedRules());

    if ($change !== null) {
        $state = $change($state) ?? $state;
    }

    return $state;
}

/** @return array<string, mixed> */
function tutorialState(?Closure $change = null): array
{
    $state = TutorialForm::toState(publishedRules('tutorial'));

    if ($change !== null) {
        $state = $change($state) ?? $state;
    }

    return $state;
}

// The panel speaks Polish to the people who run it (the middleware that sets it is not on the path of a component that is tested alone).
beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false);
    app()->setLocale('pl');
});

describe('the pages', function () {
    it('are for any admin to open, and the world shows its cities', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/world')->assertOk()->assertSee('zlomowo');
        $this->get('/admin/tutorial')->assertOk()->assertSee('wreck');
        $this->get('/admin/rules')->assertOk();
    });

    it('say that the rules are not in the database when they are not, instead of an empty form', function () {
        signedInAdmin();
        // The content was never imported under this name: the page looks for the rules by their slug.
        ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->update(['slug' => 'elsewhere']);

        Livewire::test(WorldPage::class)->assertSee(__('admin.rules.missing'))->assertDontSee(__('admin.rules.save'));
    });
});

describe('the world', function () {
    it('starts from what is published, and saving it as it is keeps no draft', function () {
        signedInAdmin();
        $rules = publishedRules();

        $page = Livewire::test(WorldPage::class);

        expect(array_column(array_values($page->get('data.regions')), 'slug'))->toBe(array_keys($rules['regions']))
            ->and(array_column(array_values($page->get('data.tracks')), 'slug'))->toBe(array_keys($rules['tracks']));

        $page->call('save')->assertHasNoFormErrors();

        expect(theRulesDefinition()->hasDraft())->toBeFalse();
    });

    it('keeps a draft with a changed name, and leaves the rest of the rules as they were', function () {
        $admin = signedInAdmin();
        $rules = publishedRules();

        Livewire::test(WorldPage::class)
            ->fillForm(worldState(function (array $state): array {
                $state['regions'][0]['name']['pl'] = 'Złomowo Stare';

                return $state;
            }))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.definitions.saved'));

        $draft = theRulesDefinition()->draftDocument();
        $slug = array_key_first($rules['regions']);
        $changed = $rules;
        $changed['regions'][$slug]['name']['pl'] = 'Złomowo Stare';

        expect($draft['regions'][$slug]['name']['pl'])->toBe('Złomowo Stare')
            ->and($draft)->toEqual($changed)
            ->and(theRulesDefinition()->publishedDocument())->toEqual($rules)
            ->and(theRulesDefinition()->draft_admin_id)->toBe($admin->id)
            ->and(AdminAuditLog::query()->where('action', 'definition_draft_saved')->value('subject'))->toBe('rules:starter');
    });

    it('moves a city on the map, which is its two numbers', function () {
        signedInAdmin();

        Livewire::test(WorldPage::class)
            ->fillForm(worldState(function (array $state): array {
                $state['regions'][1]['map'] = ['x' => 200, 'y' => 150];

                return $state;
            }))
            ->call('save')
            ->assertHasNoFormErrors();

        $slug = array_keys(publishedRules()['regions'])[1];

        expect(theRulesDefinition()->draftDocument()['regions'][$slug]['map'])->toBe(['x' => 200, 'y' => 150]);
    });

    it('draws the board of the map with the other cities and the roads between them', function () {
        signedInAdmin();

        Livewire::test(WorldPage::class)->assertSeeHtml('stroke-dasharray="6 4"')->assertSeeHtml('x-ref="board"');
    });

    it('starts again from the draft that was kept, and the draft can be discarded', function () {
        signedInAdmin();

        $page = Livewire::test(WorldPage::class)->assertActionHidden('discard');

        $page->fillForm(worldState(function (array $state): array {
            $state['roads'][0]['km'] = 99;

            return $state;
        }))->call('save');

        expect(Livewire::test(WorldPage::class)->get('data.roads'))->toContain(['from' => publishedRules()['roads'][0]['from'], 'to' => publishedRules()['roads'][0]['to'], 'km' => 99]);

        Livewire::test(WorldPage::class)->callAction('discard');

        expect(theRulesDefinition()->hasDraft())->toBeFalse();
    });

    it('does not keep a world that cannot be played, and says so', function () {
        signedInAdmin();

        Livewire::test(WorldPage::class)
            ->fillForm(worldState(function (array $state): array {
                $state['tracks'][0]['demand'][0]['value'] = 99;

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.invalid'));

        expect(theRulesDefinition()->hasDraft())->toBeFalse();
    });

    it('does not take out a city or a track that the players use', function () {
        signedInAdmin();
        $driver = User::factory()->guest()->create();
        DB::table('city_garages')->insert(['user_id' => $driver->id, 'region' => 'autodrom', 'capacity' => 3, 'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(WorldPage::class)
            ->fillForm(worldState(function (array $state): array {
                $state['regions'] = array_values(array_filter($state['regions'], fn (array $city): bool => $city['slug'] !== 'autodrom'));
                $state['tracks'] = array_values(array_filter($state['tracks'], fn (array $track): bool => $track['region'] !== 'autodrom'));
                $state['roads'] = array_values(array_filter($state['roads'], fn (array $road): bool => ! in_array('autodrom', [$road['from'], $road['to']], true)));

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.refused.removed_in_use', ['slug' => 'starter', 'what' => 'city autodrom (1)']));

        expect(theRulesDefinition()->hasDraft())->toBeFalse();
    });

    it('keeps a draft with no text in a language, and says which', function () {
        signedInAdmin();

        Livewire::test(WorldPage::class)
            ->fillForm(worldState(function (array $state): array {
                $state['regions'][0]['name']['pt-BR'] = null;

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.missing_languages', ['languages' => 'pt-BR']));

        expect(theRulesDefinition()->hasDraft())->toBeTrue();
    });

    it('needs a name in English and in Polish, and the numbers of a track', function () {
        signedInAdmin();

        Livewire::test(WorldPage::class)
            ->fillForm(worldState(function (array $state): array {
                $state['regions'][0]['name']['en'] = null;
                $state['tracks'][0]['length_m'] = null;

                return $state;
            }))
            ->call('save')
            ->assertHasFormErrors();

        expect(theRulesDefinition()->hasDraft())->toBeFalse();
    });
});

describe('the tutorial', function () {
    it('starts from the steps, and keeps a draft of a message that was changed', function () {
        signedInAdmin();
        $tutorial = publishedRules('tutorial');
        $index = array_key_first(array_filter($tutorial['steps'], fn (array $step): bool => $step['sms'] !== []));

        $page = Livewire::test(TutorialPage::class);

        expect(array_column(array_values($page->get('data.steps')), 'key'))->toBe(array_column($tutorial['steps'], 'key'));

        $page
            ->fillForm(tutorialState(function (array $state) use ($index): array {
                $state['steps'][$index]['sms'][0]['pl'] = 'Nowa wiadomość mentora.';

                return $state;
            }))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.definitions.saved'));

        $changed = $tutorial;
        $changed['steps'][$index]['sms'][0]['pl'] = 'Nowa wiadomość mentora.';

        expect(theRulesDefinition('tutorial')->draftDocument())->toEqual($changed)
            ->and(theRulesDefinition('tutorial')->publishedDocument())->toEqual($tutorial);
    });

    it('keeps no draft when it is saved as it is, and a step that gives nothing keeps its empty object', function () {
        signedInAdmin();

        Livewire::test(TutorialPage::class)->call('save')->assertHasNoFormErrors();

        expect(theRulesDefinition('tutorial')->hasDraft())->toBeFalse();

        Livewire::test(TutorialPage::class)
            ->fillForm(tutorialState(function (array $state): array {
                $state['first_run_fuel_ml'] = 9000;

                return $state;
            }))
            ->call('save');

        expect(JsonDocument::encode(theRulesDefinition('tutorial')->draftDocument()))->toContain('"reward": {}');
    });

    it('does not take out a step that a driver has met', function () {
        signedInAdmin();
        $driver = User::factory()->guest()->create();
        TutorialProgress::query()->create(['user_id' => $driver->id, 'step_key' => 'first-lap', 'state' => TutorialProgress::STATE_DONE]);

        Livewire::test(TutorialPage::class)
            ->fillForm(tutorialState(function (array $state): array {
                $state['steps'] = array_values(array_filter($state['steps'], fn (array $step): bool => $step['key'] !== 'first-lap'));

                return $state;
            }))
            ->call('save')
            ->assertNotified(__('admin.definitions.refused.removed_in_use', ['slug' => 'tutorial', 'what' => 'step first-lap (1)']));

        expect(theRulesDefinition('tutorial')->hasDraft())->toBeFalse();
    });
});

describe('the rules as text', function () {
    it('start from the rules of the game, and show the tutorial when it is chosen', function () {
        signedInAdmin();

        $page = Livewire::test(RulesJsonPage::class)->assertFormSet(['document' => 'starter']);

        expect($page->get('data.json'))->toBe(JsonDocument::encode(publishedRules()));

        $page->set('data.document', 'tutorial');

        expect($page->get('data.json'))->toBe(JsonDocument::encode(publishedRules('tutorial')));
    });

    it('keep a draft of a document that is changed in the text, which the world page then shows', function () {
        signedInAdmin();
        $rules = publishedRules();
        $slug = array_key_first($rules['tracks']);
        $rules['tracks'][$slug]['xp_per_lap'] = 25;

        Livewire::test(RulesJsonPage::class)
            ->set('data.json', JsonDocument::encode($rules))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.definitions.saved'));

        expect(theRulesDefinition()->draftDocument()['tracks'][$slug]['xp_per_lap'])->toBe(25);

        $tracks = array_values(Livewire::test(WorldPage::class)->get('data.tracks'));

        expect($tracks[0]['xp_per_lap'])->toBe(25);
    });

    it('do not take text that is not JSON, or a document that the schema does not accept', function () {
        signedInAdmin();
        $rules = publishedRules();
        unset($rules['fuel']);

        Livewire::test(RulesJsonPage::class)->set('data.json', '{ nope')->call('save')->assertHasFormErrors(['json']);

        Livewire::test(RulesJsonPage::class)->set('data.json', JsonDocument::encode($rules))->call('save')->assertNotified(__('admin.definitions.invalid'));

        expect(theRulesDefinition()->hasDraft())->toBeFalse();
    });
});
