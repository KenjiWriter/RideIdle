<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\VocabularyForm;
use App\Content\PackValidator;
use App\Content\Vocabulary\MessageCatalog;
use App\Content\Vocabulary\ServerMessages;
use App\Content\Vocabulary\Vocabulary;
use App\Content\Vocabulary\WebMessages;
use App\Filament\Pages\VocabularyPage;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Models\User;
use App\Services\ContentDrafts;
use App\Services\ContentPublisher;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

// The vocabulary of a pack: the messages of the web app that the pack says in its own words. It is checked against the app that speaks them, is said by an endpoint
// to the web app, and is edited on a page of the panel as a draft like the other documents are.

/** A pack of the repository whose vocabulary says what is given: key => texts. */
function packSaying(array $messages): Closure
{
    return fn (array $vocabulary): array => [...$vocabulary, 'messages' => $messages === [] ? new stdClass : $messages];
}

function vocabularyResult(array $messages): array
{
    $result = PackValidator::fromConfig()->validateSource(contentWith(['rules/vocabulary' => packSaying($messages)]));

    return ['errors' => $result->errors(), 'warnings' => $result->warnings(), 'valid' => $result->isValid()];
}

/** The first message of the app that fills in a name (and its text). */
function messageWithAName(): array
{
    foreach (WebMessages::fromConfig()->all() as $key => $text) {
        if (WebMessages::placeholders($text) !== []) {
            return [$key, $text];
        }
    }

    throw new RuntimeException('The app has no message that fills in a name.');
}

describe('the document', function () {
    it('is in the content of the game, empty, and the app says everything itself', function () {
        $document = repositoryContent()->document('rules/vocabulary');

        expect($document['messages'])->toBe([])
            ->and(vocabularyResult([])['valid'])->toBeTrue()
            ->and(Vocabulary::fromSource(repositoryContent())->isEmpty())->toBeTrue();
    });

    it('may be left out of a pack altogether', function () {
        $result = PackValidator::fromConfig()->validateSource(contentWith([], removed: ['rules/vocabulary']));

        expect($result->isValid())->toBeTrue()
            ->and($result->warnings())->toBe([])
            ->and(Vocabulary::fromSource(contentWith([], removed: ['rules/vocabulary']))->isEmpty())->toBeTrue();
    });

    it('takes a message of the app in its own words, and tells which languages have no words of their own', function () {
        $result = vocabularyResult(['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']]);

        expect($result['valid'])->toBeTrue()
            ->and(array_column($result['warnings'], 'pointer'))->toContain('/messages/slot.tires/fr', '/messages/slot.tires/de', '/messages/slot.tires/pt-BR');
    });

    it('needs English and Polish', function () {
        $result = vocabularyResult(['slot.tires' => ['en' => 'Hull']]);

        expect($result['valid'])->toBeFalse();
    });

    it('refuses a message that the app does not have', function () {
        $result = vocabularyResult(['slot.no_such_thing' => ['en' => 'Hull', 'pl' => 'Kadłub']]);

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'][0]['pointer'])->toBe('/messages/slot.no_such_thing');
    });

    it('refuses a text that leaves out a name the app fills in, or adds one', function () {
        [$key, $text] = messageWithAName();
        $name = WebMessages::placeholders($text)[0];

        $left = vocabularyResult([$key => ['en' => 'Nothing here', 'pl' => $text]]);
        $added = vocabularyResult([$key => ['en' => $text.' {surprise}', 'pl' => $text]]);

        expect($left['valid'])->toBeFalse()
            ->and($left['errors'][0]['pointer'])->toBe("/messages/{$key}/en")
            ->and($left['errors'][0]['message'])->toContain("{{$name}}")
            ->and($added['valid'])->toBeFalse();
    });

    it('refuses a brace that is not a name, a link to another message, and another number of plural forms', function () {
        $stray = vocabularyResult(['slot.tires' => ['en' => 'Hull {', 'pl' => 'Kadłub']]);
        $linked = vocabularyResult(['slot.tires' => ['en' => 'Hull', 'pl' => '@:slot.engine']]);
        $plural = vocabularyResult(['slot.tires' => ['en' => 'Hull | Hulls', 'pl' => 'Kadłub']]);

        expect($stray['valid'])->toBeFalse()
            ->and($linked['valid'])->toBeFalse()
            ->and($plural['valid'])->toBeFalse();
    });

    it('keeps the forms of a plural the message has', function () {
        foreach (WebMessages::fromConfig()->all() as $key => $text) {
            if (str_contains($text, '|')) {
                $names = WebMessages::placeholders($text);
                $texts = [];

                foreach (['en', 'pl'] as $locale) {
                    $forms = substr_count((string) WebMessages::fromConfig()->text($key, $locale), '|');
                    $texts[$locale] = implode(' | ', array_fill(0, $forms + 1, $names === [] ? 'x' : '{'.implode('} {', $names).'}'));
                }

                expect(vocabularyResult([$key => $texts])['valid'])->toBeTrue();

                return;
            }
        }

        $this->markTestSkipped('The app has no plural message.');
    });
});

