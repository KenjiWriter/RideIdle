<?php

declare(strict_types=1);

use App\Domain\Run\GameClock;
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

        $response = getRun($driver)->assertOk()
            ->assertJsonPath('data.weather.now', $climate->at($segment))
            ->assertJsonPath('data.weather.ends_ms', ($segment + 1) * Settler::SEGMENT_MS)
            ->assertJsonPath('data.weather.next', $climate->at($segment + 1));

        // The forecast is this segment and the four after it, drawn from the same seed, each with the surface it makes of the track.
        $forecast = $response->json('data.weather.forecast');

        expect($forecast)->toHaveCount(5)
            ->and($forecast[0])->toBe(['weather' => $climate->at($segment), 'starts_ms' => $segment * Settler::SEGMENT_MS, 'ends_ms' => ($segment + 1) * Settler::SEGMENT_MS, 'surface' => $climate->at($segment) === 'rain' ? 'asphalt_wet' : 'asphalt_dry'])
            ->and(array_column($forecast, 'weather'))->toBe(array_map(fn (int $ahead): string => $climate->at($segment + $ahead), range(0, 4)));

        foreach ($forecast as $index => $entry) {
            expect($entry['surface'])->toBe($entry['weather'] === 'rain' ? 'asphalt_wet' : 'asphalt_dry')
                ->and($entry['ends_ms'] - $entry['starts_ms'])->toBe(Settler::SEGMENT_MS);

            if ($index > 0) {
                expect($entry['starts_ms'])->toBe($forecast[$index - 1]['ends_ms']);
            }
        }
    });

    it('tells the clock of the game: three hours for a day, and where its phases begin', function () {
        [$driver] = driverWithRun();

        getRun($driver)->assertOk()
            ->assertJsonPath('data.clock.day_ms', 10_800_000)
            ->assertJsonPath('data.clock.phases', [
                ['key' => 'dawn', 'from_hour' => 5],
                ['key' => 'day', 'from_hour' => 8],
                ['key' => 'dusk', 'from_hour' => 18],
                ['key' => 'night', 'from_hour' => 22],
            ]);
    });

    it('takes a lap that starts at night on a track that is not lit 3% longer, and says by how much a night lap differs', function () {
        // The day of the game begins every 3 hours of the epoch, so T0 + 23 hours of the game is night.
        $night = intdiv(RUN_T0, GameClock::DAY_MS) * GameClock::DAY_MS + 23 * GameClock::HOUR_MS;
        [$driver] = driverWithRun(['cursor_ms' => $night, 'config' => [...RaceRunFactory::constantConfig(), 'night_lap_ppm' => 1_030_000]]);
        Carbon::setTestNow(Carbon::createFromTimestampMs($night + 10_000));

        getRun($driver)->assertOk()
            ->assertJsonPath('data.lap.night_ppm', 1_030_000)
            ->assertJsonPath('data.current_lap.ms', 77_250)
            ->assertJsonPath('data.current_lap.finish_ms', $night + 77_250);
    });

    it('keeps the day lap on a track that is lit, and for a run frozen before the night', function () {
        $night = intdiv(RUN_T0, GameClock::DAY_MS) * GameClock::DAY_MS + 23 * GameClock::HOUR_MS;
        [$driver] = driverWithRun(['cursor_ms' => $night]);
        Carbon::setTestNow(Carbon::createFromTimestampMs($night + 10_000));

        getRun($driver)->assertOk()
            ->assertJsonPath('data.lap.night_ppm', 1_000_000)
            ->assertJsonPath('data.current_lap.ms', 75_000);
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
