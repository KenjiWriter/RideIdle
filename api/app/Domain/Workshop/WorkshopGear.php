<?php

declare(strict_types=1);

namespace App\Domain\Workshop;

/**
 * What a workshop of a city has (Systemy III, point 9): the levels of its four items of equipment, and how many bays and mechanics it has. Level 0 of an item
 * is the start state, with which every job lasts what the rules say, so a workshop that bought nothing is `WorkshopGear::start()`.
 */
final readonly class WorkshopGear
{
    public const TIRE_MACHINE = 'tire_machine';

    public const LIFT = 'lift';

    public const HOIST = 'hoist';

    public const TOOLKIT = 'toolkit';

    /** The items of equipment, in the order the workshop shows them. */
    public const ITEMS = [self::TIRE_MACHINE, self::LIFT, self::HOIST, self::TOOLKIT];

    public const BAYS = 'bays';

    public const MECHANICS = 'mechanics';

    /** @param  int  $bays  how many cars can be worked on at once (the first is there from the start) */
    public function __construct(
        public int $tireMachine = 0,
        public int $lift = 0,
        public int $hoist = 0,
        public int $toolkit = 0,
        public int $bays = 1,
        public int $mechanics = 1,
    ) {}

    /** The workshop that has bought nothing: one bay, one mechanic and no equipment. */
    public static function start(): self
    {
        return new self;
    }

    /** The level of an item of equipment (`ITEMS`), or the number of `BAYS` or of `MECHANICS`. */
    public function level(string $item): int
    {
        return match ($item) {
            self::TIRE_MACHINE => $this->tireMachine,
            self::LIFT => $this->lift,
            self::HOIST => $this->hoist,
            self::TOOLKIT => $this->toolkit,
            self::BAYS => $this->bays,
            self::MECHANICS => $this->mechanics,
            default => throw new \InvalidArgumentException("The workshop has no {$item}."),
        };
    }

    /** The same workshop with one more of an item, or of the bays or the mechanics. */
    public function with(string $item, int $level): self
    {
        $levels = [
            self::TIRE_MACHINE => $this->tireMachine,
            self::LIFT => $this->lift,
            self::HOIST => $this->hoist,
            self::TOOLKIT => $this->toolkit,
            self::BAYS => $this->bays,
            self::MECHANICS => $this->mechanics,
        ];

        if (! isset($levels[$item])) {
            throw new \InvalidArgumentException("The workshop has no {$item}.");
        }

        $levels[$item] = $level;

        return new self($levels[self::TIRE_MACHINE], $levels[self::LIFT], $levels[self::HOIST], $levels[self::TOOLKIT], $levels[self::BAYS], $levels[self::MECHANICS]);
    }

    /** How many mechanics work at once: one needs a bay of his own. */
    public function workers(): int
    {
        return max(1, min($this->bays, $this->mechanics));
    }
}
