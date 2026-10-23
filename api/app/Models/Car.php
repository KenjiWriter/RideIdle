<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A car a driver owns: an instance of a vehicle definition of the content pack, with the parts mounted on it. */
class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'level' => 'integer',
            'xp' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Part, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    /**
     * The slugs of the parts that sit on the car, by slot (in the order of the slots): what the statistics of the car are made of.
     *
     * @return array<string, string>
     */
    public function mountedSlugs(): array
    {
        return $this->parts()->whereNotNull('mounted_slot')->orderBy('mounted_slot')->pluck('part_slug', 'mounted_slot')->all();
    }
}
