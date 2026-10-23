<?php

declare(strict_types=1);

use App\Domain\Run\SeededWeather;
use App\Domain\Settlement\Settler;
use App\Models\RaceRun;
use Database\Factories\RaceRunFactory;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * A run in a climate of its own: the weather is drawn from the seed in segments of 22.5 minutes, with a lap time, a
 * reward and XP for each weather that can come.
 *
 * @param  array<string, int>  $chancesPpm
 * @return array<string, mixed>
 */
function climateConfig(string $seed, array $chancesPpm): array
{
    return [
        ...RaceRunFactory::constantConfig(),
        'lap_ms' => ['dry' => 75_000, 'rain' => 84_000],
        'lap_cash_cents' => ['dry' => 1_550, 'rain' => 1_440],
        'lap_xp' => ['dry' => 25, 'rain' => 23],
        'weather' => ['seed' => $seed, 'chances_ppm' => $chancesPpm],
    ];
}

describe('the live view of a run', function () {
    it('tells when the lap in progress started and ends, and in what weather', function () {
        [$driver] = driverWithRun();
        clockAt(10_000);

        getRun($driver)->assertOk()
            ->assertJsonPath('data.current_lap', ['start_ms' => RUN_T0, 'finish_ms' => RUN_T0 + 75_000, 'ms' => 75_000, 'weather' => 'dry'])
            ->assertJsonPath('data.server_time_ms', RUN_T0 + 10_000);
    });

    it('starts the lap in progress where the last one ended', function () {
        [$driver] = driverWithRun();
        clockAt(100_000);

        // One lap is done (75 s), and the next began at that moment.
        getRun($driver)->assertOk()
            ->assertJsonPath('data.laps_done', 1)
            ->assertJsonPath('data.current_lap.start_ms', RUN_T0 + 75_000)
            ->assertJsonPath('data.current_lap.finish_ms', RUN_T0 + 150_000);
    });

    it('has no lap in progress for a car that stands', function (array $run) {
        [$driver] = driverWithRun($run);
        clockAt(10_000);

        getRun($driver)->assertOk()->assertJsonPath('data.current_lap', null);
    })->with([
        'after a breakdown' => [['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']],
        'with an empty tank' => [['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'fuel_empty', 'fuel_ml' => 0]],
        'in the workshop' => [['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_WORKSHOP]],
    ]);

    it('has no lap in progress for a car that waits for a tow, whose start is still ahead', function () {
        [$driver] = driverWithRun(['cursor_ms' => RUN_T0 + 300_000]);
        clockAt(10_000);

        getRun($driver)->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.current_lap', null);
    });

    it('tells the weather now, until when it lasts and what comes after, as the climate draws it', function () {
        $chances = ['dry' => 500_000, 'rain' => 500_000];
        [$driver] = driverWithRun(['config' => climateConfig('region-test', $chances)]);
        clockAt(10_000);

        $segment = intdiv(RUN_T0 + 10_000, Settler::SEGMENT_MS);
        $climate = new SeededWeather('region-test', $chances);

        getRun($driver)->assertOk()->assertJsonPath('data.weather', [
            'now' => $climate->at($segment),
            'ends_ms' => ($segment + 1) * Settler::SEGMENT_MS,
            'next' => $climate->at($segment + 1),
        ]);
    });

    it('takes the lap time of the weather the lap started in', function () {
        // Rain all the time: the lap takes the rain lap time, which the dry one is not.
        [$driver] = driverWithRun(['config' => climateConfig('region-test', ['rain' => 1_000_000])]);
        clockAt(10_000);

        getRun($driver)->assertOk()
            ->assertJsonPath('data.weather.now', 'rain')
            ->assertJsonPath('data.current_lap', ['start_ms' => RUN_T0, 'finish_ms' => RUN_T0 + 84_000, 'ms' => 84_000, 'weather' => 'rain']);
    });
});
