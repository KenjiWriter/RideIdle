<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A storage unit that a driver opened: what was in it and how it was drawn. */
class StorageOpening extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'opening_index' => 'integer',
            'age_years' => 'integer',
            'mileage_km' => 'integer',
            'condition_ppm' => 'integer',
            'value_cents' => 'integer',
            'price_cents' => 'integer',
            'car_id' => 'integer',
        ];
    }
}