describe('the words that a language says', function () {
    it('are the ones of the pack, and the English ones of the pack where a language has none', function () {
        $source = contentWith(['rules/vocabulary' => packSaying(['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub', 'fr' => 'Coque']])]);
        $words = Vocabulary::fromSource($source)->web();

        expect($words['pl']['slot.tires'])->toBe('Kadłub')
            ->and($words['fr']['slot.tires'])->toBe('Coque')
            ->and($words['de']['slot.tires'])->toBe('Hull')
            ->and($words['pt-BR']['slot.tires'])->toBe('Hull')
            ->and(array_keys($words))->toBe(['pl', 'en', 'fr', 'de', 'pt-BR']);
    });
});

describe('the endpoint', function () {
    it('says nothing when the pack changes no word, to anyone', function () {
        $this->getJson('/api/v1/meta/vocabulary')
            ->assertOk()
            ->assertExactJson(['number' => '2026.10.0', 'messages' => []]);
    });

    it('says the words of the pack that is live, in every language', function () {
        contentPacks()->import(contentWith(['rules/vocabulary' => packSaying(['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']])]), activate: true, checkTables: false);
        readFromDatabase();

        $response = $this->getJson('/api/v1/meta/vocabulary')->assertOk();

        expect($response->json('messages.pl'))->toBe(['slot.tires' => 'Kadłub'])
            ->and($response->json('messages.de'))->toBe(['slot.tires' => 'Hull'])
            ->and($response->json('number'))->toBeString()->not->toBe('')
            ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    });

    it('lets a copy be kept: the same words are not sent again', function () {
        $first = $this->getJson('/api/v1/meta/vocabulary')->assertOk();
        $etag = $first->headers->get('ETag');

        expect($etag)->not->toBeNull();

        $this->getJson('/api/v1/meta/vocabulary', ['If-None-Match' => $etag])->assertStatus(304);
    });
});

describe('what the form shows', function () {
    it('lists the messages of the app by area, and the ones of the pack that the app has not', function () {
        $form = new VocabularyForm(MessageCatalog::fromConfig());
        $document = ['messages' => ['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub'], 'gone.message' => ['en' => 'x', 'pl' => 'x']]];
        $areas = $form->areas($document);

        expect($areas['slot']['changed'])->toBe(1)
            ->and($areas['gone']['total'])->toBe(1)
            ->and(array_sum(array_column($areas, 'total')))->toBe(count(MessageCatalog::fromConfig()->all()) + 1);
    });

    it('shows an area, what a search finds, or only what is changed, and no more than a page', function () {
        $form = new VocabularyForm(MessageCatalog::fromConfig());
        $document = ['messages' => ['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']]];

        $slots = $form->visible($document, 'slot', '', false);
        $found = $form->visible($document, VocabularyForm::ALL, 'kadłub', false);
        $changed = $form->visible($document, VocabularyForm::ALL, '', true);
        $everything = $form->visible($document, VocabularyForm::ALL, '', false);

        expect($slots['keys'])->toContain('slot.tires', 'slot.engine')
            ->and($found['keys'])->toBe(['slot.tires'])
            ->and($changed['keys'])->toBe(['slot.tires'])
            ->and(count($everything['keys']))->toBe(VocabularyForm::SHOWN)
            ->and($everything['matching'])->toBeGreaterThan(VocabularyForm::SHOWN);
    });

    it('puts the words of the pack in the boxes of the messages it shows, and leaves the rest empty', function () {
        $form = new VocabularyForm(MessageCatalog::fromConfig());
        $state = $form->toState(['messages' => ['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']]], ['slot.tires', 'slot.engine']);

        expect($state['values']['m0'])->toBe(['en' => 'Hull', 'pl' => 'Kadłub', 'fr' => null, 'de' => null, 'pt-BR' => null])
            ->and($state['values']['m1'])->toBe(['en' => null, 'pl' => null, 'fr' => null, 'de' => null, 'pt-BR' => null]);
    });

    it('keeps what was written, fills English and Polish from the app, and drops a message with nothing in it', function () {
        $form = new VocabularyForm(MessageCatalog::fromConfig());
        $document = ['schema' => 0, 'version' => '2026.10.3', 'messages' => ['slot.engine' => ['en' => 'Reactor', 'pl' => 'Reaktor']]];

        $applied = $form->apply($document, ['slot.tires', 'slot.engine'], ['values' => [
            'm0' => ['en' => null, 'pl' => '  Kadłub ', 'fr' => '', 'de' => null, 'pt-BR' => null],
            'm1' => ['en' => '', 'pl' => '', 'fr' => '', 'de' => '', 'pt-BR' => ''],
        ]], '2026.10.3');

        expect($applied['messages'])->toBe(['slot.tires' => ['en' => WebMessages::fromConfig()->text('slot.tires', 'en'), 'pl' => 'Kadłub']])
            ->and($form->apply($applied, ['slot.tires'], ['values' => ['m0' => []]], '2026.10.3')['messages'])->toBeInstanceOf(stdClass::class);
    });
});

