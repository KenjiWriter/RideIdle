<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\Catalog;
use App\Content\GameCrates;
use App\Content\GameIncome;
use App\Content\GameUnits;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\TutorialSteps;
use App\Domain\Economy\CrateEconomy;
use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\ExtrasResult;
use App\Domain\Economy\ExtrasStudy;
use App\Domain\Economy\Finding;
use App\Domain\Economy\GameLadder;
use App\Domain\Economy\GateReport;
use App\Domain\Economy\GateRow;
use App\Domain\Economy\LadderRow;
use App\Domain\Economy\MergeEconomy;
use App\Domain\Economy\PaceGate;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\RulesConsistency;
use App\Domain\Economy\Simulator;
use App\Domain\Economy\UpkeepReport;
use App\Domain\Market\Crates;
use App\Domain\Run\Rarity;
use App\Domain\Run\RunFreezer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The economy simulator, version 0 (Systemy VI, points 17 to 19).
 *
 * It reads a ruleset of anchors (content/rules/economy.json), plays three kinds of players up the ladder of cars, and says
 * on which day each of them buys each car. The pace gate then says whether those days are within 25% of the days of the
 * design, and the exit code is the answer, so a pipeline can stop a ruleset that spoils the pace. Next to it the numbers
 * the game runs on are set against the anchors: that is a report, and fails the command only with --strict.
 *
 * The same players are then played with the fleet that the game really lets a driver have (every car drives and gives experience, garages
 * hold three, a wreck is cheap), and the income that the game reaches at each level, from its own cars and tracks, is set against the ladder.
 * Both are a report too, and fail the command only with --strict-game: they say what the rules of the game do to the pace of the design.
 *
 * Crates of parts and storage units are played on top of that scenario at the shares of the income that the ruleset names, and the sale of a
 * car for a share of what was paid: what they do to the days of the ladder, what a crate pays back against a car, and what a unit returns.
 *
 * Merging two parts is worked out exactly from the rules of the game (the chances with the protection from bad luck, the fee and the scrap) and set
 * against the crates: how many common parts and how much cash a part of each rarity takes by merging, and what the same rarity costs from crates.
 * It is a report only, with no gate of its own: the design says merging cannot be the only way to the rare parts.
 */
final class SimulateEconomy extends Command
{
    /** The exit code for a ruleset that cannot be read, which is not the same as one that does not pass. */
    private const INVALID_INPUT = 2;

    private const SCHEMA_BASE = 'https://rideidle.local/schema/';

    /** The slots of the parts of the shop that wear: the upkeep of the shop set is counted over them. */
    private const UPKEEP_SLOTS = ['tires', 'suspension', 'brakes', 'exhaust'];

    /**
     * The slots of the engine group and the engines. Their parts are priced by the board of the catalog (a turbo costs two to five times what the curve of the
     * anchors gives a part of its tier, and an engine by the litre of its layout, so a V12 costs three and a R4 a third of what the tier asks), so their prices
     * are not set against that curve; the check covers the parts the curve describes.
     */
    private const ENGINE_GROUP_SLOTS = ['boost', 'cooling', 'gearbox', 'engine'];

    protected $signature = 'economy:simulate
        {--ruleset= : The ruleset to play, a file of the shape of content/rules/economy.json (that file when left out)}
        {--player=* : Play only these kinds of players, by their keys (A, B or C)}
        {--json : Print the report as JSON}
        {--strict : Fail also when a number of the rules of the game is off the anchors}
        {--strict-game : Fail also when the fleet of the game is out of the pace of the design, or the ladder of the game is off the anchors}';

    protected $description = 'Simulate three kinds of players up the ladder of cars and check the pace against the design (the gate), and the rules of the game against the anchors';

