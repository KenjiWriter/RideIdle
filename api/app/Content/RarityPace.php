<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Market\Crates;
use App\Domain\Run\RunFreezer;
use JsonException;

/**
 * What the rarity of the parts is worth in income an hour, from the real pipeline: the table of the pace of the rarities
 * (`content/tables/rarity-pace.json`) that the crates of the economy simulator read (`GameCrates`, `CrateEconomy::gainAfter`).
 *
 * The car is the stock Rdzak GT-4 on the first track, dry, with the parts that give grip, handling, braking and power of the shop (street tires
 * T2, sport suspension T1, disc brakes T2, sport exhaust T2). A player opens standard crates in rounds (tires, tires, suspension, brakes), draws
 * the rarity of every one with the odds and the guarantees of the rules (`Crates::draw`, the same seed mixing as the game, only from a seed
 * that is a string of this table), and mounts the best part of each slot. The gain after n crates is the mean, over many drawn players, of the
 * income an hour of that car against the same car with common parts (0.2 is a fifth more): the laps an hour times what a lap pays, and a lap pays by
 * the place of the car among the bots, so a part that shortens the lap also lifts the car in the field (`gain_by_crates`). The gain of the laps alone is in
 * the table as well (`lap_gain_by_crates`). Nothing is random: a draw is a hash of the seed, so the table is the same on every machine, and a test pins
 * that the committed one is what this class computes.
 */
final class RarityPace
{
    public const TRIALS = 1000;

    public const SEED_PREFIX = 'rarity-pace';

    /** How many crates the table goes up to: by then the best parts of every slot are the best there are. */
    public const CRATES = 300;

    private const CAR = 'rdzak-gt-4';

    private const TRACK = 'zlomowo-street';

    /** @var array<string, string> the parts that the car has, by slot */
    private const PARTS = ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2'];

    /** The slots of the crates in turn: the tires give most (grip, handling and braking at once), so they have two of four. */
    private const CYCLE = ['tires', 'tires', 'suspension', 'brakes'];

    /** @var array<string, array{ms: int, cash: int, position: int|null}> the dry lap, what it pays and the place among the bots, by the ranks of the parts of the car, as "tires.suspension.brakes.exhaust" */
    private array $frozen = [];

    public function __construct(private readonly Rules $rules, private readonly RunInputsFactory $inputs) {}

    /**
     * The table.
     *
     * @param  int  $trials  how many players are drawn (the committed table has `TRIALS`)
     * @return array<string, mixed>
     */
    public function compute(int $trials = self::TRIALS): array
    {
        $crates = new Crates($this->rules->shopCrates());
        $base = $this->lapMsOf($this->ranks([]));
        $baseIncome = $this->incomeOf($this->ranks([]));
        $floor = $crates->floorRarity('standard')->value;
        $sums = array_fill(0, self::CRATES + 1, 0.0);
        $lapSums = array_fill(0, self::CRATES + 1, 0.0);

        for ($trial = 0; $trial < $trials; $trial++) {
            $best = ['tires' => 1, 'suspension' => 1, 'brakes' => 1];
            $sinceFloor = 0;
            $sinceLegendary = 0;

            for ($crate = 1; $crate <= self::CRATES; $crate++) {
                $rarity = $crates->draw(self::SEED_PREFIX."-{$trial}-{$crate}", 'standard', $sinceFloor, $sinceLegendary);
                $slot = self::CYCLE[($crate - 1) % count(self::CYCLE)];
                $best[$slot] = max($best[$slot], $rarity->value);
                $sinceFloor = $rarity->value >= $floor ? 0 : $sinceFloor + 1;
                $sinceLegendary = $rarity->value === 5 ? 0 : $sinceLegendary + 1;
                $lapSums[$crate] += $base / $this->lapMsOf($this->ranks($best)) - 1;
                $sums[$crate] += $this->incomeOf($this->ranks($best)) / $baseIncome - 1;
            }
        }

        $gain = array_map(static fn (float $sum): float => round($sum / $trials, 5), $sums);
        $lapGain = array_map(static fn (float $sum): float => round($sum / $trials, 5), $lapSums);

        return [
            'description' => 'The gain of income in an hour (0.2 is a fifth more: the laps in an hour times what a lap pays, which the place among the bots multiplies) of the car after n standard crates, opened in rounds of the cycle, with the best part of every slot mounted. Made by the command economy:rarity-pace.',
            'car' => self::CAR,
            'track' => self::TRACK,
            'weather' => 'dry',
            'parts' => self::PARTS,
            'cycle' => self::CYCLE,
            'group' => 'standard',
            'seed_prefix' => self::SEED_PREFIX,
            'trials' => $trials,
            'base_lap_ms' => $base,
            'base_position' => $this->frozenOf($this->ranks([]))['position'],
            'rarity_gain' => $this->rarityGain($base),
            'lap_gain_by_crates' => $lapGain,
            'gain_by_crates' => $gain,
        ];
    }

