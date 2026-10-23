<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Settlement\Ppm;

/**
 * The rules of a run (content/rules/starter.json): tracks, regional climate, weather effects and prices.
 * The shape is guaranteed by the schema and by StarterRulesTest, so this class only reads.
 */
final class Rules
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromConfig(): self
    {
        return self::fromFile(config('ride.content_path').'/rules/starter.json');
    }

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function fuelPriceCentsPerLiter(): int
    {
        return $this->data['fuel']['price_cents_per_liter'];
    }

    public function fuelReferencePowerKw(): int
    {
        return $this->data['fuel']['reference_power_kw'];
    }

    public function repairCostCents(): int
    {
        return $this->data['repair']['cost_cents'];
    }

    public function breakChancePerLapPpm(): int
    {
        return $this->data['breakdown']['chance_per_lap_ppm'];
    }

    /** How long a towed car waits before it drives again, in ms. */
    public function towDelayMs(): int
    {
        return $this->data['tow']['delay_ms'];
    }

    /** Laps of fuel a tow leaves in an empty tank. */
    public function towFuelLaps(): int
    {
        return $this->data['tow']['fuel_laps'];
    }

    public function stockTires(): string
    {
        return $this->data['stock_tires'];
    }

    /** The level a car has when the driver gets it. */
    public function carStartLevel(): int
    {
        return $this->data['car']['start_level'];
    }

    /**
     * The value of each statistic at which its bar is full: what the best car of the game is meant to reach.
     *
     * @return array<string, float>
     */
    public function statCeilings(): array
    {
        return array_map('floatval', $this->data['car']['stat_ceiling']);
    }

    /** The highest tier of a part that a car of this level can have mounted: 1 + floor(level / levels per tier). */
    public function maxMountableTier(int $carLevel): int
    {
        return 1 + intdiv($carLevel, $this->data['car']['levels_per_tier']);
    }

    /** The lowest level of a car that can have a part of this tier mounted. */
    public function requiredCarLevel(int $tier): int
    {
        return $this->data['car']['levels_per_tier'] * ($tier - 1);
    }

    /** @return list<string> slugs of the parts the shop sells */
    public function shopParts(): array
    {
        return $this->data['shop']['parts'];
    }

    /**
     * The mechanic of the workshop: the level and how much of the time of every job it cuts, in ppm.
     *
     * @return array{level: int, time_cut_ppm: int}
     */
    public function mechanic(): array
    {
        $workshop = $this->data['workshop'];

        return [
            'level' => $workshop['mechanic_level'],
            'time_cut_ppm' => min(Ppm::ONE - 1, $workshop['time_cut_ppm_per_level'] * $workshop['mechanic_level']),
        ];
    }

    /** How many jobs fit in the queue of the workshop, the one in progress included. */
    public function workshopMaxQueue(): int
    {
        return $this->data['workshop']['max_queue'];
    }

    /**
     * How long a job of the workshop takes, in ms: the time of the slot at tier 1 times the tier, cut by the level of the
     * mechanic, and a share of that for taking a part off. Whole numbers all the way, so the end of a job never depends on a machine.
     */
    public function workshopJobMs(string $slot, int $tier, bool $removal = false): int
    {
        $workshop = $this->data['workshop'];
        $base = $workshop['base_time_ms'][$slot] ?? throw new \InvalidArgumentException("The workshop has no time for the slot {$slot}.");

        $ms = Ppm::mul($base * $tier, Ppm::ONE - $this->mechanic()['time_cut_ppm']);

        return max(1, $removal ? Ppm::mul($ms, $workshop['removal_ppm']) : $ms);
    }

    /** @return array<string, array<string, mixed>> effect of every weather */
    public function weather(): array
    {
        return $this->data['weather'];
    }

    /** @return array<string, mixed>|null */
    public function region(string $slug): ?array
    {
        return $this->data['regions'][$slug] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function track(string $slug): ?array
    {
        return $this->data['tracks'][$slug] ?? null;
    }

    /** The track a new run starts on: the first one of the rules. */
    public function startingTrack(): string
    {
        return (string) array_key_first($this->data['tracks']);
    }

    /** @return array<string, array<string, mixed>> */
    public function tracks(): array
    {
        return $this->data['tracks'];
    }
}
