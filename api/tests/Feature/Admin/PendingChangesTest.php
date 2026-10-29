<?php

declare(strict_types=1);

use App\Content\Definitions\DocumentDiff;
use App\Content\Definitions\JsonDocument;
use App\Filament\Pages\GameOverview;
use App\Filament\Pages\PendingChangesPage;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use App\Services\PendingChanges;

// The screen that says what the drafts would change in the game against the live pack: the difference of two documents, how a number of the rules is said, and the page.

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
});

function pendingDefinition(string $kind, string $slug): ContentDefinition
{
    return ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
}

/** A draft of a definition: its published document with what is given in it changed. */
function pendingDraft(ContentDefinition $definition, callable $change): void
{
    $document = $definition->publishedDocument();
    $change($document);

    app(ContentDrafts::class)->save($definition, $document, Admin::query()->firstOrFail());
}

describe('the difference of two documents', function () {
    it('lists what was added, taken away and changed, at its pointer', function () {
        $old = ['name' => ['en' => 'Old', 'pl' => 'Stary'], 'stats' => ['grip' => 10, 'mass' => 900], 'tier' => 1];
        $new = ['name' => ['en' => 'New', 'pl' => 'Stary'], 'stats' => ['grip' => 10, 'power' => 5], 'tier' => 1];

        expect(DocumentDiff::between($old, $new))->toBe([
            ['pointer' => '/name/en', 'type' => 'changed', 'from' => 'Old', 'to' => 'New'],
            ['pointer' => '/stats/power', 'type' => 'added', 'from' => null, 'to' => 5],
            ['pointer' => '/stats/mass', 'type' => 'removed', 'from' => 900, 'to' => null],
        ]);
    });

    it('takes 10 and 10.0 for the same number, and a string that looks like a number for a string', function () {
        expect(DocumentDiff::between(['a' => 10, 'b' => '10'], ['a' => 10.0, 'b' => 10]))->toBe([
            ['pointer' => '/b', 'type' => 'changed', 'from' => '10', 'to' => 10],
        ]);
    });

    it('compares a list place by place and keeps an empty list as a value', function () {
        expect(DocumentDiff::between(['steps' => ['a', 'b'], 'none' => []], ['steps' => ['a', 'c'], 'none' => ['x']]))->toBe([
            ['pointer' => '/steps/1', 'type' => 'changed', 'from' => 'b', 'to' => 'c'],
            ['pointer' => '/none/0', 'type' => 'added', 'from' => null, 'to' => 'x'],
            ['pointer' => '/none', 'type' => 'removed', 'from' => [], 'to' => null],
        ]);
    });

    it('writes a slash and a tilde of a key the way a pointer does', function () {
        expect(array_keys(DocumentDiff::flatten(['a/b' => ['c~d' => 1]])))->toBe(['/a~1b/c~0d']);
    });

    it('has everything added when there is nothing to compare with', function () {
        expect(array_column(DocumentDiff::between(null, ['a' => 1, 'b' => ['c' => 2]]), 'type'))->toBe(['added', 'added']);
    });
});

