<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An immutable snapshot of the content: one version of each definition it names, with a number and the checksum of what it holds. */
class ContentPack extends Model
{
    use BelongsToGame;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsToMany<ContentVersion, $this> */
    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(ContentVersion::class, 'content_pack_items');
    }

    /** @return HasMany<ContentActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(ContentActivation::class);
    }
}
