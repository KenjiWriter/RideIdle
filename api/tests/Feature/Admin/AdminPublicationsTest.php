<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Filament\Resources\ContentPublications\Pages\ManageContentPublications;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentDefinition;
use App\Models\ContentPublication;
use App\Services\ContentDrafts;
use App\Services\ContentPublisher;
use Livewire\Livewire;

// The publications in the panel: a publisher asks for a pack of the drafts and sees how it went, and makes a pack that is ready live. The content of the
// repository is what is in the database, and it is live.

/** A draft of a car that the pack of the drafts holds. */
function aDraftOfTheCar(Admin $by): void
{
    $car = JsonDocument::decode((string) repositoryContent()->raw('vehicles/rdzak-gt-4'));
    $definition = ContentDefinition::query()->where('kind', 'vehicle')->where('slug', 'rdzak-gt-4')->firstOrFail();

    app(ContentDrafts::class)->save($definition, [...$car, 'price_cents' => $car['price_cents'] + 100_000], $by);
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
    app()->setLocale('pl');
});

describe('the list', function () {
    it('is for any admin to look at, but only a publisher is offered a pack to ask for', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/content-publications')->assertOk();

        Livewire::test(ManageContentPublications::class)->assertActionHidden('prepare');
    });

    it('is where a publisher asks for a pack of the drafts, which is built and waits to be made live', function () {
        $admin = signedInAdmin(Admin::ROLE_PUBLISHER);
        aDraftOfTheCar($admin);
        $live = contentPacks()->active();

        Livewire::test(ManageContentPublications::class)
            ->assertActionVisible('prepare')
            ->callAction('prepare', ['note' => 'a dearer Rdzak'])
            ->assertNotified(__('admin.publications.queued', ['number' => now()->year.'.'.now()->month.'.1']));

        $publication = ContentPublication::query()->firstOrFail();

        expect($publication->status)->toBe(ContentPublication::STATUS_READY)->and($publication->note)->toBe('a dearer Rdzak')
            ->and(contentPacks()->active()->id)->toBe($live->id);

        Livewire::test(ManageContentPublications::class)->assertCanSeeTableRecords([$publication])->assertSee('Gotowa');
    });

    it('says when there is nothing to make a pack of, and asks nothing of the queue', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);

        Livewire::test(ManageContentPublications::class)
            ->callAction('prepare', ['note' => null])
            ->assertNotified(__('admin.publications.refused.nothing_to_publish'));

        expect(ContentPublication::query()->count())->toBe(0);
    });
});

describe('the report', function () {
    it('says how it went: the gates, what the pack changes, and the derived tables', function () {
        $admin = signedInAdmin(Admin::ROLE_PUBLISHER);
        aDraftOfTheCar($admin);
        Livewire::test(ManageContentPublications::class)->callAction('prepare', ['note' => null]);
        $publication = ContentPublication::query()->firstOrFail();

        Livewire::test(ManageContentPublications::class)
            ->mountTableAction('report', $publication)
            ->assertMountedActionModalSee(['tracks:simulate --strict', 'economy:simulate --strict --strict-game', 'vehicles/rdzak-gt-4', 'tables/unit-returns']);
    });

    it('says what stopped a pack that did not get built', function () {
        $admin = signedInAdmin(Admin::ROLE_PUBLISHER);
        $rules = JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
        foreach ($rules['tracks'] as $slug => $track) {
            $rules['tracks'][$slug]['xp_per_lap'] = $track['xp_per_lap'] * 10;
        }
        app(ContentDrafts::class)->save(ContentDefinition::query()->where('kind', 'rules')->where('slug', 'starter')->firstOrFail(), $rules, $admin);

        Livewire::test(ManageContentPublications::class)->callAction('prepare', ['note' => null]);
        $publication = ContentPublication::query()->firstOrFail();

        expect($publication->status)->toBe(ContentPublication::STATUS_FAILED);

        Livewire::test(ManageContentPublications::class)
            ->assertSee('Zatrzymana')
            ->mountTableAction('report', $publication)
            ->assertMountedActionModalSee(__('admin.publications.not_passed'));

        Livewire::test(ManageContentPublications::class)->assertTableActionHidden('activate', $publication);
    });
});

describe('making a pack live', function () {
    it('is for a publisher, for a pack that is ready', function () {
        $admin = signedInAdmin(Admin::ROLE_PUBLISHER);
        aDraftOfTheCar($admin);
        Livewire::test(ManageContentPublications::class)->callAction('prepare', ['note' => null]);
        $publication = ContentPublication::query()->firstOrFail();

        Livewire::test(ManageContentPublications::class)
            ->assertTableActionVisible('activate', $publication)
            ->callTableAction('activate', $publication)
            ->assertNotified(__('admin.packs.activated', ['number' => $publication->number]));

        expect(contentPacks()->active()->id)->toBe($publication->content_pack_id)
            ->and(AdminAuditLog::query()->where('action', 'content_pack_activated')->value('subject'))->toBe("content_pack:{$publication->number}")
            ->and(ContentDefinition::query()->where('slug', 'rdzak-gt-4')->first()->hasDraft())->toBeFalse();

        Livewire::test(ManageContentPublications::class)->assertTableActionHidden('activate', $publication);
    });

    it('is not offered to an editor', function () {
        $publisher = anAdmin(['email' => 'publisher@example.com', 'role' => Admin::ROLE_PUBLISHER]);
        aDraftOfTheCar($publisher);
        app(ContentPublisher::class)->queue($publisher);
        $publication = ContentPublication::query()->firstOrFail();

        signedInAdmin(Admin::ROLE_EDITOR, 'editor@example.com');

        Livewire::test(ManageContentPublications::class)->assertTableActionHidden('activate', $publication)->assertTableActionVisible('report', $publication);
    });
});
