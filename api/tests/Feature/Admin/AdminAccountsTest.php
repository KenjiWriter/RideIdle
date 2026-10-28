<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use Filament\Panel;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// The people who run the content are not players: their own table, their own guard, a password and an authenticator app, and a trail of what they do.

describe('an admin', function () {
    it('keeps the password hashed, and the secret of the authenticator app and the recovery codes encrypted', function () {
        $admin = anAdmin();

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['abcd-1234', 'efgh-5678']);

        $row = DB::table('admins')->where('id', $admin->id)->first();

        expect($row->password)->not->toBe('a-long-enough-password')->and(password_verify('a-long-enough-password', $row->password))->toBeTrue()
            ->and($row->app_authentication_secret)->not->toContain('JBSWY3DPEHPK3PXP')
            ->and($row->app_authentication_recovery_codes)->not->toContain('abcd-1234')
            ->and($admin->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
            ->and($admin->fresh()->getAppAuthenticationRecoveryCodes())->toBe(['abcd-1234', 'efgh-5678'])
            ->and($admin->fresh()->getAppAuthenticationHolderName())->toBe('boss@example.com')
            ->and($admin->toArray())->not->toHaveKeys(['password', 'app_authentication_secret', 'app_authentication_recovery_codes']);
    });

    it('is an editor until made a publisher, and only a publisher is one', function () {
        $editor = anAdmin();

        expect($editor->role)->toBe(Admin::ROLE_EDITOR)
            ->and($editor->fresh()->role)->toBe(Admin::ROLE_EDITOR)
            ->and($editor->isPublisher())->toBeFalse()
            ->and(anAdmin(['email' => 'pub@example.com', 'role' => Admin::ROLE_PUBLISHER])->isPublisher())->toBeTrue();
    });

    it('may use the panel while switched on and not after', function () {
        $panel = Mockery::mock(Panel::class);

        expect(anAdmin()->canAccessPanel($panel))->toBeTrue()
            ->and(anAdmin(['email' => 'off@example.com', 'is_active' => false])->canAccessPanel($panel))->toBeFalse();
    });

    it('has a guard of its own: a player is not an admin, and an admin is not a player', function () {
        anAdmin();
        User::factory()->guest()->create(['email' => 'player@example.com', 'password' => 'a-long-enough-password']);

        expect(Auth::guard('admin')->attempt(['email' => 'boss@example.com', 'password' => 'a-long-enough-password']))->toBeTrue()
            ->and(Auth::guard('admin')->user())->toBeInstanceOf(Admin::class);

        Auth::guard('admin')->logout();

        expect(Auth::guard('admin')->attempt(['email' => 'player@example.com', 'password' => 'a-long-enough-password']))->toBeFalse()
            ->and(Auth::guard('web')->attempt(['email' => 'boss@example.com', 'password' => 'a-long-enough-password']))->toBeFalse();
    });
});

describe('the trail of the panel', function () {
    it('writes a sign-in, with the time of it kept on the admin, and a sign-out', function () {
        $admin = anAdmin();

        Auth::guard('admin')->login($admin);
        Auth::guard('admin')->logout();

        expect(AdminAuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['login', 'logout'])
            ->and(AdminAuditLog::query()->first()->admin_id)->toBe($admin->id)
            ->and(AdminAuditLog::query()->first()->email)->toBe('boss@example.com')
            ->and($admin->fresh()->last_login_at)->not->toBeNull();
    });

    it('writes a sign-in that failed, with the address that was tried whether or not it is an admin', function () {
        $admin = anAdmin();

        expect(Auth::guard('admin')->attempt(['email' => 'boss@example.com', 'password' => 'wrong']))->toBeFalse()
            ->and(Auth::guard('admin')->attempt(['email' => 'nobody@example.com', 'password' => 'wrong']))->toBeFalse();

        $rows = AdminAuditLog::query()->orderBy('id')->get();

        expect($rows->pluck('action')->all())->toBe(['login_failed', 'login_failed'])
            ->and($rows->pluck('email')->all())->toBe(['boss@example.com', 'nobody@example.com'])
            ->and($rows[0]->admin_id)->toBe($admin->id)
            ->and($rows[1]->admin_id)->toBeNull();
    });

    it('leaves the sign-ins of the players out of it', function () {
        $player = User::factory()->guest()->create();

        Auth::guard('web')->login($player);
        Event::dispatch(new Failed('web', null, ['email' => 'player@example.com']));
        Auth::guard('web')->logout();

        expect(AdminAuditLog::query()->count())->toBe(0);
    });

    it('writes the setting up and the taking off of the authenticator app, the new recovery codes and a new password', function () {
        $admin = anAdmin();

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['abcd-1234']);
        $admin->update(['password' => 'another-long-password']);
        $admin->saveAppAuthenticationSecret(null);

        expect(AdminAuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['mfa_enabled', 'mfa_recovery_codes_changed', 'password_changed', 'mfa_disabled'])
            // The secret is not in the trail.
            ->and(json_encode(AdminAuditLog::query()->get()->toArray()))->not->toContain('JBSWY3DPEHPK3PXP');
    });

    it('is only ever added to: an admin who is deleted leaves their lines, nameless', function () {
        $admin = anAdmin();
        Auth::guard('admin')->login($admin);

        $admin->delete();

        expect(AdminAuditLog::query()->count())->toBe(1)
            ->and(AdminAuditLog::query()->first()->admin_id)->toBeNull()
            ->and(AdminAuditLog::query()->first()->email)->toBe('boss@example.com');
    });
});

