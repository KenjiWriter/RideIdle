<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'recovery_code_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const NAME_MIN_LENGTH = 3;

    public const NAME_MAX_LENGTH = 16;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'claimed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'recovery_code_set_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'age_confirmed_at' => 'datetime',
        ];
    }

    /** Key used for the unique driver name check: case does not matter. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** A guest has not secured the account with an e-mail yet. */
    public function isGuest(): bool
    {
        return $this->claimed_at === null;
    }

    public function hasRecoveryCode(): bool
    {
        return $this->recovery_code_hash !== null;
    }

    /** @return HasMany<AccountIdentity, $this> the ways this account can be signed in to */
    public function identities(): HasMany
    {
        return $this->hasMany(AccountIdentity::class);
    }

    /** @return HasOne<Wallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** @return HasMany<RaceRun, $this> */
    public function raceRuns(): HasMany
    {
        return $this->hasMany(RaceRun::class);
    }
}