describe('the page', function () {
    beforeEach(function () {
        contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
        app()->setLocale('pl');
    });

    function vocabularyDraft(): ?array
    {
        return ContentDefinition::query()->where('kind', 'rules')->where('slug', 'vocabulary')->first()?->draftDocument();
    }

    it('opens for any admin, with the words of the app beside the boxes', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/vocabulary')->assertOk()->assertSee('Słownik gry')->assertSee('app.name');
    });

    it('keeps the words that were written as the draft of the vocabulary', function () {
        signedInAdmin();

        Livewire::test(VocabularyPage::class)
            ->fillForm(['area' => 'slot', 'values' => ['m0' => ['pl' => 'Kadłub', 'en' => 'Hull']]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(vocabularyDraft()['messages'])->toBe(['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']]);
    });

    it('keeps no draft of a text that the validator refuses', function () {
        signedInAdmin();
        [$key] = messageWithAName();

        Livewire::test(VocabularyPage::class)
            ->fillForm(['area' => VocabularyForm::ALL, 'search' => $key, 'values' => ['m0' => ['pl' => 'Bez zmiennych', 'en' => 'No names']]])
            ->call('save');

        expect(vocabularyDraft())->toBeNull();
    });
});

describe('a database that was made before the vocabulary existed', function () {
    beforeEach(function () {
        contentPacks()->import(contentWith([], removed: ['rules/vocabulary']), checkTables: false, activate: true);
        app()->setLocale('pl');
    });

    it('starts the document on the page when no pack has brought it, and only when something is written', function () {
        signedInAdmin();

        $page = Livewire::test(VocabularyPage::class)->assertOk();

        expect(ContentDefinition::query()->where('slug', 'vocabulary')->exists())->toBeFalse();

        $page->fillForm(['area' => 'slot', 'values' => ['m0' => ['pl' => 'Kadłub']]])->call('save')->assertHasNoFormErrors();

        $definition = ContentDefinition::query()->where('slug', 'vocabulary')->firstOrFail();

        expect($definition->status)->toBe(ContentDefinition::STATUS_DRAFT)
            ->and(JsonDocument::decode((string) $definition->draft)['messages'])->toHaveCount(1)
            ->and(JsonDocument::decode((string) $definition->draft)['version'])->toBe(repositoryContent()->document('rules/starter')['version']);
    });
});

describe('the way to the game', function () {
    beforeEach(function () {
        contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    });

    it('is a draft, a publication that passes the gates, and a pack that is made live: then the endpoint says the words', function () {
        $admin = anAdmin(['email' => 'publisher@example.com', 'role' => Admin::ROLE_PUBLISHER]);
        $definition = ContentDefinition::query()->where('kind', 'rules')->where('slug', 'vocabulary')->firstOrFail();

        app(ContentDrafts::class)->save($definition, ['schema' => 0, 'version' => '2026.10.3', 'messages' => ['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']]], $admin);

        $publication = app(ContentPublisher::class)->queue($admin, 'ships')->refresh();

        expect($publication->status)->toBe('ready');

        app(ContentPublisher::class)->activate($publication->pack, $admin);
        readFromDatabase();

        $this->getJson('/api/v1/meta/vocabulary')
            ->assertOk()
            ->assertJsonPath('messages.pl', ['slot.tires' => 'Kadłub'])
            ->assertJsonPath('messages.en', ['slot.tires' => 'Hull'])
            ->assertJsonPath('number', $publication->number);
    });
});

