<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkshopJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Putting a part on a car, taking it off, upgrading, merging or repairing parts, or servicing the car, with the time the mechanic starts and finishes. */
class WorkshopJob extends Model
{
    /** @use HasFactory<WorkshopJobFactory> */
    use HasFactory;

    public const KIND_MOUNT = 'mount';

    public const KIND_UNMOUNT = 'unmount';

    /** Taking a mounted part to the next level of upgrade (+N): paid when it is queued. */
    public const KIND_UPGRADE = 'upgrade';

    /** Merging two parts of the inventory into one of the next rarity: the car is not in it, and it is paid when it is queued. */
    public const KIND_MERGE = 'merge';

    /** Putting a worn part right: its wear is gone when the mechanic is done. Paid when it is queued. */
    public const KIND_REPAIR = 'repair';

    /** The service of the car: the interval of its service starts again when the mechanic is done. Paid when it is queued, and the car stands. */
    public const KIND_SERVICE = 'service';

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
            'merge_part_id' => 'integer',
            'cost_cents' => 'integer',
            'upgrade_to' => 'integer',
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

    /**
     * The second part of a merge, which is used up: the first one (`part`) is the one that stays.
     *
     * @return BelongsTo<Part, $this>
     */
    public function mergePart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'merge_part_id');
    }
}
