<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A picture of a car or of a part, kept under the SHA-256 of its bytes. It never changes; `data` is the bytes as base64. */
class ContentImage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** The name that a document uses for the picture: the checksum and the kind of the file. */
    public function name(): string
    {
        return "{$this->sha256}.{$this->kind}";
    }
}
