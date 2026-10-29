<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One of the games that this installation runs: its own content, its own players, its own domain. */
class Game extends Model
{
    public const STATUS_LIVE = 'live';

    /** A draft game answers to nobody but an admin who chose it: it is being made. */
    public const STATUS_DRAFT = 'draft';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /** @return BelongsToMany<Admin, $this> the admins that were given this game one by one (the ones that may work in every game are not listed) */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'admin_games')->withPivot('role')->withTimestamps();
    }
}
