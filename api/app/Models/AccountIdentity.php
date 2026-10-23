<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One way to sign in to an account: an e-mail or a provider the driver has linked. */
class AccountIdentity extends Model
{
    public const EMAIL = 'email';

    public const GOOGLE = 'google';

    public const FACEBOOK = 'facebook';

    public const APPLE = 'apple';

    /** The providers of a sign-in with another service, in the order they are offered. */
    public const SOCIAL = [self::GOOGLE, self::FACEBOOK, self::APPLE];

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
