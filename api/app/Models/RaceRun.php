<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RaceRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A car driving laps on a track, with the state that settlement changes and the frozen configuration. */
class RaceRun extends Model
{
    /** @use HasFactory<RaceRunFactory> */
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_STOPPED = 'stopped';

    /** The car stands in the workshop while a job on it is in progress. The other stop reasons are events of settlement. */
    public const STOP_WORKSHOP = 'workshop';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'car_id' => 'integer',
            'config' => 'array',
            'cursor_ms' => 'integer',
            'laps_done' => 'integer',
            'fuel_ml' => 'integer',
            'break_after_laps' => 'integer',
            'state_version' => 'integer',
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

    /** @return HasMany<Settlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
