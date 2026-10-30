<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\MissionCatalog;
use App\Content\Rules;
use App\Games\CurrentGame;

/**
 * Draws the set of a period for a driver: one mission for every slot, from the templates that fit the level of the driver, by weight. The seed is the game, the
 * driver, the period and the slot (with the secret of the application, as the draw of a crate has), so the same driver gets the same set whenever it is asked for,
 * and nobody can know what the next day brings. The goals are worked out from a tank of the best car of the driver, and the reward of the set is divided among its
 * missions by their shares, so a set pays the same whichever missions it draws (the numbers of the rules): the draw decides what is asked, not how much is paid.
 */
final class MissionDraw
{
    public function __construct(
        private readonly MissionCatalog $catalog,
        private readonly Rules $rules,
        private readonly CurrentGame $game,
    ) {}

    /**
     * @param  array{laps: int, lap_cash_cents: int, gross_cents: int, net_cents: int, xp: int, fuel_cents: int}  $tankful
     * @return list<array{slot: int, key: string, objective: string, target: int, reward: array{cash_cents: int, xp: int, car_xp: int, items: list<array<string, string>>}}>
     */
    public function set(int $userId, string $period, string $periodKey, int $level, array $tankful): array
    {
        $chosen = [];

        foreach ($this->catalog->slots($period) as $index => $slot) {
            $fitting = array_filter(
                $slot['templates'],
                static fn (array $template): bool => $level >= ($template['min_player_level'] ?? 1) && $level <= ($template['max_player_level'] ?? PHP_INT_MAX),
            );

            if ($fitting === []) {
                continue;
            }

            $seed = hash_hmac('sha256', "mission|{$this->game->id()}|{$userId}|{$period}|{$periodKey}|{$slot['key']}", (string) config('app.key'));
            $key = $this->pick($seed, array_map(static fn (array $template): int => (int) $template['weight'], $fitting));
            $chosen[] = ['slot' => $index + 1, 'key' => $key, 'template' => $fitting[$key]];
        }

        $budget = $this->rules->missions()['periods'][$period];
        $shares = array_sum(array_map(static fn (array $mission): int => (int) $mission['template']['reward_share'], $chosen)) ?: 1;
        $cash = max(0, $tankful['net_cents']) * $budget['cash_ppm'] / 1_000_000;
        $xp = $tankful['xp'] * $budget['xp_ppm'] / 1_000_000;
        $set = [];

        foreach ($chosen as $mission) {
            $template = $mission['template'];
            $share = (int) $template['reward_share'] / $shares;

            $set[] = [
                'slot' => $mission['slot'],
                'key' => $mission['key'],
                'objective' => $template['objective'],
                'target' => $this->target($template, $tankful),
                'reward' => [
                    'cash_cents' => $budget['cash_ppm'] > 0 ? $this->dollars((int) round($cash * $share)) : 0,
                    'xp' => $budget['xp_ppm'] > 0 ? max(1, (int) round($xp * $share)) : 0,
                    'car_xp' => isset($template['car_xp_laps']) ? (int) $template['car_xp_laps'] * $this->rules->carXpPerLap() : 0,
                    'items' => $template['items'] ?? [],
                ],
            ];
        }

        return $set;
    }

    /**
     * What a template asks of a driver whose tank is this: laps and the cents of cash or fuel as a part of a tank, the rest a number of times.
     *
     * @param  array<string, mixed>  $template
     * @param  array{laps: int, lap_cash_cents: int, gross_cents: int, net_cents: int, xp: int, fuel_cents: int}  $tankful
     */
    public function target(array $template, array $tankful): int
    {
        $ppm = (int) ($template['tanks_ppm'] ?? 0);

        return match ($template['objective']) {
            'laps', 'wet_laps' => max(1, (int) round($tankful['laps'] * $ppm / 1_000_000)),
            'cash_earned' => $this->dollars((int) round($tankful['gross_cents'] * $ppm / 1_000_000)),
            'fuel_spent' => $this->dollars((int) round($tankful['fuel_cents'] * $ppm / 1_000_000)),
            default => (int) ($template['count'] ?? 1),
        };
    }

    /** Whole dollars, and at least one. */
    private function dollars(int $cents): int
    {
        return max(100, intdiv($cents + 50, 100) * 100);
    }

    /**
     * The key of a weighted choice: the same seed gives the same key.
     *
     * @param  array<string, int>  $weights
     */
    private function pick(string $seed, array $weights): string
    {
        $roll = hexdec(substr($seed, 0, 12)) % max(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if ($roll < $weight) {
                return (string) $key;
            }

            $roll -= $weight;
        }

        return (string) array_key_last($weights);
    }
}
