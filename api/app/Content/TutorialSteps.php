<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The steps of the tutorial (content/rules/tutorial.json), in the order a driver meets them.
 * The shape is guaranteed by the schema and by TutorialStepsTest, so this class only reads.
 */
final class TutorialSteps
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        return new self($source->document('rules/tutorial'));
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

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->data['steps']);
    }

    /**
     * What the server arranges in the first run of a driver, so that the steps come in the first minutes.
     *
     * @return array{first_run_fuel_ml: int, breakdown_lap: int, first_mount_ms: int, covers_up_to_cents: int, exchange_listing?: array{vehicle: string, age_years: int, mileage_km: int, condition_ppm: int, price_cents: int}}
     */
    public function script(): array
    {
        return $this->data['script'];
    }

    public function count(): int
    {
        return count($this->data['steps']);
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        foreach ($this->data['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The facts that a trigger or a completion names, one by one: a single fact is a list of one.
     *
     * @return list<string>
     */
    public static function conditions(mixed $condition): array
    {
        return array_values(array_map(strval(...), (array) $condition));
    }

    /** The place of a step in the tutorial, counting from 1, or null when there is no such step. */
    public function numberOf(string $key): ?int
    {
        foreach (array_values($this->data['steps']) as $index => $step) {
            if ($step['key'] === $key) {
                return $index + 1;
            }
        }

        return null;
    }
}
