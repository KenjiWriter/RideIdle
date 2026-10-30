<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInAGame;
use App\Content\MissionCatalog;
use App\Content\Rules;
use App\Models\RaceRun;
use App\Services\MissionDraw;
use App\Services\MissionScale;
use Illuminate\Console\Command;

/**
 * What the missions of the game ask and pay, for a car on a track: the goals of every template, what each set pays in all (cash and XP as a part of a tank, the
 * items, the bonus in coins), what a month of doing everything comes to, and the coins of a month next to the prices of the game. The tool for tuning the numbers of
 * the rules, and for seeing what a change does before it is published.
 */
final class MissionsBudget extends Command
{
    use RunsInAGame;

    protected $signature = 'missions:budget
        {--car=rdzak-gt-4 : The slug of the car whose tank is the measure}
        {--track=zlomowo-street : The slug of the track it drives}
        {--level=1 : The level of the driver, which decides what templates fit}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Show what the missions ask and pay, for a car on a track';

    public function handle(MissionCatalog $catalog, Rules $rules, MissionScale $scale, MissionDraw $draw): int
    {
        $settings = $rules->missions();

        if ($settings === null) {
            $this->error('The rules of this game have no missions.');

            return self::FAILURE;
        }

        $run = new RaceRun(['car_slug' => (string) $this->option('car'), 'track_slug' => (string) $this->option('track')]);
        $tank = $scale->tankOf($run);

        if ($tank === null) {
            $this->error('That car cannot drive that track on its tank.');

            return self::FAILURE;
        }

        $level = (int) $this->option('level');
        $dollars = static fn (int|float $cents): string => '$'.number_format($cents / 100, 2);

        $this->info(sprintf('A tank of the %s on %s: %d laps, %s gross, %s after the fuel, %d XP; filling it costs %s. The missions are %s.', $this->option('car'), $this->option('track'), $tank['laps'], $dollars($tank['gross_cents']), $dollars($tank['net_cents']), $tank['xp'], $dollars($tank['fuel_cents']), $settings['enabled'] ? 'on' : 'OFF'));

        $cashes = [];
        $xps = [];
        $coins = [];

        foreach (MissionCatalog::PERIODS as $period) {
            $budget = $settings['periods'][$period];
            $cash = (int) round(max(0, $tank['net_cents']) * $budget['cash_ppm'] / 1_000_000);
            $xp = (int) round($tank['xp'] * $budget['xp_ppm'] / 1_000_000);
            $rows = [];

            foreach ($catalog->slots($period) as $slot) {
                foreach ($slot['templates'] as $key => $template) {
                    $fits = $level >= ($template['min_player_level'] ?? 1) && $level <= ($template['max_player_level'] ?? PHP_INT_MAX);
                    $items = implode(', ', array_map(static fn (array $item): string => $item['type'] === 'crate' ? "crate {$item['kind']}" : "part {$item['rarity']}", $template['items'] ?? []));
                    $rows[] = [$slot['key'], $key, $template['objective'], $fits ? $this->goal($template, $draw->target($template, $tank)) : '(does not fit)', $template['weight'], $template['reward_share'], $items];
                }
            }

            $this->newLine();
            $this->line(sprintf('%s: %d missions, the set pays %s and %d XP, and %d coins for the whole set.', strtoupper($period), count($catalog->slots($period)), $dollars($cash), $xp, $budget['bonus_coins']));
            $this->table(['slot', 'template', 'goal', 'asks', 'weight', 'share', 'items'], $rows);

            $cashes[$period] = $cash;
            $xps[$period] = $xp;
            $coins[$period] = $budget['bonus_coins'];
        }

        // A month of 30 days of doing everything: 30 days, 30 / 7 weeks and one month.
        $weeks = 30 / 7;
        $monthCash = 30 * $cashes['daily'] + $weeks * $cashes['weekly'] + $cashes['monthly'];
        $monthXp = 30 * $xps['daily'] + $weeks * $xps['weekly'] + $xps['monthly'];
        $monthCoins = 30 * $coins['daily'] + $weeks * $coins['weekly'] + $coins['monthly'];
        $day = max(1, $tank['net_cents']);

        $this->newLine();
        $this->line(sprintf('A month of doing everything: %s (%d%% of the %s that 30 tanks earn), %d XP (%d%% of the %d of 30 tanks), %d coins.', $dollars($monthCash), (int) round(100 * $monthCash / (30 * $day)), $dollars(30 * $day), $monthXp, (int) round(100 * $monthXp / max(1, 30 * $tank['xp'])), 30 * $tank['xp'], (int) round($monthCoins)));

        $vip = $rules->vip();
        $products = $rules->premiumProducts();
        $smallest = $products === [] ? null : $products[array_key_first($products)];

        if ($vip !== null) {
            $this->line(sprintf('The VIP account costs %d coins: a month of doing everything is %d%% of it.', $vip['coins'], (int) round(100 * $monthCoins / max(1, $vip['coins']))));
        }

        if ($smallest !== null) {
            $this->line(sprintf('At the price of the smallest pack (%d coins for %.2f %s) a month of doing everything is worth %.2f %s; speeding up at %d minutes a coin, it is %.1f hours of timers.', $smallest['coins'], $smallest['price_minor'] / 100, $rules->premiumPriceCurrency(), $monthCoins * $smallest['price_minor'] / $smallest['coins'] / 100, $rules->premiumPriceCurrency(), $rules->speedupMinutesPerCoin() ?? 5, $monthCoins * ($rules->speedupMinutesPerCoin() ?? 5) / 60));
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $template */
    private function goal(array $template, int $target): string
    {
        return match ($template['objective']) {
            'cash_earned', 'fuel_spent' => '$'.number_format($target / 100, 0),
            default => (string) $target,
        };
    }
}