describe('what the drafts would change', function () {
    it('has nothing when there is no draft', function () {
        signedInAdmin();

        expect(app(PendingChanges::class)->all())->toBe([])
            ->and(app(PendingChanges::class)->waiting())->toBe(0);
    });

    it('says a car with a draft is changed, and where', function () {
        signedInAdmin();
        $car = pendingDefinition(ContentDefinition::KIND_VEHICLE, 'rdzak-gt-4');
        pendingDraft($car, function (array &$document): void {
            $document['name']['en'] = 'Rdzak GT-4 Mk II';
        });

        $entries = app(PendingChanges::class)->all();

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['definition']->slug)->toBe('rdzak-gt-4')
            ->and($entries[0]['state'])->toBe('changed')
            ->and($entries[0]['count'])->toBe(1)
            ->and($entries[0]['changes'][0])->toMatchArray(['type' => 'changed', 'label' => '/name/en', 'from' => 'Rdzak GT-4', 'to' => 'Rdzak GT-4 Mk II', 'group' => null]);
    });

    it('says a number of the rules the way the studio does: where it is, and in the unit a person thinks in', function () {
        signedInAdmin();
        pendingDraft(pendingDefinition(ContentDefinition::KIND_RULES, 'starter'), function (array &$document): void {
            $document['player']['other_cars_xp_ppm'] = 250_000;
        });

        $change = app(PendingChanges::class)->all()[0]['changes'][0];

        expect($change['to'])->toBe('25%')
            ->and($change['from'])->toBe('0%')
            ->and($change['group'])->not->toBeNull()
            ->and($change['label'])->not->toStartWith('/');
    });

    it('says a draft that says what was published has no difference', function () {
        signedInAdmin();
        // The panel keeps no draft that says what was published; one can still be left when the pack that is live was made from the same document.
        $car = pendingDefinition(ContentDefinition::KIND_VEHICLE, 'rdzak-gt-4');
        $car->forceFill(['draft' => $car->latestVersion->document, 'draft_saved_at' => now()])->save();

        $entries = app(PendingChanges::class)->all();

        expect($entries[0]['state'])->toBe('same')->and($entries[0]['count'])->toBe(0);
    });

    it('says a definition that was never published is new', function () {
        $admin = signedInAdmin();
        $document = pendingDefinition(ContentDefinition::KIND_VEHICLE, 'rdzak-gt-4')->publishedDocument();
        $document['slug'] = 'brand-new-car';

        app(ContentDrafts::class)->create(ContentDefinition::KIND_VEHICLE, $document, $admin);

        $entries = app(PendingChanges::class)->all();

        expect($entries)->toHaveCount(1)->and($entries[0]['state'])->toBe('new')->and($entries[0]['definition']->slug)->toBe('brand-new-car');
    });

    it('lists only the changes of the cap, and counts all of them', function () {
        signedInAdmin();
        $rules = pendingDefinition(ContentDefinition::KIND_RULES, 'starter');
        $document = $rules->publishedDocument();

        // A draft that has every whole number raised, put in without the checks of the panel, which would not accept it.
        array_walk_recursive($document, function (mixed &$value): void {
            if (is_int($value) && $value > 1) {
                $value++;
            }
        });
        $rules->forceFill(['draft' => JsonDocument::encode($document), 'draft_saved_at' => now()])->save();

        $entry = app(PendingChanges::class)->all()[0];

        expect($entry['count'])->toBeGreaterThan(PendingChanges::LISTED)
            ->and($entry['changes'])->toHaveCount(PendingChanges::LISTED);
    });
});

describe('the page', function () {
    it('says there is nothing when there are no drafts', function () {
        signedInAdmin();

        $this->get(PendingChangesPage::getUrl())->assertOk()->assertSee('data-test="pending-none"', false)->assertSee('Nie ma szkiców.');
    });

    it('lists the definitions with a draft and what each changes, and says which pack is live', function () {
        signedInAdmin();
        pendingDraft(pendingDefinition(ContentDefinition::KIND_VEHICLE, 'rdzak-gt-4'), function (array &$document): void {
            $document['name']['en'] = 'Rdzak GT-4 Mk II';
        });
        pendingDraft(pendingDefinition(ContentDefinition::KIND_RULES, 'starter'), function (array &$document): void {
            $document['player']['other_cars_xp_ppm'] = 250_000;
        });

        $page = $this->get(PendingChangesPage::getUrl())->assertOk();

        $page->assertSee('data-test="pending-definition"', false)
            ->assertSee('data-slug="rdzak-gt-4"', false)
            ->assertSee('data-slug="starter"', false)
            ->assertSee('Rdzak GT-4 Mk II')
            ->assertSee('25%')
            ->assertSee('Definicji ze szkicem: 2. Zmian razem: 2.')
            ->assertSee('Żywa paczka:')
            ->assertSee('Przejdź do publikacji');
    });

    it('is for any admin to read, an editor too', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get(PendingChangesPage::getUrl())->assertOk();
    });

    it('is in the menu of the game with the number of drafts, and the number on the overview leads to it', function () {
        signedInAdmin();
        pendingDraft(pendingDefinition(ContentDefinition::KIND_VEHICLE, 'rdzak-gt-4'), function (array &$document): void {
            $document['name']['en'] = 'Rdzak GT-4 Mk II';
        });

        $this->get(GameOverview::getUrl())->assertOk()->assertSeeHtml('href="'.PendingChangesPage::getUrl().'"');

        expect(PendingChangesPage::getNavigationBadge())->toBe('1');
    });

    it('has no badge when there is nothing to publish', function () {
        signedInAdmin();

        expect(PendingChangesPage::getNavigationBadge())->toBeNull();
    });
});
