<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The moment a pack became the one the game reads. The last row is the live one; going back is a new row. */
class ContentActivation extends Model
{
    use BelongsToGame;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<ContentPack, $this> */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(ContentPack::class, 'content_pack_id');
    }
}