    /** The table as the file has it. */
    public function computedJson(int $trials = self::TRIALS): string
    {
        try {
            return json_encode($this->compute($trials), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $problem) {
            throw new \RuntimeException('The table of the pace of the rarities cannot be written: '.$problem->getMessage(), 0, $problem);
        }
    }

    /**
     * What a rarity of one slot is worth, and of the three that matter at once (the ranks 2 to 5 are uncommon to legendary): for the docs and as a check.
     *
     * @return array<string, list<float>>
     */
    private function rarityGain(int $base): array
    {
        $gain = [];

        foreach (['tires', 'suspension', 'brakes', 'exhaust'] as $slot) {
            $gain[$slot] = array_map(fn (int $rank): float => round($base / $this->lapMsOf($this->ranks([$slot => $rank])) - 1, 5), [2, 3, 4, 5]);
        }

        $gain['all_three'] = array_map(fn (int $rank): float => round($base / $this->lapMsOf($this->ranks(['tires' => $rank, 'suspension' => $rank, 'brakes' => $rank])) - 1, 5), [2, 3, 4, 5]);

        return $gain;
    }

    /**
     * @param  array<string, int>  $ranks  the rank (1 common to 5 legendary) of the part of each slot, 1 for the slots that are left out
     * @return array<string, int>
     */
    private function ranks(array $ranks): array
    {
        return array_map(static fn (string $slot): int => $ranks[$slot] ?? 1, array_combine(array_keys(self::PARTS), array_keys(self::PARTS)));
    }

    /** @param  array<string, int>  $ranks */
    private function lapMsOf(array $ranks): int
    {
        return $this->frozenOf($ranks)['ms'];
    }

    /**
     * What an hour of the car pays, per ms of lap: what a lap pays (the place among the bots counted) over how long it takes.
     *
     * @param  array<string, int>  $ranks
     */
    private function incomeOf(array $ranks): float
    {
        $frozen = $this->frozenOf($ranks);

        return $frozen['cash'] / $frozen['ms'];
    }

    /**
     * @param  array<string, int>  $ranks
     * @return array{ms: int, cash: int, position: int|null}
     */
    private function frozenOf(array $ranks): array
    {
        $key = implode('.', $ranks);

        if (isset($this->frozen[$key])) {
            return $this->frozen[$key];
        }

        $mounted = [];

        foreach (self::PARTS as $slot => $slug) {
            $mounted[$slot] = ['slug' => $slug, 'rarity' => $ranks[$slot]];
        }

        $config = (new RunFreezer)->freeze($this->inputs->make(self::CAR, self::TRACK, $mounted), 'rarity-pace', 0)->config;

        return $this->frozen[$key] = ['ms' => $config['lap_ms']['dry'], 'cash' => $config['lap_cash_cents']['dry'], 'position' => $config['field']['position'] ?? null];
    }
}