describe('the texts of the server', function () {
    it('are known by the key they have in the language files, in every language', function () {
        $server = ServerMessages::fromConfig();

        expect($server->all())->toHaveKey('server.game.errors.no_run')->toHaveKey('server.game.rarity.common')
            ->and($server->text('server.game.rarity.common', 'pl'))->toBe(trans('game.rarity.common', [], 'pl'))
            ->and($server->text('server.game.errors.no_run', 'pt-BR'))->toBe(trans('game.errors.no_run', [], 'pt_BR'))
            ->and(ServerMessages::placeholders('Requires level :required (you have :Current)'))->toBe(['current', 'required'])
            ->and(MessageCatalog::area('server.game.errors.no_run'))->toBe('server.game')
            ->and(MessageCatalog::area('slot.tires'))->toBe('slot');
    });

    it('may be said in the words of a pack, with the same names filled in', function () {
        $ok = vocabularyResult(['server.game.errors.player_level_required' => ['en' => 'Captains of rank :required only (you are :current).', 'pl' => 'Tylko kapitanowie rangi :required (jesteś :current).']]);
        $unknown = vocabularyResult(['server.game.errors.nothing_like_it' => ['en' => 'x', 'pl' => 'x']]);
        $left = vocabularyResult(['server.game.errors.player_level_required' => ['en' => 'Captains of rank :required only.', 'pl' => 'Tylko kapitanowie rangi :required.']]);

        expect($ok['valid'])->toBeTrue()
            ->and($unknown['valid'])->toBeFalse()
            ->and($unknown['errors'][0]['message'])->toContain('server.game.errors.nothing_like_it')
            ->and($left['valid'])->toBeFalse()
            ->and($left['errors'][0]['message'])->toContain(':current, :required');
    });

    it('are lines for the translator, apart from the words of the web app', function () {
        $source = contentWith(['rules/vocabulary' => packSaying([
            'server.game.errors.no_run' => ['en' => 'You have no ship at sea yet.', 'pl' => 'Nie masz jeszcze statku na morzu.'],
            'slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub'],
        ])]);
        $vocabulary = Vocabulary::fromSource($source);

        expect($vocabulary->serverLines('pl'))->toBe(['game.errors.no_run' => 'Nie masz jeszcze statku na morzu.'])
            ->and($vocabulary->serverLines('de'))->toBe(['game.errors.no_run' => 'You have no ship at sea yet.'])
            ->and($vocabulary->web()['pl'])->toBe(['slot.tires' => 'Kadłub']);
    });

    it('are not sent to the web app', function () {
        contentPacks()->import(contentWith(['rules/vocabulary' => packSaying(['server.game.errors.no_run' => ['en' => 'x', 'pl' => 'y']])]), activate: true, checkTables: false);
        readFromDatabase();

        $this->getJson('/api/v1/meta/vocabulary')->assertOk()->assertExactJson(['number' => '2026.10.0', 'messages' => []]);
    });

    it('are said to a driver in the words of the pack, in the language of the driver, and the rest of the texts stay as they were', function () {
        $driver = User::factory()->guest()->create();
        $default = trans('game.errors.no_run', [], 'pl');

        $this->getJson('/api/v1/runs/current', [...bearerFor($driver), 'X-Locale' => 'pl'])->assertNotFound()->assertJsonPath('message', $default);

        contentPacks()->import(contentWith(['rules/vocabulary' => packSaying(['server.game.errors.no_run' => ['en' => 'You have no ship at sea yet.', 'pl' => 'Nie masz jeszcze statku na morzu.']])]), activate: true, checkTables: false);
        readFromDatabase();

        $this->getJson('/api/v1/runs/current', [...bearerFor($driver), 'X-Locale' => 'pl'])->assertNotFound()->assertJsonPath('message', 'Nie masz jeszcze statku na morzu.');

        // The group was loaded before the lines were added to it, so the texts that the pack leaves alone are the ones of the language file.
        expect(trans('game.errors.run_exists', [], 'pl'))->toBe((include lang_path('pl/game.php'))['errors']['run_exists']);

        $this->getJson('/api/v1/runs/current', [...bearerFor($driver), 'X-Locale' => 'de'])->assertNotFound()->assertJsonPath('message', 'You have no ship at sea yet.');
    });
});

