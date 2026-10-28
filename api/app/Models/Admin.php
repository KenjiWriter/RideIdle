<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\AdminAudit;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

/**
 * Somebody who may use the admin panel. An editor looks at the content and prepares it; a publisher also makes a pack live. Both sign in with a password and a
 * code of an authenticator app, and an admin without the app is made to set it up before anything else.
 */
class Admin extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use Notifiable;

    public const ROLE_EDITOR = 'editor';

    public const ROLE_PUBLISHER = 'publisher';

    public const ROLES = [self::ROLE_EDITOR, self::ROLE_PUBLISHER];

    protected $guarded = [];

    /** @var array<string, mixed> What a new admin is before the database says so: an editor who is switched on. */
    protected $attributes = ['role' => self::ROLE_EDITOR, 'is_active' => true];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (self $admin): void {
            if ($admin->wasChanged('password')) {
                app(AdminAudit::class)->record('password_changed', $admin);
            }
        });
    }

    /** The panel is for admins who are switched on. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isPublisher(): bool
    {
        return $this->role === self::ROLE_PUBLISHER;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();

        app(AdminAudit::class)->record($secret === null ? 'mfa_disabled' : 'mfa_enabled', $this);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return list<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param  list<string>|null  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();

        app(AdminAudit::class)->record('mfa_recovery_codes_changed', $this);
    }
}
