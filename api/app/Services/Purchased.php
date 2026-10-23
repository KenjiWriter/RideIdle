<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Part;

/**
 * What a purchase in the shop came to. A request that was already made once is `repeated`: it charged and gave
 * nothing the second time, and `part` is null because the one it made is in the inventory already.
 */
final readonly class Purchased
{
    public function __construct(public ?Part $part, public int $costCents, public bool $repeated) {}
}
