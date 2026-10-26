<?php

declare(strict_types=1);

namespace App\Domain\Run;

/**
 * How rare a part is (Systemy I): it multiplies what the part adds to a car. A part has its rarity from the moment it is made, and it is the
 * same in the inventory and on a car. The stored value is the number of the case.
 */
enum Rarity: int
{
    case Common = 1;
    case Uncommon = 2;
    case Rare = 3;
    case Epic = 4;
    case Legendary = 5;

    /** The name that the rules and the API use: `common`, `uncommon`, `rare`, `epic`, `legendary`. */
    public function key(): string
    {
        return strtolower($this->name);
    }

    public static function fromKey(string $key): self
    {
        foreach (self::cases() as $case) {
            if ($case->key() === $key) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("There is no rarity {$key}.");
    }
}
