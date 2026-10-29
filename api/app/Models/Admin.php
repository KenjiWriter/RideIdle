<?php

declare(strict_types=1);

namespace App\Models;

use App\Games\CurrentGame;
use App\Services\AdminAudit;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
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
    protected $attributes = ['role' => self::ROLE_EDITOR, 'is_active' => true, 'all_games' => true];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'all_games' => 'boolean',
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

    /** Whether the admin is a publisher in the game that is in play (see `CurrentGame`): in every game, for an admin whose role is the same in all of them. */
    public function isPublisher(): bool
    {
        return $this->roleIn(app(CurrentGame::class)->id()) === self::ROLE_PUBLISHER;
    }

    /** @return BelongsToMany<Game, $this> the games that the admin was given one by one, with the role in each (an admin who may work in every game has none listed) */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'admin_games')->withPivot('role')->withTimestamps();
    }

    /**
     * The games that the admin may work in: every game for an admin who may work in all of them, else the ones they were given. A game that is only a draft is one of
     * them, because an admin is who makes it.
     *
     * @return Collection<int, Game>
     */
    public function allowedGames(): Collection
    {
        return $this->all_games ? Game::query()->orderBy('id')->get() : $this->games()->orderBy('games.id')->get();
    }

    public function canEnter(Game|int $game): bool
    {
        $id = $game instanceof Game ? $game->id : $game;

        return $this->all_games || $this->games()->where('games.id', $id)->exists();
    }

    /** The role of the admin in a game: the one they were given for it, else their own. */
    public function roleIn(Game|int $game): string
    {
        $id = $game instanceof Game ? $game->id : $game;

        if ($this->all_games) {
            return $this->role;
        }

        return $this->games()->where('games.id', $id)->first()?->pivot->role ?? $this->role;
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
