<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TutorialProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A step of the tutorial that a driver has reached, and what became of it. */
class TutorialProgress extends Model
{
    /** @use HasFactory<TutorialProgressFactory> */
    use HasFactory;

    public const STATE_ACTIVE = 'active';

    public const STATE_DONE = 'done';

    public const STATE_SKIPPED = 'skipped';

    protected $table = 'tutorial_progress';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'started_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
