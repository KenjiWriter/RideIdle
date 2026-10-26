<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A crate that a driver opened: what was drawn, how it was drawn, and which part it became. */
class CrateOpening extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'opening_index' => 'integer',
            'tier' => 'integer',
            'rarity' => 'integer',
            'price_cents' => 'integer',
            'part_id' => 'integer',
        ];
    }
}
