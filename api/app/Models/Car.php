<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /** A car that was sold is gone from every list: only the export and the erasure of the account ask for it (`withoutGlobalScopes`). */
    protected static function booted(): void
    {
        static::addGlobalScope('unsold', static fn (Builder $query) => $query->whereNull('cars.sold_at'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'level' => 'integer',
            'xp' => 'integer',
            'condition_ppm' => 'integer',
            'value_cents' => 'integer',
            'mileage_m' => 'integer',
            'serviced_at_m' => 'integer',
            'sold_at' => 'datetime',
        ];
    }

    /** The average condition of the car in ppm (1 000 000 is perfect): a car that was not given one is perfect. */
    public function conditionPpm(): int
    {
        return $this->condition_ppm ?? 1_000_000;
    }

    /**
     * Where the odometer of the car is and where it was serviced last, in metres: what the wear plan of a run and the service are counted from.
     *
     * @return array{mileage_m: int, serviced_at_m: int}
     */
    public function odometer(): array
    {
        return ['mileage_m' => (int) $this->mileage_m, 'serviced_at_m' => (int) $this->serviced_at_m];
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

    /**
     * The parts that sit on the car, by slot, with their rarity, upgrade and wear: what the statistics of the car are made of, since a part of a rarity,
     * or one that is upgraded, gives more, and a worn one less.
     *
     * @return array<string, array{slug: string, rarity: int, upgrade: int, wear: int}>
     */
    public function mountedParts(): array
    {
        return $this->parts()->whereNotNull('mounted_slot')->orderBy('mounted_slot')->get()
            ->mapWithKeys(static fn (Part $part): array => [$part->mounted_slot => ['slug' => $part->part_slug, 'rarity' => (int) $part->rarity, 'upgrade' => (int) $part->upgrade_level, 'wear' => (int) $part->wear_ppb, 'affixes' => $part->affixes ?? []]])
            ->all();
    }
}
