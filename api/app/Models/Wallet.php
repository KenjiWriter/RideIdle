<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The balance of a driver: cash in cents, XP, premium coins and the charges of the auto-refill, and until when the VIP account and the pass of the endless refill last. What can be spent changes only through the ledger, under a row lock. */
class Wallet extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cash_cents' => 'integer',
            'xp' => 'integer',
            'premium_coins' => 'integer',
            'auto_refills' => 'integer',
            'auto_refill_enabled' => 'boolean',
            'vip_until' => 'datetime',
            'infinity_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'user_id', 'user_id');
    }
}
