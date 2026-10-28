<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\VocabularyForm;
use App\Content\PackValidator;
use App\Content\Vocabulary\Vocabulary;
use App\Content\Vocabulary\WebMessages;
use App\Filament\Pages\VocabularyPage;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use App\Services\ContentPublisher;
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
                $forms = substr_count($text, '|');
                $names = WebMessages::placeholders($text);
                $same = implode(' | ', array_fill(0, $forms + 1, $names === [] ? 'x' : '{'.implode('} {', $names).'}'));

                expect(vocabularyResult([$key => ['en' => $same, 'pl' => $same]])['valid'])->toBeTrue();

                return;
            }
        }

        $this->markTestSkipped('The app has no plural message.');
    });
});

describe('the words that a language says', function () {
    it('are the ones of the pack, and the English ones of the pack where a language has none', function () {
        $source = contentWith(['rules/vocabulary' => packSaying(['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub', 'fr' => 'Coque']])]);
        $words = Vocabulary::fromSource($source)->byLocale();

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
        $form = new VocabularyForm(WebMessages::fromConfig());
        $document = ['messages' => ['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub'], 'gone.message' => ['en' => 'x', 'pl' => 'x']]];
        $areas = $form->areas($document);

        expect($areas['slot']['changed'])->toBe(1)
            ->and($areas['gone']['total'])->toBe(1)
            ->and(array_sum(array_column($areas, 'total')))->toBe(count(WebMessages::fromConfig()->all()) + 1);
    });

    it('shows an area, what a search finds, or only what is changed, and no more than a page', function () {
        $form = new VocabularyForm(WebMessages::fromConfig());
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
        $form = new VocabularyForm(WebMessages::fromConfig());
        $state = $form->toState(['messages' => ['slot.tires' => ['en' => 'Hull', 'pl' => 'Kadłub']]], ['slot.tires', 'slot.engine']);

        expect($state['values']['m0'])->toBe(['en' => 'Hull', 'pl' => 'Kadłub', 'fr' => null, 'de' => null, 'pt-BR' => null])
            ->and($state['values']['m1'])->toBe(['en' => null, 'pl' => null, 'fr' => null, 'de' => null, 'pt-BR' => null]);
    });

    it('keeps what was written, fills English and Polish from the app, and drops a message with nothing in it', function () {
        $form = new VocabularyForm(WebMessages::fromConfig());
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
