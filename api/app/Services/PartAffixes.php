<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Domain\Market\Affixes;
use App\Domain\Run\Rarity;
use App\Models\Part;

/**
 * Gives a part the affixes of its rarity: when a crate makes it, when a merge makes it the next rarity (it keeps what it has and gets what it lacks), and, once,
 * for a part from before the affixes. The draw is the one of `App\Domain\Market\Affixes`, from a seed that is made of the secret of the application, the driver,
 * the part and the rarity, so that a part gets the same affixes whichever way it came to them.
 */
final class PartAffixes
{
    public function __construct(private readonly Rules $rules) {}

    /** The seed of the draw of the affixes of a part at a rarity. */
    public function seed(Part $part, Rarity $rarity): string
    {
        return hash_hmac('sha256', "affix|{$part->user_id}|{$part->id}|{$rarity->value}", (string) config('app.key'));
    }

    /** The part with the affixes of the rarity: what it had stays, and what it lacks is drawn. A part that already has enough is not changed. */
    public function give(Part $part, Rarity $rarity): Part
    {
        $had = (array) ($part->affixes ?? []);
        $has = (new Affixes($this->rules->affixes()))->roll($this->seed($part, $rarity), $part->slot, $rarity, $had);

        if ($has !== $had) {
            $part->forceFill(['affixes' => $has])->save();
        }

        return $part;
    }
}
