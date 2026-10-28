<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\Tracks\Build;
use App\Content\Tracks\SurfaceOutcome;
use App\Content\Tracks\TrackOutcome;
use App\Content\Tracks\TrackReport;
use App\Content\Tracks\TrackSimulator;
use App\Domain\Run\Surface;
use Illuminate\Console\Command;

/**
 * The track simulator (gate M3 of the roadmap): the best build on every track of the rules, and whether the tracks ask for
 * different cars. It reads the rules and the pack of the game and freezes every build the way a run is frozen, so it says what the
 * game does, not what a model of it would. The exit code is the answer: 1 when the data is not coherent (a tire that is best on
 * the wrong surface, a gravel track won by the car of an asphalt one), and with --strict also when one car wins more than two tracks.
 */
final class SimulateTracks extends Command
{
    protected $signature = 'tracks:simulate
        {--json : Print the report as JSON}
        {--strict : Fail also when a car wins more than two tracks (the gate M3)}';

    protected $description = 'Find the best build on every track and check that the tires, the terrain and the cars ask for different choices (the gate M3)';

    public function handle(TrackSimulator $simulator): int
    {
        $report = $simulator->simulate();

        if ($this->option('json')) {
            $this->line((string) json_encode($this->asArray($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->print($report);
        }

        return $report->passed() && (! $this->option('strict') || $report->m3Passed()) ? self::SUCCESS : self::FAILURE;
    }

    private function print(TrackReport $report): void
    {
        $this->line("Tracks of the rules {$report->rulesVersion}");
        $this->newLine();

        $this->line('Every tire of the pack on every surface, on one car with nothing else changed (the lap in seconds, the best marked):');
        $this->printSurfaces($report);

        $this->newLine();
        $this->line('The best build on every track, by what it earns an hour in the climate of the region (parts up to the tier of the level of the track):');
        $this->table(
            ['track', 'level', 'tier', 'builds', 'car', 'parts', '$/h', 'runner-up', 'best dry', 'best rain'],
            array_map(fn (TrackOutcome $track): array => [
                $track->track,
                $track->minPlayerLevel,
                $track->tierCap,
                $track->candidates,
                $track->winner->vehicle,
                implode(', ', $track->winner->mounted) ?: '-',
                number_format($track->centsPerHour / 100, 0),
                $this->runnerUp($track),
                $this->fastest($track, 'dry'),
                $this->fastest($track, 'rain'),
            ], $report->tracks),
        );

        $this->newLine();
        $this->gate($report->tiresPassed(), 'Tires: the best tires on every surface are the kind of the design, so the rain changes the winner.', 'Tires: a kind of tires is the best on a surface where the design says another is (see the table above).');
        $this->gate($report->terrainPassed(), 'Terrain: no car that wins a gravel track wins an asphalt track.', 'Terrain: the same car wins a gravel track and an asphalt track, so off-road asks for nothing of a car.');

        $this->m3($report);
    }

    private function printSurfaces(TrackReport $report): void
    {
        if ($report->surfaces === []) {
            $this->warn('No surface to try: the rules have no track, or the pack no tires.');

            return;
        }

        $tires = array_keys($report->surfaces[0]->lapMsByTires);
        $rows = [];

        foreach ($tires as $slug) {
            $rows[] = [
                $slug.' ('.$report->surfaces[0]->kindByTires[$slug].')',
                ...array_map(
                    fn (SurfaceOutcome $surface): string => number_format($surface->lapMsByTires[$slug] / 1000, 1).($surface->bestTires() === $slug ? ' *' : ''),
                    $report->surfaces,
                ),
            ];
        }

        $this->table(['tires', ...array_map(static fn (SurfaceOutcome $surface): string => $surface->surface.' ('.$surface->track.', '.$surface->weather.')', $report->surfaces)], $rows);

        foreach ($report->tireFindings() as $finding) {
            if (! $finding['ok']) {
                $this->warn("{$finding['surface']}: the best are {$finding['best']} ({$finding['kind']}), and the design says {$finding['expected']}.");
            }
        }
    }

    private function m3(TrackReport $report): void
    {
        $summary = implode('; ', array_map(
            static fn (string $kind, array $byCar): string => "{$kind}: ".implode(', ', array_map(static fn (string $car, array $tracks): string => "{$car} wins ".count($tracks).' ('.implode(', ', $tracks).')', array_keys($byCar), $byCar)),
            array_keys($report->winsByKind()),
            $report->winsByKind(),
        ));

        if ($report->m3Passed()) {
            $this->info("Gate M3: PASSED. No car wins more than {$this->most()} tracks of a kind. {$summary}.");

            return;
        }

        $this->warn("Gate M3: NOT YET. {$summary}.");
        $this->line('  The tracks do not yet ask for different cars: one car is the best on more than '.$this->most().' tracks of one kind. That is what the cars of the pack allow,');
        $this->line('  and it is the answer of the gate, not a fault of the numbers: a car that is better in everything that a track weighs wins it, and the cars');
        $this->line('  that trade power for something else (weight, drivetrain) are what the catalog is for. This fails the command only with --strict.');
    }

    private function gate(bool $passed, string $ok, string $failed): void
    {
        $passed ? $this->info('Gate passed. '.$ok) : $this->error('Gate FAILED. '.$failed);
    }

    private function runnerUp(TrackOutcome $track): string
    {
        $next = $track->runnerUp();

        return $next === null ? '-' : sprintf('%s -%.1f%%', $next['car'], $next['behind'] * 100);
    }

    private function fastest(TrackOutcome $track, string $weather): string
    {
        $fastest = $track->fastest[$weather] ?? null;

        return $fastest === null ? '-' : number_format($fastest['lap_ms'] / 1000, 1).' s '.$this->tiresOf($fastest['build']);
    }

    private function tiresOf(Build $build): string
    {
        return $build->vehicle.($build->mounted['tires'] ?? false ? ' on '.$build->mounted['tires'] : '');
    }

    private function most(): int
    {
        return TrackReport::MOST_TRACKS_PER_CAR;
    }

    /** @return array<string, mixed> */
    private function asArray(TrackReport $report): array
    {
        return [
            'rules' => $report->rulesVersion,
            'surfaces' => array_map(static fn (SurfaceOutcome $surface): array => [
                'surface' => $surface->surface,
                'track' => $surface->track,
                'weather' => $surface->weather,
                'car' => $surface->vehicle,
                'lap_ms' => $surface->lapMsByTires,
                'best' => $surface->bestTires(),
                'best_kind' => $surface->bestKind(),
                'design_kind' => TrackReport::EXPECTED_TIRES[$surface->surface] ?? null,
            ], $report->surfaces),
            'tracks' => array_map(static fn (TrackOutcome $track): array => [
                'track' => $track->track,
                'surface' => $track->surface,
                'min_player_level' => $track->minPlayerLevel,
                'tier_cap' => $track->tierCap,
                'builds' => $track->candidates,
                'car' => $track->winner->vehicle,
                'parts' => $track->winner->mounted,
                'cents_per_hour' => round($track->centsPerHour, 2),
                'cents_per_hour_by_car' => array_map(static fn (float $cents): float => round($cents, 2), $track->centsPerHourByCar),
                'lap_ms' => $track->lapMs,
                'fastest' => array_map(static fn (array $fastest): array => ['car' => $fastest['build']->vehicle, 'parts' => $fastest['build']->mounted, 'lap_ms' => $fastest['lap_ms']], $track->fastest),
            ], $report->tracks),
            'gates' => [
                'tires' => $report->tiresPassed(),
                'terrain' => $report->terrainPassed(),
                'no_car_wins_more_than_two' => $report->m3Passed(),
            ],
            'wins' => $report->winsByCar(),
            'wins_by_kind' => $report->winsByKind(),
            'surface_names' => Surface::ALL,
        ];
    }
}
