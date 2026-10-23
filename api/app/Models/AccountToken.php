<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time secret of an account flow. Only its hash is stored (see AccountTokens). */
class AccountToken extends Model
{
    public const CLAIM = 'claim';

    public const LOGIN = 'login';

    public const OAUTH = 'oauth';

    public const EXCHANGE = 'exchange';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
