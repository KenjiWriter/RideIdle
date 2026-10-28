<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TravelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A car on the road from one city to another: it left at `start_ms` and arrives at `arrive_ms`, and earns nothing on the way. */
class Travel extends Model
{
    /** @use HasFactory<TravelFactory> */
    use HasFactory;

    public const STATUS_TRAVELLING = 'travelling';

    public const STATUS_ARRIVED = 'arrived';

    protected $table = 'travels';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'race_run_id' => 'integer',
            'distance_km' => 'integer',
            'fuel_ml' => 'integer',
            'start_ms' => 'integer',
            'arrive_ms' => 'integer',
            'boosted' => 'boolean',
            'police_at_ms' => 'integer',
            'police_fine_cents' => 'integer',
            'police_paid_cents' => 'integer',
            'police_resolved_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<RaceRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RaceRun::class, 'race_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
