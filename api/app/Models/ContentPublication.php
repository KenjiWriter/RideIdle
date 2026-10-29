<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The making of one pack of content out of the drafts: who asked for it, the number the pack will have, how far it got, and what it found.
 */
class ContentPublication extends Model
{
    use BelongsToGame;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    /** The pack is made and has passed everything: it waits to be made live. */
    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['report' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<Admin, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    /** @return BelongsTo<ContentPack, $this> */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(ContentPack::class, 'content_pack_id');
    }

    /** Whether it is still going to be built, or is being built: a new one is not started meanwhile. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
