<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Domain\Run\Rarity;
use App\Models\Part;

/**
 * What the game gives a driver for something done: a part of the pack as a gift, experience for the car they play, and, for a mission, cash, experience, crates and
 * parts together. The tutorial pays its steps through the first two and the missions through the last, so there is one way of giving a part (rarity, affixes) and
 * one of giving the car its experience.
 *
 * The callers hold the lock of the wallet and the transaction, and each makes sure that what it pays is paid once (the tutorial by the state of the step, the
 * missions by the state of the mission and the key of the ledger); nothing here is idempotent by itself.
 */
final class RewardPayer
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Garage $garage,
        private readonly CarProgress $progress,
        private readonly Catalog $catalog,
        private readonly PartAffixes $affixes,
        private readonly CrateShop $crates,
    ) {}

    /** Parts of the pack in the inventory of the driver, common unless said otherwise: the slug, or null for a part that the pack does not have. */
    public function givePart(int $userId, string $slug, Rarity $rarity = Rarity::Common, int $count = 1): ?string
    {
        $definition = $this->catalog->part($slug);

        if ($definition === null) {
            return null;
        }

        for ($given = 0; $given < $count; $given++) {
            $part = Part::query()->create(['user_id' => $userId, 'part_slug' => $slug, 'slot' => $definition['slot'], 'rarity' => $rarity->value]);

            $this->affixes->give($part, $rarity);
        }

        return $slug;
    }

    /** The experience goes to the car the driver has: with no car there is nothing to give it to. Returns how much the car got. */
    public function awardCarXp(int $userId, int $xp): int
    {
        if ($xp <= 0) {
            return 0;
        }

        $car = $this->garage->carOf($userId);

        return $car === null ? 0 : ($this->progress->award($car->id, $xp) === null ? 0 : $xp);
    }

    /**
     * Pays a mission: the cash (under `$cashKey` in the ledger), the experience of the driver and of the car, and the items, in this order, as the experience
     * of the driver can open a part or a crate that the level had not.
     *
     * @param  array{cash_cents?: int, xp?: int, car_xp?: int, items?: list<array{type: string, kind?: string, rarity?: string}>}  $reward
     * @return array{cash_cents: int, xp: int, car_xp: int, parts: list<int>, crates: list<string>}
     */
    public function payMission(int $userId, array $reward, string $cashKey, string $seedKey): array
    {
        $cents = (int) ($reward['cash_cents'] ?? 0);
        $xp = (int) ($reward['xp'] ?? 0);

        if ($cents > 0) {
            $this->ledger->post($userId, $cents, 'mission', $cashKey);
        }

        $this->ledger->grantXp($userId, $xp);
        $carXp = $this->awardCarXp($userId, (int) ($reward['car_xp'] ?? 0));
        $parts = [];
        $crates = [];

        foreach ($reward['items'] ?? [] as $position => $item) {
            if ($item['type'] === 'crate') {
                $opened = $this->crates->grantFree($userId, $item['kind'] ?? 'slot');

                if ($opened !== null) {
                    $parts[] = $opened['part']->id;
                    $crates[] = $opened['kind'];
                }

                continue;
            }

            $part = $this->crates->grantPart($userId, Rarity::fromKey($item['rarity'] ?? 'rare'), "{$seedKey}:{$position}");

            if ($part !== null) {
                $parts[] = $part->id;
            }
        }

        return ['cash_cents' => $cents, 'xp' => $xp, 'car_xp' => $carXp, 'parts' => $parts, 'crates' => $crates];
    }
}
