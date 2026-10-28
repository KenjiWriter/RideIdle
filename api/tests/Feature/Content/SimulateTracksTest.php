<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\Tracks\TrackReport;
use App\Content\Tracks\TrackSimulator;
use App\Domain\Run\Surface;

/**
 * The simulator on rules of its own: the shipped ones, changed by `$change`, and with only the tracks that are asked for.
 *
 * @param  list<string>|null  $tracks
 */
function simulateTracks(?array $tracks = null, ?Closure $change = null): TrackReport
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true);

    if ($tracks !== null) {
        $data['tracks'] = array_intersect_key($data['tracks'], array_flip($tracks));
    }

    if ($change !== null) {
        $data = $change($data);
    }

    $rules = Rules::fromArray($data);
    $catalog = app(Catalog::class);

    return (new TrackSimulator($catalog, $rules, new RunInputsFactory($catalog, $rules)))->simulate();
}

/** @return array<string, mixed> the report of the command on the shipped rules */
function trackReportJson(): array
{
    expect(Artisan::call('tracks:simulate', ['--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

describe('the track simulator on the shipped rules', function () {
    it('finds a winner for every track, with parts that a driver of the level of the track could have', function () {
        $report = trackReportJson();
        $parts = app(Catalog::class)->parts();

        expect($report['tracks'])->toHaveCount(count(Rules::fromConfig()->tracks()));

        foreach ($report['tracks'] as $track) {
            expect($track['builds'])->toBeGreaterThan(1);

            foreach ($track['parts'] as $slug) {
                expect($parts[$slug]['tier'])->toBeLessThanOrEqual($track['tier_cap'], "{$track['track']}: {$slug}");
            }
        }
    });

    it('lets the tires of the design win the surfaces, so that the rain changes the winner, and makes gravel ask for another car', function () {
        $report = trackReportJson();
        $best = array_column($report['surfaces'], 'best_kind', 'surface');

        expect($report['gates']['tires'])->toBeTrue()
            ->and($report['gates']['terrain'])->toBeTrue()
            ->and($best)->toBe(TrackReport::EXPECTED_TIRES);

        $gravel = collect($report['tracks'])->firstWhere('surface', 'gravel');
        $asphalt = collect($report['tracks'])->firstWhere('surface', 'asphalt');

        expect($gravel['car'])->not->toBe($asphalt['car']);
    });

    it('prints the tables and the gates', function () {
        $this->artisan('tracks:simulate')
            ->expectsOutputToContain('Every tire of the pack on every surface')
            ->expectsOutputToContain('The best build on every track')
            ->expectsOutputToContain('Gate passed. Tires')
            ->expectsOutputToContain('Gate passed. Terrain')
            ->expectsOutputToContain('Gate M3')
            ->assertSuccessful();
    });

    it('fails with --strict exactly when the gate M3 does, and not otherwise', function () {
        $m3 = trackReportJson()['gates']['no_car_wins_more_than_two'];

        $this->artisan('tracks:simulate', ['--strict' => true])->assertExitCode($m3 ? 0 : 1);
        $this->artisan('tracks:simulate')->assertSuccessful();
    });
});

describe('the gates of the track simulator', function () {
    it('fails the gate of the tires when a kind of tires wins a surface that is not its own', function () {
        // Slick tires that grip twice as well on wet asphalt win the wet as well, so the rain no longer changes the winner.
        $report = simulateTracks(['zlomowo-street'], function (array $data): array {
            $data['tire_matrix']['slick']['asphalt_wet'] = 2_000_000;

            return $data;
        });

        $findings = array_column($report->tireFindings(), 'ok', 'surface');

        expect($report->tiresPassed())->toBeFalse()
            ->and($report->passed())->toBeFalse()
            ->and($findings[Surface::ASPHALT_DRY])->toBeTrue()
            ->and($findings[Surface::ASPHALT_WET])->toBeFalse();
    });

    it('fails the gate of the terrain when the car of the asphalt wins the gravel too', function () {
        // A gravel track that asks the same of a car as the street sprint does is won by the same car.
        $report = simulateTracks(['zlomowo-street', 'kruszyn-quarry'], function (array $data): array {
            $data['tracks']['kruszyn-quarry']['demand'] = $data['tracks']['zlomowo-street']['demand'];
            $data['tracks']['kruszyn-quarry']['reference'] = $data['tracks']['zlomowo-street']['reference'];

            return $data;
        });

        expect($report->terrainPassed())->toBeFalse()
            ->and($report->passed())->toBeFalse();
    });

    it('fails the gate M3 when one car wins more than two tracks, and says which', function () {
        // Three asphalt tracks that ask what a drag strip asks are won by the car that accelerates best, whatever else they are called.
        $report = simulateTracks(['zlomowo-street', 'suchy-pas-drag', 'wilcza-przelecz-switchbacks'], function (array $data): array {
            foreach (['zlomowo-street', 'wilcza-przelecz-switchbacks'] as $track) {
                $data['tracks'][$track]['demand'] = $data['tracks']['suchy-pas-drag']['demand'];
                $data['tracks'][$track]['reference'] = $data['tracks']['suchy-pas-drag']['reference'];
            }

            return $data;
        });

        expect($report->m3Passed())->toBeFalse()
            ->and(array_keys($report->carsThatWinTooMuch()))->toBe(['bizon-m-2 (standard)'])
            ->and($report->carsThatWinTooMuch()['bizon-m-2 (standard)'])->toBe(['zlomowo-street', 'suchy-pas-drag', 'wilcza-przelecz-switchbacks'])
            // The data is coherent all the same: the command fails on this only with --strict.
            ->and($report->passed())->toBeTrue();
    });

    it('passes the gate M3 on the shipped rules: the weight of a car is what sends the tracks to different cars', function () {
        $wins = array_map('count', trackReportJson()['wins_by_kind']['standard']);

        expect(trackReportJson()['gates']['no_car_wins_more_than_two'])->toBeTrue()
            // With the cars of the dealer and the exchange the wins are spread over four cars: the lightest Rdzak takes the track in the snow, and the Kornet R6,
            // which asks level 12 like the Autodrom does, the circuit (by a fraction of a per cent over the Bizon V8: its rear wheel drive is what the two
            // differ in).
            ->and($wins)->toBe(['bizon-m-2' => 2, 'kuna-4x4' => 1, 'rdzak-gt-2' => 1, 'kornet-r6' => 1])
            // The sprints and the endurance tracks are counted among themselves: the Bizon M-2 wins the one of Złomowo in each, another car the one of the Autodrom.
            ->and(array_map('count', trackReportJson()['wins_by_kind']['sprint']))->toBe(['bizon-m-2' => 1, 'bizon-v8' => 1])
            ->and(array_map('count', trackReportJson()['wins_by_kind']['endurance']))->toBe(['bizon-m-2' => 1, 'serwal-gt' => 1]);

        $this->artisan('tracks:simulate', ['--strict' => true])->assertSuccessful();
    });

    it('says which car comes second on a track and how far behind it is', function () {
        $report = simulateTracks(['zlomowo-street', 'kruszyn-quarry']);

        foreach ($report->tracks as $track) {
            $next = $track->runnerUp();

            expect($next['car'])->not->toBe($track->winner->vehicle)
                ->and($next['behind'])->toBeGreaterThan(0.0)
                ->and($next['behind'])->toBeLessThan(1.0);
        }
    });

    it('passes the gate M3 for tracks that are won by different cars', function () {
        $report = simulateTracks(['zlomowo-street', 'kruszyn-quarry']);

        expect($report->m3Passed())->toBeTrue()
            ->and($report->winsByCar())->toBe(['bizon-m-2' => ['zlomowo-street'], 'kuna-4x4' => ['kruszyn-quarry']]);
    });

    it('breaks a tie between builds that drive the same lap by what the parts cost', function () {
        // A gearbox that caps the top speed above what the car reaches changes nothing about the lap, so it is never a reason to pay.
        $report = simulateTracks(['kruszyn-quarry']);
        $winner = $report->tracks[0]->winner;

        expect($winner->mounted)->not->toHaveKey('gearbox');
    });

    it('allows a car and a part only to the level that asks for them', function () {
        $report = simulateTracks(['zlomowo-street']);

        // Złomowo is level 1, and a new car starts at level 3, which mounts tier 2: the rain tires of tier 3 are out of reach there.
        expect($report->tracks[0]->tierCap)->toBe(2)
            ->and($report->tracks[0]->winner->mounted['tires'])->not->toBe('rain-tires-t3');
    });
});
