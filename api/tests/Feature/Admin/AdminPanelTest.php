<?php

declare(strict_types=1);

use App\Filament\Resources\AdminAuditLogs\AdminAuditLogResource;
use App\Filament\Resources\AdminAuditLogs\Pages\ManageAdminAuditLogs;
use App\Filament\Resources\ContentPacks\ContentPackResource;
use App\Filament\Resources\ContentPacks\Pages\ManageContentPacks;
use App\Filament\Widgets\LivePackWidget;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ContentPack;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Panel;
use Livewire\Livewire;

// The admin panel: who gets in, what each role sees and does, and what is written down.

/** An admin who has set up the authenticator app, which is what the panel asks of everyone before it shows anything. */
function signedInAdmin(string $role = Admin::ROLE_EDITOR, string $email = 'boss@example.com'): Admin
{
    $admin = anAdmin(['email' => $email, 'role' => $role]);
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $admin->saveAppAuthenticationRecoveryCodes(['abcd-1234']);
    test()->actingAs($admin, 'admin');
    Filament::setCurrentPanel('admin');

    return $admin;
}

describe('getting in', function () {
    it('sends somebody who is not signed in to the sign-in page, and shows it', function () {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
        $this->get('/admin/content-packs')->assertRedirect('/admin/login');
    });

    it('does not take a player for an admin, whatever the player is signed in as', function () {
        $player = User::factory()->guest()->create();

        $this->actingAs($player, 'web')->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($player, 'sanctum')->get('/admin')->assertRedirect('/admin/login');
    });

    it('lets an admin in who has the authenticator app', function () {
        signedInAdmin();

        $this->get('/admin')->assertOk();
    });

    it('sends an admin who has not set up the authenticator app to set it up first, and shows nothing else', function () {
        $without = anAdmin(['email' => 'new@example.com']);

        $this->actingAs($without, 'admin')->get('/admin')->assertRedirectContains('multi-factor');
        $this->get('/admin/content-packs')->assertRedirectContains('multi-factor');
    });

    it('does not let in an admin who is switched off, though the password is right', function () {
        $admin = signedInAdmin();
        $admin->update(['is_active' => false]);

        $this->get('/admin')->assertForbidden();
    });

    it('speaks the language of the people who run it, not the language of whoever asks', function () {
        $this->get('/admin/login', ['Accept-Language' => 'de'])->assertOk();
        expect(app()->getLocale())->toBe('pl');

        config(['ride.admin_locale' => 'fr']);
        $this->get('/admin/login')->assertOk();
        expect(app()->getLocale())->toBe('fr');
    });

    it('answers on a host of its own when there is one, and under /admin on the host of the application when there is not', function () {
        $provider = new AdminPanelProvider(app());

        config(['ride.admin_domain' => null]);
        $here = $provider->panel(Panel::make());

        config(['ride.admin_domain' => 'admin.example.test']);
        $there = $provider->panel(Panel::make());

        expect($here->getPath())->toBe('admin')->and($here->getDomains())->toBe([])
            ->and($there->getPath())->toBe('')->and($there->getDomains())->toBe(['admin.example.test'])
            ->and($there->getAuthGuard())->toBe('admin');
    });

    it('asks for the authenticator app of everybody', function () {
        $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

        expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue()
            ->and(array_keys($panel->getMultiFactorAuthenticationProviders()))->toBe(['app']);
    });
});

describe('the packs', function () {
    it('are listed with the live one marked, newest first, for an editor too', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false, activate: true)->pack;
        $second = contentPacks()->import(repositoryContent(), '2026.10.1', checkTables: false)->pack;
        signedInAdmin();

        Livewire::test(ManageContentPacks::class)
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->assertSee('2026.10.0')
            ->assertSee('2026.10.1');
    });

    it('are made live by a publisher, and the trail says who, which pack and which one it was before', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false, activate: true)->pack;
        $second = contentPacks()->import(repositoryContent(), '2026.10.1', checkTables: false)->pack;
        $admin = signedInAdmin(Admin::ROLE_PUBLISHER);

        Livewire::test(ManageContentPacks::class)
            ->assertTableActionHidden('activate', $first)
            ->assertTableActionVisible('activate', $second)
            ->callTableAction('activate', $second)
            // The table that is shown after it says the new one is live.
            ->assertTableActionHidden('activate', $second)
            ->assertTableActionVisible('activate', $first);

        $log = AdminAuditLog::query()->where('action', 'content_pack_activated')->firstOrFail();

        expect(contentPacks()->active()->id)->toBe($second->id)
            ->and($log->admin_id)->toBe($admin->id)
            ->and($log->subject)->toBe('content_pack:2026.10.1')
            ->and($log->context)->toBe(['previous' => '2026.10.0']);

        // Going back is the same: the earlier pack, made live again.
        Livewire::test(ManageContentPacks::class)->callTableAction('activate', $first);

        expect(contentPacks()->active()->id)->toBe($first->id)
            ->and(AdminAuditLog::query()->where('action', 'content_pack_activated')->count())->toBe(2);
    });

    it('are not made live by an editor, who is not shown the button', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false, activate: true)->pack;
        $second = contentPacks()->import(repositoryContent(), '2026.10.1', checkTables: false)->pack;
        signedInAdmin(Admin::ROLE_EDITOR);

        Livewire::test(ManageContentPacks::class)->assertTableActionHidden('activate', $second);

        expect(contentPacks()->active()->id)->toBe($first->id)
            ->and(AdminAuditLog::query()->where('action', 'content_pack_activated')->count())->toBe(0);
    });

    it('are refused when they do not fit this version of the game, and the trail says why', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false, activate: true)->pack;
        // The Rdzak is stronger than the table of the bot profiles of this pack says.
        $stale = contentPacks()->import(contentWith(stronger()), '2026.10.1', checkTables: false)->pack;
        signedInAdmin(Admin::ROLE_PUBLISHER);

        Livewire::test(ManageContentPacks::class)->callTableAction('activate', $stale);

        $log = AdminAuditLog::query()->where('action', 'content_pack_refused')->firstOrFail();

        expect(contentPacks()->active()->id)->toBe($first->id)
            ->and($log->subject)->toBe('content_pack:2026.10.1')
            ->and($log->context['errors'][0])->toContain('tables/bot-profiles.json')
            ->and(AdminAuditLog::query()->where('action', 'content_pack_activated')->count())->toBe(0);
    });

    it('have the live one on the dashboard, and say when the game does not read from the database at all', function () {
        contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
        signedInAdmin();

        Livewire::test(LivePackWidget::class)->assertSee('2026.10.0');

        $this->get('/admin')->assertOk();
    });
});

describe('the trail', function () {
    it('is not read by an editor', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/admin-audit-logs')->assertForbidden();
    });

    it('is read by a publisher, line by line', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER, 'pub@example.com');

        $this->get('/admin/admin-audit-logs')->assertOk();

        Livewire::test(ManageAdminAuditLogs::class)
            ->assertCanSeeTableRecords(AdminAuditLog::query()->get())
            ->assertSee('pub@example.com');
    });

    it('is not written to by anybody from the panel', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);

        expect(AdminAuditLogResource::canCreate())->toBeFalse()
            ->and(AdminAuditLogResource::canEdit(new AdminAuditLog))->toBeFalse()
            ->and(AdminAuditLogResource::canDelete(new AdminAuditLog))->toBeFalse()
            ->and(ContentPackResource::canCreate())->toBeFalse()
            ->and(ContentPackResource::canEdit(new ContentPack))->toBeFalse()
            ->and(ContentPackResource::canDelete(new ContentPack))->toBeFalse();
    });
});
