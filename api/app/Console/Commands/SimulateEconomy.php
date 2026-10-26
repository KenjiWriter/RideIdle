<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\Catalog;
use App\Content\GameCrates;
use App\Content\GameIncome;
use App\Content\GameUnits;
use App\Content\Rules;
use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\ExtrasResult;
use App\Domain\Economy\ExtrasStudy;
use App\Domain\Economy\Finding;
use App\Domain\Economy\GameLadder;
use App\Domain\Economy\GateReport;
use App\Domain\Economy\GateRow;
use App\Domain\Economy\LadderRow;
use App\Domain\Economy\PaceGate;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\RulesConsistency;
use App\Domain\Economy\Simulator;
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
 */
final class SimulateEconomy extends Command
{
    /** The exit code for a ruleset that cannot be read, which is not the same as one that does not pass. */
    private const INVALID_INPUT = 2;

    private const SCHEMA_BASE = 'https://rideidle.local/schema/';

    protected $signature = 'economy:simulate
        {--ruleset= : The ruleset to play, a file of the shape of content/rules/economy.json (that file when left out)}
        {--player=* : Play only these kinds of players, by their keys (A, B or C)}
        {--json : Print the report as JSON}
        {--strict : Fail also when a number of the rules of the game is off the anchors}
        {--strict-game : Fail also when the fleet of the game is out of the pace of the design, or the ladder of the game is off the anchors}';

    protected $description = 'Simulate three kinds of players up the ladder of cars and check the pace against the design (the gate), and the rules of the game against the anchors';

