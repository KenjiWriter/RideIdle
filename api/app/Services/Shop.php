<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Run\Rarity;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\Part;
use Illuminate\Support\Facades\DB;

/**
 * The shop of parts of the city. It sells what the rules list (`shop.parts`), without rarity, for the price of the
 * definition, and puts the part in the inventory of the driver. Putting it on a car is a job of the workshop.
 *
 * A purchase is one transaction under the lock of the wallet: the money and the part go together or not at all.
 */
final class Shop
{
    /** The key of the posting is shop:{driver}:{request}: 5 + 20 + 1 + the request id, within the 128 of the ledger. */
    public const MAX_REQUEST_ID_LENGTH = 64;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly Ledger $ledger,
        private readonly PartRequirements $requirements,
        private readonly Garage $garage,
    ) {}

    /**
     * What is for sale, with what the driver's car says about it: the level the car needs to have the part mounted and
     * whether it has it. Without a car nothing can be mounted.
     *
     * A part that the car cannot take for another reason than its level (a turbo for six cylinders on an engine of four, an intercooler with no booster) says
     * what it lacks in `blocked_by` (see `PartRequirements::lack`), and is not `mountable`.
     *
     * The shop shows the parts up to one tier above what the car can have mounted (a car without a part shows as a new one), so the next tier is something to aim
     * for and nothing is out of sight: the engine group comes into view as the car rises.
     *
     * @return list<array<string, mixed>> definitions of the parts, in the order of the rules, with `required_car_level`, `mountable` and `blocked_by`
     */
    public function offers(?Car $car): array
    {
        $boosterOn = $car !== null && $this->requirements->boosterOn($car);
        $slugs = array_values(array_filter($this->rules->shopParts(), fn (string $slug): bool => $this->definition($slug)['tier'] <= $this->highestTierShown($car)));

        return array_map(function (string $slug) use ($car, $boosterOn): array {
            $part = $this->definition($slug);
            $required = $this->rules->requiredCarLevel($part['tier']);
            $lack = $car === null ? null : $this->requirements->lack($part, $car, $boosterOn);

            return [
                ...$part,
                'required_car_level' => $required,
                'mountable' => $car !== null && $car->level >= $required && $lack === null,
                'blocked_by' => $lack,
            ];
        }, $slugs);
    }

    /** The highest tier the shop shows to a car: one above what it can have mounted. */
    private function highestTierShown(?Car $car): int
    {
        return $this->rules->maxMountableTier($car?->level ?? $this->rules->carStartLevel()) + 1;
    }

    /**
     * Buys a part. `$requestId` is chosen by the client for one intention to buy: sending it again, for example after an
     * answer was lost, charges and gives once.
     *
     * @throws RuleRefusedException when the part is not for sale
     * @throws InsufficientFundsException when the driver cannot pay (nothing is changed)
     * @throws IdempotencyConflictException when the request id was used for another part
     */
    public function buy(int $userId, string $slug, string $requestId): Purchased
    {
        if (! in_array($slug, $this->rules->shopParts(), true) || $this->definition($slug)['tier'] > $this->highestTierShown($this->garage->carOf($userId))) {
            throw new RuleRefusedException('part_not_for_sale');
        }

        $part = $this->definition($slug);
        $price = $part['price_cents'];
        $key = "shop:{$userId}:{$requestId}";

        // Before the transaction: creating the wallet under a lock deadlocks (see Ledger::ensureWallet).
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $part, $price, $key): Purchased {
            $this->ledger->lockWallet($userId);

            $earlier = LedgerEntry::query()->where('idem_key', $key)->first();

            if ($earlier !== null) {
                if ($earlier->delta !== -$price) {
                    throw new IdempotencyConflictException($key);
                }

                return new Purchased(null, 0, true);
            }

            $this->ledger->post($userId, -$price, 'purchase', $key);

            $owned = Part::query()->create([
                'user_id' => $userId,
                'part_slug' => $part['slug'],
                'slot' => $part['slot'],
                'rarity' => Rarity::Common->value,
            ]);

            return new Purchased($owned, $price, false);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /** @return array<string, mixed> */
    private function definition(string $slug): array
    {
        return $this->catalog->part($slug) ?? throw new \InvalidArgumentException("The shop sells {$slug}, which is not in the pack.");
    }
}
