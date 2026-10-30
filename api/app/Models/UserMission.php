<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mission that a driver has: the goal and the reward it was drawn with, how far the driver is, and whether the reward was taken. */
class UserMission extends Model
{
    public const KIND_MAIN = 'main';

    public const KIND_DAILY = 'daily';

    public const KIND_WEEKLY = 'weekly';

    public const KIND_MONTHLY = 'monthly';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'slot' => 'integer',
            'params' => 'array',
            'target' => 'integer',
            'progress' => 'integer',
            'reward' => 'array',
            'window_from_ms' => 'integer',
            'window_to_ms' => 'integer',
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
            'auto_claimed' => 'boolean',
            'claim_result' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    /** open (under way), completed (done, not taken) or claimed. */
    public function status(): string
    {
        return $this->isClaimed() ? 'claimed' : ($this->isCompleted() ? 'completed' : 'open');
    }
}
