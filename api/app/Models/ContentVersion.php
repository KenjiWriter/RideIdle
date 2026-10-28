<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One state of the document of a definition. A version is only ever added: a change of the definition is the next number. */
class ContentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<ContentDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ContentDefinition::class, 'content_definition_id');
    }
}
