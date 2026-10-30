<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A set of missions of a day, a week or a month that was drawn for a driver, and the bonus of the premium coins for having done all of it. */
class UserMissionPeriod extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'missions' => 'integer',
            'bonus_coins' => 'integer',
            'window_from_ms' => 'integer',
            'window_to_ms' => 'integer',
            'completed_at' => 'datetime',
            'bonus_claimed_at' => 'datetime',
            'bonus_auto_claimed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** locked (not all done), ready (all done, not taken) or claimed. A set that pays no coins has no bonus. */
    public function bonusState(): string
    {
        return $this->bonus_claimed_at !== null ? 'claimed' : ($this->completed_at !== null ? 'ready' : 'locked');
    }
}
