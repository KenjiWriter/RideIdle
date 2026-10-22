<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/** How a tournament pool is shared. All amounts are integer cents. */
final readonly class PayoutResult
{
    /**
     * @param  list<array{stage: string, count: int, each_cents: int}>  $groups  from the winner down; `each_cents` is the share of every player in the group
     */
    public function __construct(
        public int $totalCents,
        public int $poolCents,
        public int $rakeCents,
        public array $groups,
        public int $remainderCents,
    ) {
    }

    /** The winner also gets the few cents that rounding left over. */
    public function winnerCents(): int
    {
        return $this->groups[0]['each_cents'] + $this->remainderCents;
    }

    /** Everything paid out, which is always the whole pool. */
    public function distributedCents(): int
    {
        $sum = $this->remainderCents;

        foreach ($this->groups as $group) {
            $sum += $group['count'] * $group['each_cents'];
        }

        return $sum;
    }

    /**
     * The payout of every finishing place, best place first (the winner includes the remainder).
     *
     * @return list<int>
     */
    public function perPlace(): array
    {
        $places = [];

        foreach ($this->groups as $group) {
            for ($i = 0; $i < $group['count']; $i++) {
                $places[] = $group['each_cents'];
            }
        }

        $places[0] += $this->remainderCents;

        return $places;
    }
}
