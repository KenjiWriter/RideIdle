<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkshopJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Putting a part on a car or taking it off, with the time the mechanic starts and finishes. */
class WorkshopJob extends Model
{
    /** @use HasFactory<WorkshopJobFactory> */
    use HasFactory;

    public const KIND_MOUNT = 'mount';

    public const KIND_UNMOUNT = 'unmount';

    /** Waiting for the mechanic. */
    public const STATUS_QUEUED = 'queued';

    /** The mechanic is at it, and the car stands in the workshop. */
    public const STATUS_WORKING = 'working';

    public const STATUS_DONE = 'done';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'car_id' => 'integer',
            'part_id' => 'integer',
            'start_ms' => 'integer',
            'finish_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Car, $this> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