describe('admin:create', function () {
    it('makes an admin with a password that is shown once, and writes it to the trail without the password', function () {
        $this->artisan('admin:create', ['email' => 'New@Example.com', '--role' => 'publisher', '--name' => 'New One'])
            ->expectsOutputToContain('new@example.com is a publisher now.')
            ->assertExitCode(0);

        $admin = Admin::query()->where('email', 'new@example.com')->firstOrFail();
        $log = AdminAuditLog::query()->where('action', 'admin_created')->firstOrFail();

        expect($admin->name)->toBe('New One')
            ->and($admin->isPublisher())->toBeTrue()
            ->and($admin->is_active)->toBeTrue()
            ->and($admin->getAppAuthenticationSecret())->toBeNull()
            ->and($log->subject)->toBe('admin:new@example.com')
            ->and($log->context)->toBe(['role' => 'publisher', 'by' => 'cli']);
    });

    it('makes an editor by default, named after the address', function () {
        $this->artisan('admin:create', ['email' => 'someone@example.com'])->assertExitCode(0);

        $admin = Admin::query()->firstOrFail();

        expect($admin->role)->toBe('editor')->and($admin->name)->toBe('someone');
    });

    it('refuses what is not an address, a role that does not exist, and somebody who is an admin already', function () {
        anAdmin();

        $this->artisan('admin:create', ['email' => 'nobody'])->expectsOutputToContain('is not an e-mail address')->assertExitCode(1);
        $this->artisan('admin:create', ['email' => 'x@example.com', '--role' => 'king'])->expectsOutputToContain('The role is editor or publisher')->assertExitCode(1);
        $this->artisan('admin:create', ['email' => 'Boss@example.com'])->expectsOutputToContain('is an admin already')->assertExitCode(1);

        expect(Admin::query()->count())->toBe(1);
    });
});

describe('admin:update', function () {
    it('changes the role, switches an admin off and on, and writes what it did', function () {
        $admin = anAdmin();

        $this->artisan('admin:update', ['email' => 'boss@example.com', '--role' => 'publisher', '--deactivate' => true])
            ->expectsOutputToContain('role: publisher, switched off')
            ->assertExitCode(0);

        expect($admin->fresh()->isPublisher())->toBeTrue()->and($admin->fresh()->is_active)->toBeFalse();

        $this->artisan('admin:update', ['email' => 'boss@example.com', '--activate' => true])->assertExitCode(0);

        expect($admin->fresh()->is_active)->toBeTrue()
            ->and(AdminAuditLog::query()->where('action', 'admin_updated')->count())->toBe(2);
    });

    it('takes the authenticator app off for an admin who lost the phone, and gives a new password once', function () {
        $admin = anAdmin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['abcd-1234']);
        $before = $admin->fresh()->password;

        $this->artisan('admin:update', ['email' => 'boss@example.com', '--reset-mfa' => true, '--reset-password' => true])
            ->expectsOutputToContain('new password, authenticator app taken off')
            ->assertExitCode(0);

        expect($admin->fresh()->getAppAuthenticationSecret())->toBeNull()
            ->and($admin->fresh()->getAppAuthenticationRecoveryCodes())->toBeNull()
            ->and($admin->fresh()->password)->not->toBe($before);
    });

    it('does not know an admin who is not there, and does not guess what is wanted', function () {
        anAdmin();

        $this->artisan('admin:update', ['email' => 'nobody@example.com', '--activate' => true])->expectsOutputToContain('There is no admin')->assertExitCode(1);
        $this->artisan('admin:update', ['email' => 'boss@example.com', '--activate' => true, '--deactivate' => true])->expectsOutputToContain('at once')->assertExitCode(1);
        $this->artisan('admin:update', ['email' => 'boss@example.com', '--role' => 'king'])->expectsOutputToContain('The role is')->assertExitCode(1);
        $this->artisan('admin:update', ['email' => 'boss@example.com'])->expectsOutputToContain('Nothing to change')->assertExitCode(0);
    });
});
