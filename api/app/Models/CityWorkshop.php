<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Workshop\WorkshopGear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the workshop of a driver in a city has bought: the levels of its equipment, its bays and its mechanics. A city without a row has the start state. */
class CityWorkshop extends Model
{
    protected $table = 'city_workshops';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'tire_machine' => 'integer',
            'lift' => 'integer',
            'hoist' => 'integer',
            'toolkit' => 'integer',
            'bays' => 'integer',
            'mechanics' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gear(): WorkshopGear
    {
        return new WorkshopGear($this->tire_machine, $this->lift, $this->hoist, $this->toolkit, $this->bays, $this->mechanics);
    }
}
