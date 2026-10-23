<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\Finding;
use App\Domain\Economy\GateReport;
use App\Domain\Economy\GateRow;
use App\Domain\Economy\PaceGate;
use App\Domain\Economy\PlayerRun;
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
        {--strict : Fail also when a number of the rules of the game is off the anchors}';

    protected $description = 'Simulate three kinds of players up the ladder of cars and check the pace against the design (the gate), and the rules of the game against the anchors';

    public function handle(Rules $game, Catalog $catalog, Simulator $simulator, PaceGate $gate, RulesConsistency $consistency): int
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

        if ($this->option('json')) {
            $this->line((string) json_encode($this->asArray($economy, $runs, $report, $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->print($economy, $runs, $report, $findings);
        }

        return $report->passed() && (! $this->option('strict') || $off === []) ? self::SUCCESS : self::FAILURE;
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
     */
    private function print(EconomyRules $economy, array $runs, GateReport $report, array $findings): void
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
            $this->table(
                ['car', 'target day', 'day', 'off', 'waited for', 'gate'],
                array_map(fn (GateRow $row): array => [
                    $row->rung,
                    $row->target,
                    $row->day ?? 'never',
                    $row->deviation() === null ? '-' : sprintf('%+.0f%%', $row->deviation() * 100),
                    $row->binding ?? '-',
                    $row->passed ? 'ok' : 'OUT',
                ], array_values(array_filter($report->rows, static fn (GateRow $row): bool => $row->player === (string) $key))),
            );
        }

        $failures = $report->failures();
        $this->newLine();
        $tolerance = sprintf('%.0f%%', $report->tolerance * 100);

        if ($failures === []) {
            $this->info("Pace gate: PASSED. Every day is within {$tolerance} of the target (or within {$this->trim($report->slackDays)} day of it, for a target of a day or two).");
        } else {
            $this->error('Pace gate: FAILED. '.count($failures).' of '.count($report->rows)." days are out of {$tolerance} of the target.");
        }

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

    /**
     * @param  array<string, PlayerRun>  $runs
     * @param  list<Finding>  $findings
     * @return array<string, mixed>
     */
    private function asArray(EconomyRules $economy, array $runs, GateReport $report, array $findings): array
    {
        return [
            'ruleset' => $economy->version(),
            'gate' => [
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
