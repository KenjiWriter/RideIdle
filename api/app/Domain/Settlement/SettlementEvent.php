<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/** One entry of the settlement log. It feeds the summary screen, notifications and telemetry. */
final readonly class SettlementEvent
{
    /** @param array<string, int|string> $payload */
    private function __construct(
        public EventType $type,
        public int $atMs,
        public array $payload = [],
    ) {}

    /**
     * Laps finished within one weather segment, aggregated. `$cashCents` and `$xp` are what the batch paid, the bonus of the VIP account included; when there
     * was a bonus the payload says how much of it was (`bonus_cash_cents`, `bonus_xp`), and when there was none it has no such keys.
     */
    public static function lapBatch(int $segment, string $weather, int $laps, int $cashCents, int $xp, int $fromMs, int $toMs, int $bonusCashCents = 0, int $bonusXp = 0): self
    {
        return new self(EventType::LapBatch, $toMs, [
            'segment' => $segment,
            'weather' => $weather,
            'laps' => $laps,
            'cash_cents' => $cashCents,
            'xp' => $xp,
            'from_ms' => $fromMs,
            'to_ms' => $toMs,
            ...($bonusCashCents > 0 || $bonusXp > 0 ? ['bonus_cash_cents' => $bonusCashCents, 'bonus_xp' => $bonusXp] : []),
        ]);
    }

    public static function breakdown(int $atMs): self
    {
        return new self(EventType::Breakdown, $atMs);
    }

    public static function fuelEmpty(int $atMs): self
    {
        return new self(EventType::FuelEmpty, $atMs);
    }

    /** The tank was filled for the driver at `$atMs`, with `$addedMl` of fuel; `$free` when the pass paid for it and no charge was used. */
    public static function autoRefill(int $atMs, int $addedMl, bool $free): self
    {
        return new self(EventType::AutoRefill, $atMs, ['added_ml' => $addedMl, 'free' => $free ? 1 : 0]);
    }

    /** The settlement stopped where the frozen configuration has to be made again. */
    public static function recompute(int $atMs): self
    {
        return new self(EventType::Recompute, $atMs);
    }

    /** The car is due for its service. */
    public static function serviceDue(int $atMs, int $mileageKm): self
    {
        return new self(EventType::ServiceDue, $atMs, ['mileage_km' => $mileageKm]);
    }

    /**
     * The same event with more in its payload: what only the caller knows (the part that broke down).
     *
     * @param  array<string, int|string>  $payload
     */
    public function withPayload(array $payload): self
    {
        return new self($this->type, $this->atMs, [...$this->payload, ...$payload]);
    }

    public function priority(): int
    {
        return $this->type->priority();
    }

    /** @return array{type: string, at_ms: int, payload: array<string, int|string>} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'at_ms' => $this->atMs, 'payload' => $this->payload];
    }
}
