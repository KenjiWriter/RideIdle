<?php

declare(strict_types=1);

namespace App\Content\Tracks;

/** The best build on a track, and what it took to find it. */
final readonly class TrackOutcome
{
    /**
     * @param  string  $surface  asphalt or gravel
     * @param  int  $tierCap  the highest tier of a part that a car of the level of the track can have mounted
     * @param  int  $candidates  how many builds were compared
     * @param  float  $centsPerHour  what the winner earns an hour in the climate of the region, weather by weather
     * @param  array<string, int>  $lapMs  the lap of the winner in every weather of the region
     * @param  array<string, float>  $centsPerHourByCar  what the best build of every car earns an hour: how far the winner is ahead
     * @param  array<string, array{build: Build, lap_ms: int}>  $fastest  the fastest build in the dry and in the rain, when the region has them

     * @param  string  $kind  what the track asks of the driver (`sprint`, `standard`, `endurance`): the gate M3 counts the tracks of a kind among themselves
     */
    public function __construct(
        public string $track,
        public string $surface,
        public int $minPlayerLevel,
        public int $tierCap,
        public int $candidates,
        public Build $winner,
        public float $centsPerHour,
        public array $lapMs,
        public array $centsPerHourByCar,
        public array $fastest,
        public string $kind = 'standard',
    ) {}

    /**
     * The best of the other cars and how far it is behind the winner, as a share of what the winner earns; null when no other car drives here.
     *
     * @return array{car: string, behind: float}|null
     */
    public function runnerUp(): ?array
    {
        $others = array_diff_key($this->centsPerHourByCar, [$this->winner->vehicle => true]);

        if ($others === []) {
            return null;
        }

        arsort($others);

        return ['car' => (string) array_key_first($others), 'behind' => 1 - reset($others) / $this->centsPerHourByCar[$this->winner->vehicle]];
    }
}
