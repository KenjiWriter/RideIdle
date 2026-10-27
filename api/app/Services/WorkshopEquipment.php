<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Domain\Workshop\WorkshopGear;
use App\Models\CityWorkshop;

/**
 * What the workshops of a driver have bought (Systemy III, point 9): the equipment, the bays and the mechanics, per city. A city the driver has bought
 * nothing in has the start state, and its row is made by the first purchase. The purchase itself is `Workshop::buyGear`, which holds the locks and
 * re-plans the queue; this class reads, and writes the level once the price is paid.
 */
final class WorkshopEquipment
{
    public function __construct(private readonly Rules $rules) {}

    /** What the workshop of the driver in a city has: the start state for a city they bought nothing in. */
    public function gearOf(int $userId, string $city): WorkshopGear
    {
        return CityWorkshop::query()->where('user_id', $userId)->where('city', $city)->first()?->gear() ?? WorkshopGear::start();
    }

    /**
     * Every item of a workshop of the driver's, the level it is at and what the next one is and costs, as the client shows them.
     *
     * @return array{workers: int, queue_max: int, items: list<array<string, mixed>>, bays: array<string, mixed>, mechanics: array<string, mixed>}
     */
    public function summary(WorkshopGear $gear): array
    {
        $equipment = $this->rules->workshopEquipment();
        $items = [];

        foreach (WorkshopGear::ITEMS as $item) {
            $definition = $equipment[$item];
            $level = $gear->level($item);
            $next = $definition['levels'][$level + 1] ?? null;

            $items[] = [
                'key' => $item,
                // The slots it works on; the toolkit has none because it works on every job.
                'slots' => $definition['slots'] ?? null,
                'level' => $level,
                'max_level' => $this->rules->gearMax($item),
                'name' => $definition['levels'][$level]['key'],
                'time_ppm' => $definition['levels'][$level]['time_ppm'],
                // What a job on a part of its slots costs on top while the workshop is at this level (the vulcanizer outside).
                'fee_cents' => $definition['levels'][$level]['fee_cents'] ?? 0,
                'next' => $next === null ? null : ['name' => $next['key'], 'time_ppm' => $next['time_ppm'], 'price_cents' => $next['price_cents']],
            ];
        }

        return [
            'workers' => $gear->workers(),
            'queue_max' => $this->rules->workshopMaxQueue($gear->workers()),
            'items' => $items,
            'bays' => $this->countSummary($gear, WorkshopGear::BAYS),
            'mechanics' => $this->countSummary($gear, WorkshopGear::MECHANICS),
        ];
    }

    /**
     * Writes one more level of an item (or one more bay or mechanic) to the workshop of the driver in a city, and answers what it has now. The caller holds
     * the lock of the wallet and has paid.
     */
    public function raise(int $userId, string $city, string $item): WorkshopGear
    {
        $row = CityWorkshop::query()->where('user_id', $userId)->where('city', $city)->lockForUpdate()->first()
            ?? CityWorkshop::query()->create(['user_id' => $userId, 'city' => $city])->refresh();

        $row->forceFill([$item => $row->gear()->level($item) + 1])->save();

        return $row->gear();
    }

    /** @return array{count: int, max: int, next_price_cents: int|null} */
    private function countSummary(WorkshopGear $gear, string $item): array
    {
        return [
            'count' => $gear->level($item),
            'max' => $this->rules->gearMax($item),
            'next_price_cents' => $this->rules->gearNextPriceCents($item, $gear->level($item)),
        ];
    }
}