    public function handle(Rules $game, Catalog $catalog, Simulator $simulator, PaceGate $gate, RulesConsistency $consistency, GameLadder $ladder, GameIncome $income, ExtrasStudy $study, GameCrates $crates, GameUnits $units, TutorialSteps $tutorial, RunInputsFactory $factory): int
    {
        $path = (string) ($this->option('ruleset') ?: config('ride.content_path').'/rules/economy.json');

        try {
            $data = $this->read($path);
            $economy = EconomyRules::fromArray($data);
        } catch (InvalidArgumentException $problem) {
            $this->error($problem->getMessage());

            return self::INVALID_INPUT;
        }

        $runs = $this->runs($economy, $simulator);

        if ($runs === null) {
            return self::INVALID_INPUT;
        }

        $report = $gate->evaluate($economy, $runs);
        $findings = $consistency->check($economy, $this->numbersOf($game, $catalog, $tutorial));
        $off = array_values(array_filter($findings, static fn (Finding $finding): bool => ! $finding->ok));

        $fleetRuns = array_intersect_key($simulator->simulateAllGame($economy), $runs);
        $fleetReport = $gate->evaluate($economy, $fleetRuns);
        $incomeByLevel = $income->byLevel($ladder->levels($economy));
        $rows = $ladder->check($economy, $incomeByLevel);
        $unreachable = $ladder->unreachableRungs($economy, $incomeByLevel, $economy->consistencyTolerance());

        // Before `$game` stops being the rules of the game: merging is read from them.
        $crateEconomy = $crates->economy();
        $merge = $this->merge($game, $crateEconomy);
        $upkeep = $this->upkeep($game, $catalog, $factory, $rows, (float) $data['model']['upkeep']);

        $game = ['runs' => $fleetRuns, 'report' => $fleetReport, 'rows' => $rows, 'unreachable' => $unreachable];
        $extras = $study->run($economy, $crateEconomy, $units->economy(), array_map('strval', array_keys($runs)));
        $gameOk = $fleetReport->passed() && $unreachable === [] && array_filter($rows, static fn (LadderRow $row): bool => ! $row->ok || ! $row->xpOk) === [] && $extras->passed();

        if ($this->option('json')) {
            $this->line((string) json_encode($this->asArray($economy, $runs, $report, $findings, $game, $extras, $merge, $upkeep), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->print($economy, $runs, $report, $findings, $game, $extras, $merge, $upkeep);
            $this->printLaterGifts($tutorial);
        }

        $strict = (bool) $this->option('strict');

        return $report->passed() && (! $strict || $off === []) && (! $this->option('strict-game') || $gameOk) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The ruleset, checked against its schema.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the file is missing, is not JSON or does not match the schema
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("There is no ruleset at {$path}.");
        }

        try {
            $object = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            $array = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $problem) {
            throw new InvalidArgumentException("The ruleset at {$path} is not JSON: {$problem->getMessage()}");
        }

        $validator = new Validator;
        $validator->resolver()?->registerPrefix(self::SCHEMA_BASE, rtrim((string) config('ride.content_path'), '/\\').'/schema');
        $outcome = $validator->validate($object, self::SCHEMA_BASE.'economy.schema.json');

        if (! $outcome->isValid()) {
            $errors = (new ErrorFormatter)->formatFlat($outcome->error());

            throw new InvalidArgumentException("The ruleset at {$path} does not match its schema:\n - ".implode("\n - ", $errors));
        }

        return $array;
    }

    /**
     * The kinds of players that were asked for, played.
     *
     * @return array<string, PlayerRun>|null null when a key that is not a kind of player was named
     */
    private function runs(EconomyRules $economy, Simulator $simulator): ?array
    {
        $asked = array_map('strval', (array) $this->option('player'));
        $unknown = array_values(array_diff($asked, array_keys($economy->players())));

        if ($unknown !== []) {
            $this->error('There is no kind of player '.implode(', ', $unknown).'. The ruleset has '.implode(', ', array_keys($economy->players())).'.');

            return null;
        }

        $runs = $simulator->simulateAll($economy);

        return $asked === [] ? $runs : array_intersect_key($runs, array_flip($asked));
    }

    /**
     * The numbers the game runs on: the first track, the fuel, and the parts of the pack.
     *
     * @return array{lap_ms: int, fuel_ml: int, fuel_price_cents: int, reward_cents: int, xp_per_lap: int, other_cars_xp_share: float, parts: list<array{slug: string, tier: int, slot: string, price_cents: int}>}
     */
    private function numbersOf(Rules $game, Catalog $catalog, TutorialSteps $tutorial): array
    {
        $track = $game->track($game->startingTrack()) ?? [];

        return [
            'lap_ms' => (int) $track['base_lap_ms'],
            'fuel_ml' => (int) $track['fuel_per_lap_ml'],
            'fuel_price_cents' => $game->fuelPriceCentsPerLiter(),
            'reward_cents' => (int) $track['reward_cents_per_lap'],
            'xp_per_lap' => (int) $track['xp_per_lap'],
            'other_cars_xp_share' => $game->otherCarsXpPpm() / 1_000_000,
            'tutorial' => $this->tutorialGifts($tutorial),
            'parts' => array_values(array_map(
                static fn (array $part): array => ['slug' => (string) $part['slug'], 'tier' => (int) $part['tier'], 'slot' => (string) $part['slot'], 'price_cents' => (int) $part['price_cents']],
                array_filter($catalog->parts(), static fn (array $part): bool => ! in_array($part['slot'], self::ENGINE_GROUP_SLOTS, true)),
            )),
        ];
    }

    /**
     * What the steps of the guided first quarter of an hour (the first two phases) give: the experience, the cash (the repair only gives back what it cost, so
     * it is no gift) and the garages. The steps of the first day and week give no experience and no garage, only cash and parts, which `laterGifts` adds up.
     *
     * @return array{xp: int, cash: float, garages: int}
     */
    private function tutorialGifts(TutorialSteps $tutorial): array
    {
        $steps = array_values(array_filter($tutorial->all(), static fn (array $step): bool => $step['phase'] <= 2));

        return [
            'xp' => array_sum(array_map(static fn (array $step): int => (int) ($step['reward']['xp'] ?? 0), $steps)),
            'cash' => array_sum(array_map(static fn (array $step): int => $step['key'] === 'repair' ? 0 : (int) ($step['reward']['cash_cents'] ?? 0), $steps)) / 100,
            'garages' => count(array_filter($steps, static fn (array $step): bool => isset($step['reward']['garage']))),
        ];
    }

    /**
     * What the steps of the first day and week give on top of that, in cash and in parts, which the scenario of the game does not count: the player has earned
     * it by then, and it is small beside what the laps pay. A report only.
     */
    private function printLaterGifts(TutorialSteps $tutorial): void
    {
        $later = array_values(array_filter($tutorial->all(), static fn (array $step): bool => $step['phase'] > 2));

        if ($later === []) {
            return;
        }

        $cash = array_sum(array_map(static fn (array $step): int => (int) ($step['reward']['cash_cents'] ?? 0), $later)) / 100;
        $parts = array_sum(array_map(static fn (array $step): int => (int) ($step['gift']['count'] ?? (isset($step['gift']) ? 1 : 0)) + (isset($step['reward']['part']) ? 1 : 0), $later));

        $this->newLine();
        $this->line('The steps of the first day and week ('.count($later).') add $'.$this->trim($cash).' and '.$parts.' parts on top of that, which the scenario does not count: they pay no experience and no garage.');
    }

    /**
     * What merging costs a part of each rarity, set against the crates: the rules of the game give the chances, the fee and the scrap, and the crates
     * give how many of them it takes to get the same rarity (a crate costs the price of a part of the model times the price factor of the slot crate).
     *
     * @return list<array<string, float|string|bool>>
     */
    private function merge(Rules $game, CrateEconomy $crates): array
    {
        $merge = new MergeEconomy(
            static fn (string $rarity, int $failures): int => $game->mergeChancePpm(Rarity::fromKey($rarity), $failures),
            // The rules count both as a share of the price of the model, so the share is what they make of a million.
            $game->mergeCostCents(1_000_000),
            $game->mergeScrapCents(1_000_000),
        );

        return $merge->againstCrates($crates->meansToFirst(), $crates->priceFactorPpm / 1_000_000);
    }

    /**
     * What the game charges for keeping the best car of each level of the ladder of the game, against the share that the model takes off: the service
     * every so many kilometres, and the repairs of the parts of the shop that the level opens. Read from the rules and the pack, with the real pipeline;
     * a report only.
     *
     * @param  list<LadderRow>  $rows  the ladder of the game: the best pair of car and track of every level and its income
     * @return array{model: float, rows: list<array<string, mixed>>, summary: array{min: float, max: float, median: float, model: float, below: list<int>, above: list<int>}, every_km: int}
     */
    private function upkeep(Rules $game, Catalog $catalog, RunInputsFactory $factory, array $rows, float $modelShare): array
    {
        $crates = new Crates($game->shopCrates());
        $report = new UpkeepReport(
            $game->service()['every_km'],
            static fn (int $lapCashCents, int $lapLengthM): int => $game->serviceCostCents($lapCashCents, $lapLengthM),
            // The repair of a part costs a share of its price for the whole life of it: what the rules ask for a part that is worth a million, worn out.
            $game->partRepairCostCents(1_000_000, 0),
        );

        $levels = array_map(function (LadderRow $row) use ($game, $catalog, $factory, $crates): array {
            $frozen = (new RunFreezer)->freeze($factory->make($row->car, $row->track), 'game-ladder', 0)->config;
            $set = $this->shopSet($row->level, $game, $catalog, $crates);
            $power = $factory->power($row->car, array_column($set, 'model', 'slot'));
            $surface = $game->track($row->track)['surface'];

            return [
                'level' => $row->level,
                'car' => $row->car,
                'track' => $row->track,
                'net' => $row->game,
                'lap_ms' => $frozen['lap_ms']['dry'],
                'lap_length_m' => $game->trackLengthM($row->track),
                'lap_cash_cents' => $frozen['lap_cash_cents']['dry'],
                'service_ppm' => $game->drivetrain($catalog->vehicle($row->car)['drivetrain'])['service_ppm'],
                'parts' => array_map(static fn (array $part): array => [
                    'slot' => $part['slot'],
                    'model' => $part['model'],
                    'price_cents' => $part['price_cents'],
                    'ppb_per_km' => $game->wearPerKmPpb($part['slot'], $surface, $power),
                ], $set),
            ];
        }, $rows);

        $upkeepRows = $report->rows($levels);

        return ['model' => $modelShare, 'rows' => $upkeepRows, 'summary' => $report->summary($upkeepRows, $modelShare), 'every_km' => $game->service()['every_km']];
    }

    /**
     * The parts of the shop that a level of the driver has on a car: in each slot that wears and is sold (tires, suspension, brakes, exhaust) the
     * cheapest model of the highest tier that the level opens (a tier opens as the crates of it do), and nothing in a slot with no part open yet.
     *
     * @return list<array{slot: string, model: string, price_cents: int}>
     */
    private function shopSet(int $level, Rules $game, Catalog $catalog, Crates $crates): array
    {
        $best = [];

        foreach ($game->shopParts() as $slug) {
            $part = $catalog->part($slug);

            if ($part === null || ! in_array($part['slot'], self::UPKEEP_SLOTS, true) || $crates->playerLevelOfTier($part['tier']) > $level) {
                continue;
            }

            $current = $best[$part['slot']] ?? null;

            if ($current === null || $part['tier'] > $current['tier'] || ($part['tier'] === $current['tier'] && $part['price_cents'] < $current['price_cents'])) {
                $best[$part['slot']] = ['slot' => $part['slot'], 'model' => $slug, 'tier' => $part['tier'], 'price_cents' => $part['price_cents']];
            }
        }

        $set = [];

        foreach (self::UPKEEP_SLOTS as $slot) {
            if (isset($best[$slot])) {
                $set[] = ['slot' => $slot, 'model' => $best[$slot]['model'], 'price_cents' => $best[$slot]['price_cents']];
            }
        }

        return $set;
    }

    /**
     * @param  array<string, PlayerRun>  $runs
     * @param  list<Finding>  $findings
     * @param  array{runs: array<string, PlayerRun>, report: GateReport, rows: list<LadderRow>, unreachable: array<int, string>}  $game
     * @param  list<array<string, float|string|bool>>  $merge
     * @param  array{model: float, rows: list<array<string, mixed>>, summary: array{min: float, max: float, median: float, model: float, below: list<int>, above: list<int>}, every_km: int}  $upkeep
     */
    private function print(EconomyRules $economy, array $runs, GateReport $report, array $findings, array $game, ExtrasResult $extras, array $merge, array $upkeep): void
    {
        $this->line("Economy ruleset {$economy->version()}");
        $this->newLine();

        $this->line('The ladder, from the anchors:');
        $header = ['rung', 'car', 'level', 'net $/h', '$/lap', 'payback h', ...array_map(static fn ($player): string => "{$player->key} $/day", array_values($economy->players()))];
        $rows = [];

        for ($rung = 0; $rung < $economy->rungs(); $rung++) {
            $rows[] = [
                $economy->rungName($rung),
                $this->money($economy->carValue($rung)),
                $economy->minPlayerLevel($rung),
                $this->money($economy->netPerHour($rung)),
                number_format($economy->grossPerLap($rung), 2),
                number_format($economy->paybackHours($rung), 1),
                ...array_map(fn ($player): string => $this->money($economy->dailyIncome($player, $rung)), array_values($economy->players())),
            ];
        }

        $this->table($header, $rows);

        foreach ($runs as $key => $run) {
            $this->newLine();
            $this->line("Player {$key}, {$run->player->name} ({$run->player->visitsPerDay} visits/day, up to ".$this->trim($run->player->maxHoursPerDay).' h, activity '.$this->trim($run->player->activity).'):');
            $this->gateTable($report, (string) $key);
        }

        $failures = $report->failures();
        $this->newLine();
        $tolerance = sprintf('%.0f%%', $report->tolerance * 100);

        if ($failures === []) {
            $this->info("Pace gate: PASSED. Every day is within {$tolerance} of the target (or within {$this->trim($report->slackDays)} day of it, for a target of a day or two).");
        } else {
            $this->error('Pace gate: FAILED. '.count($failures).' of '.count($report->rows)." days are out of {$tolerance} of the target.");
        }

        $this->printGame($economy, $game);
        $this->printExtras($economy, $extras);
        $this->printMerge($merge);
        $this->printUpkeep($upkeep);

        $this->newLine();
        $this->line('The rules of the game against the anchors (tolerance '.sprintf('%.0f%%', $economy->consistencyTolerance() * 100).'; the engine group and the engines, priced by the board of the catalog, are not set against the curve of the parts):');
        $this->table(
            ['number', 'anchors', 'game', 'off', ''],
            array_map(fn (Finding $finding): array => [
                $finding->subject,
                $finding->expected === null ? 'no anchor' : $this->trim($finding->expected).' '.$finding->unit,
                $this->trim($finding->actual).' '.$finding->unit,
                $finding->deviation() === null ? '-' : sprintf('%+.0f%%', $finding->deviation() * 100),
                $finding->ok ? 'ok' : 'OFF',
            ], $findings),
        );

        $off = count(array_filter($findings, static fn (Finding $finding): bool => ! $finding->ok));
        $off === 0
            ? $this->info('Rules of the game: every number is within the anchors.')
            : $this->warn("Rules of the game: {$off} of ".count($findings).' numbers are off the anchors. This does not fail the command, except with --strict.');

        $summary = $upkeep['summary'];
        $this->line(sprintf(
            'Upkeep (informational, not a number of the table above): the model takes %s of the income off for service, repairs and tires, and the game charges %s to %s of it (the section on the upkeep above): less than the model at the levels %s, more at the levels %s.',
            $this->percent($summary['model']),
            $this->percent($summary['min']),
            $this->percent($summary['max']),
            $summary['below'] === [] ? 'none' : implode(', ', $summary['below']),
            $summary['above'] === [] ? 'none' : implode(', ', $summary['above']),
        ));
    }

    /**
     * What the game charges for keeping a car, set against what the model takes off: a report only, so it has no gate and does not touch the exit code.
     *
     * @param  array{model: float, rows: list<array<string, mixed>>, summary: array{min: float, max: float, median: float, model: float, below: list<int>, above: list<int>}, every_km: int}  $upkeep
     */
    private function printUpkeep(array $upkeep): void
    {
        $this->newLine();
        $this->line("Upkeep that the game charges (a service every {$this->trim((float) $upkeep['every_km'])} km, and the repairs of the shop set of the level), against the {$this->percent($upkeep['model'])} that the model takes off:");
        $this->line('The car is the best pair of the ladder of the game at the level, with its stock parts (the income of the ladder above). The service costs a share (4%) of what the car earns between two services (the reward of its lap, with the place among the bots counted, times the laps of the interval), so it is about the same share of the income at every level, whatever the car cost. The shop set is, in each of tires, suspension, brakes and exhaust, the cheapest model of the highest tier that the level opens (nothing where no tier is open yet); a part wears by the kilometres, faster with the power of the car, and putting it right costs half of its price for the whole of its life.');
        $this->table(
            ['level', 'car', 'income $/h', 'km/h', 'service $/h', 'parts $/h', 'upkeep $/h', 'share', 'shop set $'],
            array_map(fn (array $row): array => [
                $row['level'],
                $row['car'],
                number_format($row['net'], 0, '.', ' '),
                number_format($row['km_per_hour'], 0),
                number_format($row['service'], 2),
                number_format($row['parts'], 2),
                number_format($row['upkeep'], 2),
                $this->percent($row['share']),
                number_format($row['set_price'], 0, '.', ' '),
            ], $upkeep['rows']),
        );

        $summary = $upkeep['summary'];
        $this->line(sprintf(
            'Upkeep: the game charges %s to %s of the income over the levels of the ladder (the middle level %s), against the %s that the model takes off: less than the model at the levels %s, more at the levels %s. This is a report: it has no gate and does not touch the exit code. Where the game charges less, the player has the difference in cash that the model does not count, and the game runs richer than the model; where it charges more, the game runs poorer (the pace of the ladder is bound by the level of the player more than by cash).',
            $this->percent($summary['min']),
            $this->percent($summary['max']),
            $this->percent($summary['median']),
            $this->percent($summary['model']),
            $summary['below'] === [] ? 'none' : implode(', ', $summary['below']),
            $summary['above'] === [] ? 'none' : implode(', ', $summary['above']),
        ));
    }

    /** A share as a percent, with a decimal below ten percent. */
    private function percent(float $share): string
    {
        return sprintf($share < 0.1 ? '%.1f%%' : '%.0f%%', $share * 100);
    }

    /** The days a kind of player bought each car, against the days of the design. */
    private function gateTable(GateReport $report, string $key): void
    {
        $this->table(
            ['car', 'target day', 'day', 'off', 'waited for', 'gate'],
            array_map(fn (GateRow $row): array => [
                $row->rung,
                $row->target,
                $row->day ?? 'never',
                $row->deviation() === null ? '-' : sprintf('%+.0f%%', $row->deviation() * 100),
                $row->binding ?? '-',
                $row->passed ? 'ok' : 'OUT',
            ], array_values(array_filter($report->rows, static fn (GateRow $row): bool => $row->player === $key))),
        );
    }

    /**
     * The players with the fleet that the game lets them have, and the income that the game reaches at each level.
     *
     * @param  array{runs: array<string, PlayerRun>, report: GateReport, rows: list<LadderRow>, unreachable: array<int, string>}  $game
     */
    private function printGame(EconomyRules $economy, array $game): void
    {
        $fleet = $economy->fleetModel();
        $gifts = $economy->tutorialGifts();
        $this->newLine();
        $this->line('The game scenario: the fleet that the rules of the game allow (every car drives and earns, and the cars the driver does not play give '.$this->trim($fleet->otherCarsXpShare * 100).'% of their experience, a wreck is $'.$this->trim($fleet->wreckPrice).', a garage $'.$this->trim($fleet->garagePrice).' for '.$fleet->garageCapacity.' places), and the player starts with what the tutorial gives: '.$this->trim($gifts->xp).' XP, $'.$this->trim($gifts->cash).' and '.$gifts->freeGarages.' garage of another city:');

        foreach ($game['runs'] as $key => $run) {
            $this->newLine();
            $this->line("Player {$key}, {$run->player->name}: ends with {$run->wrecks} wrecks and {$run->extraGarages} garages of other cities.");
            $this->gateTable($game['report'], (string) $key);
        }

        $failures = $game['report']->failures();
        $this->newLine();

        $failures === []
            ? $this->info('Fleet gate (game): PASSED. Every day is within the tolerance of the target.')
            : $this->warn('Fleet gate (game): FAILED. '.count($failures).' of '.count($game['report']->rows).' days are out of '.sprintf('%.0f%%', $game['report']->tolerance * 100).' of the target. This does not fail the command, except with --strict-game.');

        $this->newLine();
        $this->line('The ladder of the game: the best net $/h that its cars and tracks reach at a level, against the anchor of the rung of that level, and the experience an hour of that pair against the '.$this->trim($economy->xpPerHourOfDriving()).' XP an hour of the model (tolerance '.sprintf('%.0f%%', $economy->consistencyTolerance() * 100).'):');
        $this->table(
            ['level', 'rung', 'anchors $/h', 'game $/h', 'off', 'XP/h', 'XP off', 'best', ''],
            array_map(fn (LadderRow $row): array => [
                $row->level,
                $row->rung,
                $this->money($row->anchor),
                $this->money($row->game),
                sprintf('%+.0f%%', $row->deviation() * 100),
                sprintf('%.0f', $row->xp),
                sprintf('%+.0f%%', $row->xpDeviation() * 100).($row->xpOk ? '' : ' OFF'),
                "{$row->car} on {$row->track}",
                $row->ok ? 'ok' : 'OFF',
            ], $game['rows']),
        );

        $off = count(array_filter($game['rows'], static fn (LadderRow $row): bool => ! $row->ok));
        $xpOff = count(array_filter($game['rows'], static fn (LadderRow $row): bool => ! $row->xpOk));

        if ($game['unreachable'] !== []) {
            $this->warn('No car and track of the game reach '.implode(', ', $game['unreachable']).'.');
        }

        $off === 0 && $game['unreachable'] === []
            ? $this->info('Ladder of the game: every level is within the anchors.')
            : $this->warn("Ladder of the game: {$off} of ".count($game['rows']).' levels are off the anchors. This does not fail the command, except with --strict-game.');

        $xpOff === 0
            ? $this->info('Experience an hour of the game: every level is within the model.')
            : $this->warn("Experience an hour of the game: {$xpOff} of ".count($game['rows']).' levels are off the model. This does not fail the command, except with --strict-game.');
    }

    /** The crates of parts and the storage units played on top of the scenario game, and the sale of a car for a share of what was paid. */
    private function printExtras(EconomyRules $economy, ExtrasResult $extras): void
    {
        $rungs = $economy->rungs();
        $days = static fn (PlayerRun $run): array => array_map(static fn (int $rung): string => (string) ($run->dayOf($rung) ?? '-'), range(1, $rungs - 1));
        $passedFor = static fn (GateReport $gate, string $key): bool => array_filter($gate->rows, static fn (GateRow $row): bool => $row->player === $key && ! $row->passed) === [];
        $percent = fn (float $share): string => $this->trim($share * 100).'%';
        $carCaption = array_map(static fn (int $rung): string => $economy->rungName($rung), range(1, $rungs - 1));

        $this->newLine();
        $this->line('Crates of parts, on top of the game scenario: each player spends a share of the income of a day on crates of the slots (tires, suspension, brakes), of the tier that the level opens, at the price of the anchors.');
        $this->line('The gate is the shift of the days from the scenario without them (tolerance '.sprintf('%.0f%%', $economy->gateTolerance() * 100).'), not the targets of the design.');

        foreach (array_keys($extras->baseline) as $key) {
            $this->newLine();
            $this->line("Player {$key}:");
            $rows = [['none', ...$days($extras->baseline[$key]), '-', '-', '-', '-']];

            foreach ($extras->crates as $share => $runs) {
                $note = $runs[$key]->notes['crates'];
                $rows[] = [
                    $percent((float) $share),
                    ...$days($runs[$key]),
                    $note['crates'],
                    $this->money($note['spent']),
                    sprintf('%+.0f%%', $note['uplift'] * 100),
                    $note['first_rare_day'] ?? 'never',
                    $passedFor($extras->crateShift[$share], (string) $key) ? 'ok' : 'OUT',
                ];
            }

            $this->table(['share', ...$carCaption, 'crates', 'spent', 'uplift', 'first rare day', 'gate'], $rows);
        }

        $this->newLine();
        $extras->crateShiftPassed()
            ? $this->info('Crate shift gate: PASSED. No share moves a day of the ladder out of the tolerance.')
            : $this->warn('Crate shift gate: FAILED. A share moves a day of the ladder out of the tolerance. This does not fail the command, except with --strict-game.');

        $this->newLine();
        $this->line("What crates pay back, in days, against the next car of the ladder (player {$extras->valuedPlayer}). The first crates pay back sooner as the gain falls with every crate; the gate is the whole programme, the crates up to the guarantee of a legendary part, which must not pay back sooner than the car:");
        $this->table(
            ['rung', 'level', 'crate $', 'first crate', 'after 10', 'after 40', 'programme', 'next car', ''],
            array_map(fn (array $row): array => [
                $row['rung'],
                $row['level'],
                $row['price'] === null ? '-' : $this->money($row['price']),
                ...array_map(fn (array $crate): string => $crate['crate_days'] === null ? '-' : number_format($crate['crate_days'], 1), $row['crates']),
                $row['programme_days'] === null ? '-' : number_format($row['programme_days'], 1),
                $row['step_days'] === null ? '-' : number_format($row['step_days'], 1),
                $row['crate_pays_back_sooner'] ? 'OUT' : 'ok',
            ], $extras->crateValue),
        );

        $programme = array_map(static fn (array $row): string => $row['rung'], array_filter($extras->crateValue, static fn (array $row): bool => $row['crate_pays_back_sooner']));
        $extras->crateValuePassed()
            ? $this->info('Crate value gate: PASSED. The programme of '.($extras->crateValue[0]['programme_crates'] ?? 0).' crates never pays back sooner than a car'.($extras->firstCratesSooner() === [] ? '.' : ' (the first crates do, at '.implode(', ', $extras->firstCratesSooner()).').'))
            : $this->warn('Crate value gate: FAILED. The programme of crates pays back sooner than the next car at '.implode(', ', $programme).'. This does not fail the command, except with --strict-game.');

        $this->newLine();
        $this->line('Storage units, on top of the game scenario: each player spends a share of the income of a day on the dearest unit that the level opens, and the find is sold at once (the expected return of the table).');

        foreach (array_keys($extras->baseline) as $key) {
            $this->newLine();
            $this->line("Player {$key}:");
            $rows = [['none', ...$days($extras->baseline[$key]), '-', '-', '-', '-']];

            foreach ($extras->units as $share => $runs) {
                $note = $runs[$key]->notes['units'];
                $rows[] = [
                    $percent((float) $share),
                    ...$days($runs[$key]),
                    $this->money($note['spent']),
                    $this->money($note['net_cost']),
                    sprintf('%.1f%%', $note['net_share_of_income'] * 100),
                    number_format($note['expected_jumps'], 2),
                    $passedFor($extras->unitShift[$share], (string) $key) ? 'ok' : 'OUT',
                ];
            }

            $this->table(['share', ...$carCaption, 'spent', 'net cost', 'of the income', 'jumps', 'gate'], $rows);
        }

        $this->newLine();
        $extras->unitShiftPassed()
            ? $this->info('Unit shift gate: PASSED. No share moves a day of the ladder out of the tolerance.')
            : $this->warn('Unit shift gate: FAILED. A share moves a day of the ladder out of the tolerance. This does not fail the command, except with --strict-game.');

        $this->newLine();
        $this->line('What a kind of storage unit returns when the find is sold at once:');
        $this->table(
            ['unit', 'price', 'level', 'mean return', 'chance of 2x', ''],
            array_map(fn (array $row): array => [
                $row['kind'],
                $this->money($row['price']),
                $row['unlock_level'],
                sprintf('%.0f%%', $row['mean_return'] * 100),
                sprintf('%.1f%%', $row['chance_double'] * 100),
                $row['ok'] ? 'ok' : 'OUT',
            ], $extras->unitReturns),
        );
        $extras->unitReturnsPassed()
            ? $this->info('Unit return gate: PASSED. Every kind returns less than it costs on average.')
            : $this->warn('Unit return gate: FAILED. A kind of unit returns at least what it costs on average. This does not fail the command, except with --strict-game.');

        $this->newLine();
        $this->line('The sale of a car for a share of what was paid, not of the value of the anchor (a wreck of the yard was paid $1 140, not $2 000). A report only: the greedy player buys the next car, a wreck or a garage by what pays back soonest, so a dearer first step moves it to the wrecks first.');
        $this->table(
            ['player', 'days of the ladder, as the design counts the sale', 'as the game counts it'],
            array_map(fn (string $key): array => [$key, implode(' ', $days($extras->baseline[$key])), implode(' ', $days($extras->sales[$key]))], array_keys($extras->baseline)),
        );
    }

    /**
     * Merging two parts against the crates: a report only, so it has no gate and does not touch the exit code.
     *
     * @param  list<array<string, float|string|bool>>  $merge
     */
    private function printMerge(array $merge): void
    {
        $this->newLine();
        $this->line('Merging two parts (Systemy I, point 3): two parts of one slot, tier and rarity make one of the next rarity, or fail and are lost (the scrap comes back). Every failure raises the chance of the next attempt (the protection from bad luck).');
        $this->line('What a part of a rarity takes when it is made all the way from common parts, in units of p, the price of one part of the model, against what the crates ask for the same rarity (a crate costs p times the price factor, and gives parts of other rarities too, which are left aside):');
        $this->table(
            ['rarity', 'chance', 'attempts', 'no pity', 'commons', 'no pity', 'cost p', 'crates', 'crates p', 'merge/crates', ''],
            array_map(fn (array $row): array => [
                $row['rarity'],
                sprintf('%.0f%%', $row['chance'] * 100),
                number_format($row['attempts'], 2),
                number_format($row['attempts_no_pity'], 2),
                number_format($row['commons'], 1),
                number_format($row['commons_no_pity'], 1),
                number_format($row['cost'], 1),
                number_format($row['crates'], 1),
                number_format($row['crate_cost'], 1),
                number_format($row['ratio'], 2),
                $row['cheaper'] ? 'merging cheaper' : 'merging dearer',
            ], $merge),
        );

        foreach ($merge as $row) {
            $this->line(sprintf(
                '%s %s part by merging: %s common parts and %s p (%s attempts at the last step); by crates: %s crates and %s p, so merging is %s.',
                in_array($row['rarity'][0], ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A',
                $row['rarity'],
                number_format($row['commons'], 1),
                number_format($row['cost'], 1),
                number_format($row['attempts'], 2),
                number_format($row['crates'], 1),
                number_format($row['crate_cost'], 1),
                $row['cheaper'] ? 'cheaper' : 'dearer',
            ));
        }

        $legendary = end($merge);
        $this->line(sprintf(
            'To a legendary part merging alone takes %s common parts (%s without the protection from bad luck; the design counts about 333 without the bonus of the workshop either), so it is a way next to the crates, not instead of them.',
            number_format($legendary['commons'], 0),
            number_format($legendary['commons_no_pity'], 0),
        ));
        $this->line('Convention: a common part costs p, an attempt costs the fee and uses two parts of the rarity below, valued at what they cost to make, and a failed attempt gives the scrap back.');
    }

    /**
     * @param  array<string, PlayerRun>  $runs
     * @param  list<Finding>  $findings
     * @param  array{runs: array<string, PlayerRun>, report: GateReport, rows: list<LadderRow>, unreachable: array<int, string>}  $game
     * @param  list<array<string, float|string|bool>>  $merge
     * @param  array{model: float, rows: list<array<string, mixed>>, summary: array{min: float, max: float, median: float, model: float, below: list<int>, above: list<int>}, every_km: int}  $upkeep
     * @return array<string, mixed>
     */
    private function asArray(EconomyRules $economy, array $runs, GateReport $report, array $findings, array $game, ExtrasResult $extras, array $merge, array $upkeep): array
    {
        return [
            'ruleset' => $economy->version(),
            'gate' => $this->gateAsArray($report),
            'extras' => [
                'passed' => $extras->passed(),
                'crates' => [
                    'shift_passed' => $extras->crateShiftPassed(),
                    'value_passed' => $extras->crateValuePassed(),
                    'shares' => array_map(fn (array $players, string $share): array => [
                        'share' => (float) $share,
                        'gate' => $this->gateAsArray($extras->crateShift[$share]),
                        'players' => array_map(static fn (PlayerRun $run): array => $run->notes['crates'], $players),
                    ], $extras->crates, array_keys($extras->crates)),
                    'value' => ['player' => $extras->valuedPlayer, 'rows' => $extras->crateValue],
                ],
                'units' => [
                    'shift_passed' => $extras->unitShiftPassed(),
                    'returns_passed' => $extras->unitReturnsPassed(),
                    'shares' => array_map(fn (array $players, string $share): array => [
                        'share' => (float) $share,
                        'gate' => $this->gateAsArray($extras->unitShift[$share]),
                        'players' => array_map(static fn (PlayerRun $run): array => $run->notes['units'], $players),
                    ], $extras->units, array_keys($extras->units)),
                    'returns' => $extras->unitReturns,
                ],
                'sales' => array_map(static fn (PlayerRun $run): array => ['days' => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases)], $extras->sales),
            ],
            'merge' => [
                'convention' => 'Units of p, the price of one part of the model. A common part costs p; an attempt costs the fee and uses two parts of the rarity below, valued at what they cost to make; a failed attempt gives the scrap back. A crate costs p times the price factor, and gives parts of other rarities too.',
                'steps' => array_map(static fn (array $row): array => [
                    'rarity' => $row['rarity'],
                    'chance' => round($row['chance'], 4),
                    'attempts' => round($row['attempts'], 3),
                    'attempts_no_pity' => round($row['attempts_no_pity'], 3),
                    'commons' => round($row['commons'], 2),
                    'commons_no_pity' => round($row['commons_no_pity'], 2),
                    'fees' => round($row['fees'], 3),
                    'scrap' => round($row['scrap'], 3),
                    'cost' => round($row['cost'], 3),
                    'crates' => round($row['crates'], 3),
                    'crate_cost' => round($row['crate_cost'], 3),
                    'ratio' => round($row['ratio'], 4),
                    'merging_cheaper' => $row['cheaper'],
                ], $merge),
            ],
            'upkeep' => [
                'convention' => 'The best pair of car and track of each level of the ladder of the game, with its stock parts. The service costs a share of what the car earns in the kilometres between two services; the shop set is, in tires, suspension, brakes and exhaust, the cheapest model of the highest tier that the level opens, which wears by the kilometres and is put right for half of its price for the whole of its life. A report: no gate.',
                'model_share' => $upkeep['model'],
                'service_every_km' => $upkeep['every_km'],
                'game_share_min' => round($upkeep['summary']['min'], 4),
                'game_share_max' => round($upkeep['summary']['max'], 4),
                'game_share_median' => round($upkeep['summary']['median'], 4),
                'levels_below_model' => $upkeep['summary']['below'],
                'levels_above_model' => $upkeep['summary']['above'],
                'rows' => array_map(static fn (array $row): array => [
                    'level' => $row['level'],
                    'car' => $row['car'],
                    'track' => $row['track'],
                    'net' => round($row['net'], 2),
                    'km_per_hour' => round($row['km_per_hour'], 1),
                    'service' => round($row['service'], 3),
                    'parts' => round($row['parts'], 3),
                    'upkeep' => round($row['upkeep'], 3),
                    'share' => round($row['share'], 4),
                    'set_price' => round($row['set_price'], 2),
                    'set' => array_map(static fn (array $part): array => ['slot' => $part['slot'], 'model' => $part['model'], 'price' => round($part['price'], 2), 'per_hour' => round($part['per_hour'], 3)], $row['set']),
                ], $upkeep['rows']),
            ],
            'game_fleet' => [
                'gate' => $this->gateAsArray($game['report']),
                'players' => array_map(static fn (PlayerRun $run): array => ['name' => $run->player->name, 'wrecks' => $run->wrecks, 'extra_garages' => $run->extraGarages], $game['runs']),
            ],
            'game_ladder' => [
                'rows' => array_map(static fn (LadderRow $row): array => [
                    'level' => $row->level,
                    'rung' => $row->rung,
                    'anchor' => round($row->anchor, 2),
                    'game' => round($row->game, 2),
                    'deviation' => round($row->deviation(), 4),
                    'car' => $row->car,
                    'track' => $row->track,
                    'ok' => $row->ok,
                    'xp_game' => round($row->xp, 1),
                    'xp_model' => round($row->xpModel, 1),
                    'xp_deviation' => round($row->xpDeviation(), 4),
                    'xp_ok' => $row->xpOk,
                ], $game['rows']),
                'unreachable_rungs' => array_values($game['unreachable']),
            ],
            'players' => array_map(static fn (PlayerRun $run): array => ['name' => $run->player->name, 'days_simulated' => $run->daysSimulated], $runs),
            'consistency' => array_map(static fn (Finding $finding): array => [
                'number' => $finding->subject,
                'anchors' => $finding->expected,
                'game' => $finding->actual,
                'unit' => $finding->unit,
                'deviation' => $finding->deviation() === null ? null : round($finding->deviation(), 4),
                'ok' => $finding->ok,
            ], $findings),
        ];
    }

    /** @return array<string, mixed> */
    private function gateAsArray(GateReport $report): array
    {
        return [
            'passed' => $report->passed(),
            'tolerance' => $report->tolerance,
            'slack_days' => $report->slackDays,
            'rows' => array_map(static fn (GateRow $row): array => [
                'player' => $row->player,
                'car' => $row->rung,
                'target_day' => $row->target,
                'day' => $row->day,
                'deviation' => $row->deviation() === null ? null : round($row->deviation(), 4),
                'waited_for' => $row->binding,
                'passed' => $row->passed,
            ], $report->rows),
        ];
    }

    private function money(float $dollars): string
    {
        return '$'.number_format($dollars, 0, '.', ' ');
    }

    /** A number without the zeros after the point that say nothing. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
