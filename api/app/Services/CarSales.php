<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\User;
use App\Models\WorkshopJob;
use Illuminate\Support\Facades\DB;

/**
 * Selling a car. The driver is paid a share of what the car was worth when they got it (`market.resale_ppm`, 70% as the economy model has),
 * and never less than the scrap of its mass, so that buying a car and selling it again cannot make money. The value is kept on the car
 * (`value_cents`): the price on the exchange, at the dealer or in the yard, or the value of what came out of a storage unit.
 *
 * What the car earned until now is paid first, the parts on it go back to the inventory, and its run is closed for good. A driver keeps at least
 * one car, and a car that is on the road or has a job waiting in the workshop is not sold.
 */
final class CarSales
{
    public const BLOCKED_LAST_CAR = 'last_car';

    public const BLOCKED_BUSY = 'busy';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly Catalog $catalog,
        private readonly Garage $garage,
        private readonly RunService $runs,
    ) {}

    /** What the driver is paid for a car, in cents. */
    public function quoteCents(Car $car): int
    {
        return $this->saleCents($car->vehicle_slug, $car->value_cents ?? $this->rules->wreckPriceCents($car->vehicle_slug) ?? 0);
    }

    /** What a car of this kind is bought for when it is worth this much: the share of the value, and at least the scrap. */
    public function saleCents(string $vehicle, int $valueCents): int
    {
        $mass = (int) ($this->catalog->vehicle($vehicle)['mass_kg'] ?? 0);

        return max($mass * $this->rules->marketScrapCentsPerKg(), (int) round($valueCents * $this->rules->marketResalePpm() / 1_000_000));
    }

    /**
     * Why a car cannot be sold now, or null when it can: `last_car` or `busy`.
     *
     * @param  int  $carsOfDriver  how many cars the driver has
     */
    public function blockedReason(Car $car, int $carsOfDriver): ?string
    {
        if ($carsOfDriver <= 1) {
            return self::BLOCKED_LAST_CAR;
        }

        $run = $this->garage->runOf($car);

        if ($run?->stop_reason === RaceRun::STOP_TRAVEL || WorkshopJob::query()->where('car_id', $car->id)->where('status', '!=', WorkshopJob::STATUS_DONE)->exists()) {
            return self::BLOCKED_BUSY;
        }

        return null;
    }

    /**
     * Sells a car and pays for it, in one transaction under the lock of the wallet. Returns what the driver was paid, in cents.
     *
     * @throws RuleRefusedException `car_not_found`, `last_car` or `car_busy`
     */
    public function sell(int $userId, int $carId): int
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $carId): int {
            $this->ledger->lockWallet($userId);

            $car = Car::query()->where('user_id', $userId)->lockForUpdate()->find($carId) ?? throw new RuleRefusedException('car_not_found');

            $reason = $this->blockedReason($car, Car::query()->where('user_id', $userId)->count());

            if ($reason === self::BLOCKED_LAST_CAR) {
                throw new RuleRefusedException('last_car');
            }

            if ($reason === self::BLOCKED_BUSY) {
                throw new RuleRefusedException('car_busy');
            }

            // What it earned until now is the driver's: it is settled before the car goes.
            $run = $this->garage->runOf($car);

            if ($run !== null) {
                $this->runs->settle($run->id);
                $run = $this->garage->runOf($car);
            }

            // The parts are the driver's too: they go back to the inventory.
            Part::query()->where('car_id', $car->id)->update(['car_id' => null, 'mounted_slot' => null]);

            $run?->forceFill(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_SOLD, 'state_version' => $run->state_version + 1])->save();

            $cents = $this->quoteCents($car);

            $car->forceFill(['sold_at' => now()])->save();
            User::query()->whereKey($userId)->where('selected_car_id', $car->id)->update(['selected_car_id' => null]);

            $this->ledger->post($userId, $cents, 'sale', "sale:{$userId}:{$car->id}");

            return $cents;
        }, Ledger::DEADLOCK_ATTEMPTS);
    }
}
