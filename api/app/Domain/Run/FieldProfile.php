<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;

/**
 * The rivals of a car on a track and what the place among them pays: the field of the race. The bots drive at fixed lap times, so the place of a
 * car is a fact of its own lap time, and the reward of a lap is multiplied by what that place pays.
 */
final readonly class FieldProfile
{
    /**
     * @param  list<int>  $rivalsMs  the dry lap time of every bot, in ms, from the quickest to the slowest
     * @param  list<string>  $names  the name of every bot, in the same order
     * @param  list<int>  $rewardPpm  what the reward of a lap is multiplied by for the first place, the second and so on: one more than there are bots
     */
    public function __construct(public array $rivalsMs, public array $names, public array $rewardPpm)
    {
        if ($rivalsMs === [] || count($names) !== count($rivalsMs) || count($rewardPpm) !== count($rivalsMs) + 1) {
            throw new InvalidArgumentException('A field has as many names as bots, and a reward for every place, which is one more than there are bots.');
        }

        foreach ($rivalsMs as $ms) {
            if ($ms < 1) {
                throw new InvalidArgumentException('The lap time of a bot must be positive.');
            }
        }

        foreach ($rewardPpm as $ppm) {
            if ($ppm < 1) {
                throw new InvalidArgumentException('What a place pays must be positive.');
            }
        }
    }

    /**
     * Where a car with this dry lap time finishes among the bots, and what that pays. A bot that drives the same time is ahead, so the place is
     * never better than the lap time earns.
     *
     * @return array{position: int, size: int, reward_ppm: int, rows: list<array{name: string|null, type: string, lap_ms: int}>}
     */
    public function place(int $lapMs): array
    {
        $position = 1 + count(array_filter($this->rivalsMs, static fn (int $ms): bool => $ms <= $lapMs));
        $rows = [['name' => null, 'type' => 'you', 'lap_ms' => $lapMs]];

        foreach ($this->rivalsMs as $index => $ms) {
            $rows[] = ['name' => $this->names[$index], 'type' => 'bot', 'lap_ms' => $ms];
        }

        // The quicker first; for the same time a bot is ahead of the car, and the bots keep the order they were given in (usort is stable).
        usort($rows, static fn (array $a, array $b): int => [$a['lap_ms'], $a['type'] === 'you' ? 1 : 0] <=> [$b['lap_ms'], $b['type'] === 'you' ? 1 : 0]);

        return ['position' => $position, 'size' => count($this->rivalsMs) + 1, 'reward_ppm' => $this->rewardPpm[$position - 1], 'rows' => $rows];
    }
}
