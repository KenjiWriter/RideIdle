<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The missions of the game (content/rules/missions.json): the slots that the daily, weekly and monthly sets are drawn from, and the chains of main missions.
 * The shape is guaranteed by the schema and by the validator of the pack, so this class only reads. A pack with no such document has no missions.
 */
final class MissionCatalog
{
    /** The sets that come back, shortest first. */
    public const PERIODS = ['daily', 'weekly', 'monthly'];

    /** The goals that are counted over a period and that a template can have. */
    public const PERIODIC_OBJECTIVES = ['laps', 'cash_earned', 'wet_laps', 'fuel_spent', 'upgrade', 'mount', 'service', 'crate', 'parts_bought', 'journey', 'car_bought'];

    /** The goals of a template that are a part of a tank of the best car, not a number of times. */
    public const TANK_OBJECTIVES = ['laps', 'wet_laps', 'cash_earned', 'fuel_spent'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        return new self($source->has('rules/missions') ? $source->document('rules/missions') : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** Whether the pack has anything to draw. */
    public function isEmpty(): bool
    {
        return ($this->data['periodic'] ?? []) === [];
    }

    /**
     * The slots of the set of a period, in the order of the set.
     *
     * @return list<array{key: string, templates: array<string, array<string, mixed>>}>
     */
    public function slots(string $period): array
    {
        return array_values($this->data['periodic'][$period] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function template(string $period, string $key): ?array
    {
        foreach ($this->slots($period) as $slot) {
            if (isset($slot['templates'][$key])) {
                return $slot['templates'][$key];
            }
        }

        return null;
    }

    /**
     * The chains of main missions.
     *
     * @return list<array{key: string, title: array<string, string>, missions: list<array<string, mixed>>}>
     */
    public function chains(): array
    {
        return array_values($this->data['chains'] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function chainMission(string $chain, string $key): ?array
    {
        foreach ($this->chains() as $candidate) {
            if ($candidate['key'] !== $chain) {
                continue;
            }

            foreach ($candidate['missions'] as $mission) {
                if ($mission['key'] === $key) {
                    return $mission;
                }
            }
        }

        return null;
    }
}