    public function handle(Rules $game, Catalog $catalog, Simulator $simulator, PaceGate $gate, RulesConsistency $consistency, GameLadder $ladder, GameIncome $income, ExtrasStudy $study, GameCrates $crates, GameUnits $units): int
    {
        $path = (string) ($this->option('ruleset') ?: config('ride.content_path').'/rules/economy.json');

        try {
            $economy = EconomyRules::fromArray($this->read($path));
        } catch (InvalidArgumentException $problem) {
            $this->error($problem->getMessage());

            return self::INVALID_INPUT;
        }

        $runs = $this->runs($economy, $simulator);

        if ($runs === null) {
            return self::INVALID_INPUT;
        }

        $report = $gate->evaluate($economy, $runs);
        $findings = $consistency->check($economy, $this->numbersOf($game, $catalog));
        $off = array_values(array_filter($findings, static fn (Finding $finding): bool => ! $finding->ok));

        $fleetRuns = array_intersect_key($simulator->simulateAllGame($economy), $runs);
        $fleetReport = $gate->evaluate($economy, $fleetRuns);
        $incomeByLevel = $income->byLevel($ladder->levels($economy));
        $rows = $ladder->check($economy, $incomeByLevel);
        $unreachable = $ladder->unreachableRungs($economy, $incomeByLevel, $economy->consistencyTolerance());

        $game = ['runs' => $fleetRuns, 'report' => $fleetReport, 'rows' => $rows, 'unreachable' => $unreachable];
        $extras = $study->run($economy, $crates->economy(), $units->economy(), array_map('strval', array_keys($runs)));
        $gameOk = $fleetReport->passed() && $unreachable === [] && array_filter($rows, static fn (LadderRow $row): bool => ! $row->ok) === [] && $extras->passed();

        if ($this->option('json')) {
            $this->line((string) json_encode($this->asArray($economy, $runs, $report, $findings, $game, $extras), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->print($economy, $runs, $report, $findings, $game, $extras);
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
     * @return array{lap_ms: int, fuel_ml: int, fuel_price_cents: int, reward_cents: int, xp_per_lap: int, parts: list<array{slug: string, tier: int, slot: string, price_cents: int}>}
     */
    private function numbersOf(Rules $game, Catalog $catalog): array
    {
        $track = $game->track($game->startingTrack()) ?? [];

        return [
            'lap_ms' => (int) $track['base_lap_ms'],
            'fuel_ml' => (int) $track['fuel_per_lap_ml'],
            'fuel_price_cents' => $game->fuelPriceCentsPerLiter(),
            'reward_cents' => (int) $track['reward_cents_per_lap'],
            'xp_per_lap' => (int) $track['xp_per_lap'],
            'parts' => array_values(array_map(
                static fn (array $part): array => ['slug' => (string) $part['slug'], 'tier' => (int) $part['tier'], 'slot' => (string) $part['slot'], 'price_cents' => (int) $part['price_cents']],
                $catalog->parts(),
            )),
        ];
    }

    /**
     * @param  array<string, PlayerRun>  $runs
     * @param  list<Finding>  $findings
     * @param  array{runs: array<string, PlayerRun>, report: GateReport, rows: list<LadderRow>, unreachable: array<int, string>}  $game
     */
    private function print(EconomyRules $economy, array $runs, GateReport $report, array $findings, array $game, ExtrasResult $extras): void
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

        $this->newLine();
        $this->line('The rules of the game against the anchors (tolerance '.sprintf('%.0f%%', $economy->consistencyTolerance() * 100).'):');
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
        $this->newLine();
        $this->line('The game scenario: the fleet that the rules of the game allow (every car drives and gives '.$this->trim($fleet->otherCarsXpShare * 100).'% of its experience to the player, a wreck is $'.$this->trim($fleet->wreckPrice).', a garage $'.$this->trim($fleet->garagePrice).' for '.$fleet->garageCapacity.' places):');

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
        $this->line('The ladder of the game: the best net $/h that its cars and tracks reach at a level, against the anchor of the rung of that level (tolerance '.sprintf('%.0f%%', $economy->consistencyTolerance() * 100).'):');
        $this->table(
            ['level', 'rung', 'anchors $/h', 'game $/h', 'off', 'best', ''],
            array_map(fn (LadderRow $row): array => [
                $row->level,
                $row->rung,
                $this->money($row->anchor),
                $this->money($row->game),
                sprintf('%+.0f%%', $row->deviation() * 100),
                "{$row->car} on {$row->track}",
                $row->ok ? 'ok' : 'OFF',
            ], $game['rows']),
        );

        $off = count(array_filter($game['rows'], static fn (LadderRow $row): bool => ! $row->ok));

        if ($game['unreachable'] !== []) {
            $this->warn('No car and track of the game reach '.implode(', ', $game['unreachable']).'.');
        }

        $off === 0 && $game['unreachable'] === []
            ? $this->info('Ladder of the game: every level is within the anchors.')
            : $this->warn("Ladder of the game: {$off} of ".count($game['rows']).' levels are off the anchors. This does not fail the command, except with --strict-game.');
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
        $this->line("What the first crate pays back, in days, against the next car of the ladder (player {$extras->valuedPlayer}; a crate that pays back sooner than a car is a better deal than the car):");
        $this->table(
            ['rung', 'level', 'crate $', 'first crate', 'after 10', 'after 40', 'next car', ''],
            array_map(fn (array $row): array => [
                $row['rung'],
                $row['level'],
                $row['price'] === null ? '-' : $this->money($row['price']),
                ...array_map(fn (array $crate): string => $crate['crate_days'] === null ? '-' : number_format($crate['crate_days'], 1), $row['crates']),
                $row['step_days'] === null ? '-' : number_format($row['step_days'], 1),
                $row['crate_pays_back_sooner'] ? 'OUT' : 'ok',
            ], $extras->crateValue),
        );

        $sooner = array_map(static fn (array $row): string => $row['rung'], array_filter($extras->crateValue, static fn (array $row): bool => $row['crate_pays_back_sooner']));
        $extras->crateValuePassed()
            ? $this->info('Crate value gate: PASSED. A crate never pays back sooner than a car.')
            : $this->warn('Crate value gate: FAILED. A crate pays back sooner than the next car at '.implode(', ', $sooner).'. This does not fail the command, except with --strict-game.');

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
     * @param  array<string, PlayerRun>  $runs
     * @param  list<Finding>  $findings
     * @param  array{runs: array<string, PlayerRun>, report: GateReport, rows: list<LadderRow>, unreachable: array<int, string>}  $game
     * @return array<string, mixed>
     */
    private function asArray(EconomyRules $economy, array $runs, GateReport $report, array $findings, array $game, ExtrasResult $extras): array
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
