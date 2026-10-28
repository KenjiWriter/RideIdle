<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Run\WearPlan;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A part a driver owns: in the inventory, or mounted on a car, in the slot it sits in. */
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'car_id' => 'integer',
            'rarity' => 'integer',
            'affixes' => 'array',
            'upgrade_level' => 'integer',
            'wear_ppb' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Car, $this> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** How much of its condition the part has left, in ppm (1 000 000 is new, 0 is worn out). */
    public function conditionPpm(): int
    {
        return WearPlan::conditionPpm((int) $this->wear_ppb);
    }

    public function isMounted(): bool
    {
        return $this->mounted_slot !== null;
    }
}
