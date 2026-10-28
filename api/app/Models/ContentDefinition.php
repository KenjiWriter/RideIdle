<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A thing of the game that the content describes (a car, a part, the rules, a table): its identity, which the versions of its document hang on. */
class ContentDefinition extends Model
{
    protected $guarded = [];

    /** @return HasMany<ContentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }
}
