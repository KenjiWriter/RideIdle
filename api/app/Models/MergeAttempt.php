<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An attempt of merging two parts: the chance it had, what was drawn, how it was drawn, and what came of it. */
class MergeAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'job_id' => 'integer',
            'tier' => 'integer',
            'to_rarity' => 'integer',
            'part_id' => 'integer',
            'chance_ppm' => 'integer',
            'roll_ppm' => 'integer',
            'success' => 'boolean',
            'scrap_cents' => 'integer',
            'finished_ms' => 'integer',
        ];
    }
}
