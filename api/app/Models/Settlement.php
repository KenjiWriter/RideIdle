<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What one settlement of a run changed, with its log of events. */
class Settlement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state_version' => 'integer',
            'cursor_from_ms' => 'integer',
            'cursor_to_ms' => 'integer',
            'now_ms' => 'integer',
            'laps' => 'integer',
            'cash_cents' => 'integer',
            'xp' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RaceRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RaceRun::class, 'race_run_id');
    }

    /** @return HasMany<SettlementEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SettlementEvent::class)->orderBy('seq');
    }
}
