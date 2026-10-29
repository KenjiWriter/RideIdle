<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to the server about the domain of a game (see the migration): the panel asks, the worker on the server does it and says how it went. */
class DomainJob extends Model
{
    public const ACTION_PROVISION = 'provision';

    public const ACTION_CHECK = 'check';

    public const ACTIONS = [self::ACTION_PROVISION, self::ACTION_CHECK];

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'requested_by' => 'integer',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'certificate_expires_at' => 'date',
        ];
    }

    /** Whether the worker has not finished with it: it waits for the worker or is being done. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_RUNNING], true);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }
}
