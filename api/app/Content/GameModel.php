<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The model of the game (rules/model): the slots that parts go into and the statistics of a car that the screens show. A slot has a key, which the parts, the rules
 * and the cars of the players use, and may have a role: the roles are what the formulas know (see `model.schema.json`). The code asks for a role and gets the
 * slot that has it, so a slot is the tires of the game whatever its key is. Without a document the model is the one of the car game, which is also what
 * `content/rules/model.json` says.
 */
final class GameModel
{
    /** What the formulas know of a slot. */
    public const ROLES = ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'cooling', 'tank'];

    /** The statistics that the formulas make, which a pack may list in any order or leave out of the screens. */
    public const STATS = ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance'];

    /** The slots and the statistics of the car game. */
    private const DEFAULT = [
        'slots' => [
            ['key' => 'tires', 'role' => 'tires'],
            ['key' => 'suspension', 'role' => 'suspension'],
            ['key' => 'brakes', 'role' => 'brakes'],
            ['key' => 'engine', 'role' => 'engine'],
            ['key' => 'boost', 'role' => 'boost'],
            ['key' => 'gearbox', 'role' => 'gearbox'],
            ['key' => 'exhaust'],
            ['key' => 'cooling', 'role' => 'cooling'],
            ['key' => 'tank', 'role' => 'tank'],
            ['key' => 'ecu', 'hidden' => true],
        ],
        'stats' => self::STATS,
    ];

    /**
     * @param  list<array{key: string, role: string|null, hidden: bool}>  $slots
     * @param  list<string>  $stats
     */
    private function __construct(private readonly array $slots, private readonly array $stats) {}

    /** The model of the car game. */
    public static function default(): self
    {
        return self::fromArray(self::DEFAULT);
    }

    /**
     * The model of a document of the rules. A slot that names a role that another slot has already is left to the validator: the first one has it here.
     *
     * @param  array<string, mixed>  $document
     */
    public static function fromArray(array $document): self
    {
        $slots = [];

        foreach ($document['slots'] ?? [] as $slot) {
            $slots[] = ['key' => (string) $slot['key'], 'role' => $slot['role'] ?? null, 'hidden' => (bool) ($slot['hidden'] ?? false)];
        }

        return new self($slots, array_values(array_map(strval(...), $document['stats'] ?? self::STATS)));
    }

    /** The model of the content: its document, or the model of the car game when it has none. */
    public static function fromSource(ContentSource $source): self
    {
        return $source->has('rules/model') ? self::fromArray($source->document('rules/model')) : self::default();
    }

    /** @return list<array{key: string, role: string|null, hidden: bool}> every slot, in order */
    public function slots(): array
    {
        return $this->slots;
    }

    /** @return list<string> the keys of every slot, in order */
    public function slotKeys(): array
    {
        return array_column($this->slots, 'key');
    }

    /** @return list<string> the keys of the slots that the garage lists, in order */
    public function visibleSlotKeys(): array
    {
        return array_column(array_filter($this->slots, static fn (array $slot): bool => ! $slot['hidden']), 'key');
    }

    public function has(string $slot): bool
    {
        return in_array($slot, $this->slotKeys(), true);
    }

    /** The key of the slot that has the role, or null when the model has none (a game without a booster, say). */
    public function slotOf(string $role): ?string
    {
        foreach ($this->slots as $slot) {
            if ($slot['role'] === $role) {
                return $slot['key'];
            }
        }

        return null;
    }

    /** The role of a slot, or null when it has none (or is not in the model). */
    public function roleOf(string $slot): ?string
    {
        foreach ($this->slots as $candidate) {
            if ($candidate['key'] === $slot) {
                return $candidate['role'];
            }
        }

        return null;
    }

    /** Whether the slot has the role: false for a slot that is not in the model. */
    public function is(string $slot, string $role): bool
    {
        return $this->roleOf($slot) === $role;
    }

    /** @return list<string> the statistics that the screens show, in order */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * The model as the clients get it.
     *
     * @return array{slots: list<array{key: string, role: string|null, hidden: bool}>, stats: list<string>}
     */
    public function toArray(): array
    {
        return ['slots' => $this->slots, 'stats' => $this->stats];
    }
}
