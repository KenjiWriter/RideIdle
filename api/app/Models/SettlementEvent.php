<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a settlement log: a batch of laps, a breakdown or an empty tank. */
class SettlementEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['seq' => 'integer', 'at_ms' => 'integer', 'payload' => 'array'];
    }

    /** @return BelongsTo<Settlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
