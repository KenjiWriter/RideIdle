<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInAGame;
use App\Services\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** The numbers of the alpha: do the drivers come back, how long do they stay, and do they get to what the first minutes lead to. Worked out from the game's own data. */
final class MetricsReport extends Command
{
    use RunsInAGame;

    protected $signature = 'metrics:report
        {--days=30 : The accounts made in this many last days make the cohort}
        {--json : Print the report as JSON}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Tell whether the drivers come back (D1, D2, D3, D7), how long they stay and how far the first minutes take them';

    public function handle(Metrics $metrics): int
    {
        $days = (string) $this->option('days');

        if (! ctype_digit($days) || (int) $days < 1 || (int) $days > 3650) {
            $this->error('The days are a whole number from 1 to 3650.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $report = $metrics->report($now->startOfDay()->subDays((int) $days - 1), $now->addSecond(), $now);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $cohort = $report['cohort'];
        $this->info("Accounts made from {$cohort['from']} to {$cohort['to']}: {$cohort['accounts']} ({$cohort['secured']} secured, {$cohort['guests']} guests).");

        $this->newLine();
        $this->line('Retention: of the accounts for which the day is over, how many came on that day after the day they were made');
        $this->table(['day', 'eligible', 'returned', 'rate'], collect($report['retention'])->map(fn (array $row, string $day): array => [strtoupper($day), $row['eligible'], $row['returned'], $this->percent($row['rate'])])->values()->all());

        $sessions = $report['sessions'];
        $this->line("Sessions: {$sessions['sessions']} of {$sessions['drivers']} drivers who came, ".($sessions['per_driver'] ?? '-').' a driver, the median is '.($sessions['median_minutes'] ?? '-').' minutes (visits less than '.Metrics::SESSION_BREAK_MINUTES.' minutes apart are one session; the length is counted from the first visit to the last, and a visit is at least '.Metrics::VISIT_MINUTES.' minutes).');

        $this->newLine();
        $this->line('The first minutes of the cohort');
        $this->table(
            ['step', 'did it', 'rate', 'median (min)', 'within the target'],
            collect($report['first_steps'])->map(fn (array $row, string $step): array => [$step, $row['reached'], $this->percent($row['rate']), $row['median_minutes'] ?? '-', "{$row['within_target']} within {$row['target_minutes']} min"])->values()->all(),
        );
        $this->table(['tutorial', 'reached', 'rate'], collect($report['tutorial'])->map(fn (array $row, string $step): array => [str_replace('_', ' ', $step), $row['reached'], $this->percent($row['rate'])])->values()->all());

        $this->newLine();
        $this->line('Who came on each of the last fourteen days (all accounts)');
        $this->table(['day', 'active', 'new', 'returning'], $report['activity']);

        $this->newLine();
        $this->line("The missions of the day (all accounts); the bonuses gave {$report['missions']['coins_30_days']} premium coins in thirty days");
        $this->table(['day', 'sets drawn', 'done whole', 'bonus taken'], $report['missions']['days']);

        return self::SUCCESS;
    }

    private function percent(?float $rate): string
    {
        return $rate === null ? '-' : number_format($rate * 100, 1).'%';
    }
}