/** The theme about ships that the repository keeps, as the vocabulary document it is. */
function shipsTheme(): array
{
    return contentArray(json_decode((string) file_get_contents(dirname((string) config('ride.content_path').'/x').'/themes/ships.json')));
}

describe('a plural', function () {
    it('has the forms of its language: three in Polish where English has two', function () {
        $three = '{n} rejs | {n} rejsy | {n} rejsów';
        $two = '{n} rejs | {n} rejsów';

        $ok = vocabularyResult(['run.laps' => ['en' => '{n} voyage | {n} voyages', 'pl' => $three]]);
        $wrong = vocabularyResult(['run.laps' => ['en' => '{n} voyage | {n} voyages', 'pl' => $two]]);

        expect($ok['valid'])->toBeTrue()
            ->and($wrong['valid'])->toBeFalse()
            ->and($wrong['errors'][0]['pointer'])->toBe('/messages/run.laps/pl')
            ->and($wrong['errors'][0]['message'])->toContain('3 form');
    });
});

describe('the theme of ships', function () {
    it('is a vocabulary that the app and the server accept in every word, and says so in the languages it has', function () {
        $result = PackValidator::fromConfig()->validateSource(contentWith(['rules/vocabulary' => fn (): array => shipsTheme()]));

        expect($result->errors())->toBe([])
            ->and($result->isValid())->toBeTrue()
            ->and(count(shipsTheme()['messages']))->toBeGreaterThan(80);
    });

    it('turns the slots, the stats, the menu and the voyages of the game into those of a ship, and what the server says', function () {
        contentPacks()->import(contentWith(['rules/vocabulary' => fn (): array => shipsTheme()]), activate: true, checkTables: false);
        readFromDatabase();

        $web = $this->getJson('/api/v1/meta/vocabulary')->assertOk()->json('messages');

        expect($web['pl']['slot.tires'])->toBe('Kadłub')
            ->and($web['fr']['slot.tires'])->toBe('Coque')
            ->and($web['en']['nav.garage'])->toBe('Dock')
            ->and($web['de']['nav.lap'])->toBe('Leg {lap} · {time}')
            ->and($web['pl']['run.laps'])->toBe('{n} rejs | {n} rejsy | {n} rejsów')
            ->and(array_filter(array_keys($web['en']), fn (string $key): bool => str_starts_with($key, 'server.')))->toBe([])
            ->and(Vocabulary::fromConfig()->serverLines('pl')['game.errors.no_run'])->toBe('Nie masz jeszcze statku na morzu.');
    });
});

describe('the page and a theme', function () {
    beforeEach(function () {
        contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
        app()->setLocale('pl');
    });

    it('takes a theme from a file as the draft, in place of the words of the pack', function () {
        signedInAdmin();
        $json = (string) file_get_contents(dirname((string) config('ride.content_path').'/x').'/themes/ships.json');

        Livewire::test(VocabularyPage::class)
            ->callAction('import', ['theme' => UploadedFile::fake()->createWithContent('ships.json', $json)])
            ->assertHasNoActionErrors();

        expect(vocabularyDraft()['messages'])->toHaveCount(count(shipsTheme()['messages']))
            ->and(vocabularyDraft()['messages']['slot.tires']['pl'])->toBe('Kadłub');
    });

    it('keeps no draft of a file that is not a vocabulary, or that has a word the app does not have', function () {
        signedInAdmin();

        $page = Livewire::test(VocabularyPage::class);
        $page->callAction('import', ['theme' => UploadedFile::fake()->createWithContent('a.json', 'this is not json')]);
        $page->callAction('import', ['theme' => UploadedFile::fake()->createWithContent('b.json', '{"messages": {"no.such.message": {"en": "x", "pl": "y"}}}')]);

        expect(vocabularyDraft())->toBeNull();
    });

    it('gives the draft back as a file', function () {
        signedInAdmin();

        Livewire::test(VocabularyPage::class)
            ->fillForm(['area' => 'slot', 'values' => ['m0' => ['pl' => 'Kadłub', 'en' => 'Hull']]])
            ->call('save')
            ->callAction('download')
            ->assertFileDownloaded('vocabulary.json');
    });
});
