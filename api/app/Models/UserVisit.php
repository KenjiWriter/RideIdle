<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A visit of a driver: the first request after a break of ten minutes. It is the only record of when the drivers come back (`Metrics`). */
class UserVisit extends Model
{
    /** @use HasFactory<UserVisitFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'at' => 'datetime', 'gap_minutes' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
