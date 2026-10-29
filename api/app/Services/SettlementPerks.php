<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Settlement\BonusWindow;
use App\Domain\Settlement\RefillPolicy;
use App\Models\Car;
use App\Models\RaceRun;
use App\Models\Wallet;

/**
 * What the paid extras of a driver give the settlement of one of their runs, read from the wallet at the moment of the settlement (which holds the lock of it):
 * the policy of the auto-refill and the bonus windows of the VIP account. The frozen configuration of the run holds none of it, so it does not matter when the
 * run was frozen, and a run that exists already is treated as a new one.
 *
 * A feature that the rules do not have or have switched off gives nothing, and what the driver has of it is left alone.
 */
final class SettlementPerks
{
    public function __construct(private readonly Rules $rules, private readonly RunInputsFactory $inputs) {}

    /** @return array{0: RefillPolicy, 1: list<BonusWindow>} */
    public function forRun(RaceRun $run): array
    {
        $wallet = Wallet::query()->find($run->user_id);

        if ($wallet === null) {
            return [RefillPolicy::none(), []];
        }

        return [$this->refillPolicy($run, $wallet), $this->bonusWindows($run, $wallet)];
    }

    private function refillPolicy(RaceRun $run, Wallet $wallet): RefillPolicy
    {
        // A car that stands (it broke down, it has no fuel, it is on the road) is not filled for the time it stood: the settlement does not drive it.
        if ($run->status !== RaceRun::STATUS_RUNNING || ! $this->rules->autoRefillOn() || ! $wallet->auto_refill_enabled) {
            return RefillPolicy::none();
        }

        $freeUntilMs = $this->rules->infinityOn() ? ($wallet->infinity_until?->getTimestampMs() ?? 0) : 0;
        $charges = (int) $wallet->auto_refills;

        if ($charges <= 0 && $freeUntilMs <= $run->cursor_ms) {
            return RefillPolicy::none();
        }

        $car = $run->car_id === null ? null : Car::query()->find($run->car_id);

        return new RefillPolicy($this->inputs->tankMl($run->car_slug, $car?->mountedSlugs() ?? []), $charges, $freeUntilMs);
    }

    /** @return list<BonusWindow> */
    private function bonusWindows(RaceRun $run, Wallet $wallet): array
    {
        $vip = $this->rules->vip();
        $untilMs = $wallet->vip_until?->getTimestampMs();

        if ($vip === null || ! $vip['enabled'] || $untilMs === null || $untilMs <= $run->cursor_ms || ($vip['cash_bonus_ppm'] === 0 && $vip['xp_bonus_ppm'] === 0)) {
            return [];
        }

        return [new BonusWindow(0, $untilMs, $vip['cash_bonus_ppm'], $vip['xp_bonus_ppm'])];
    }
}
