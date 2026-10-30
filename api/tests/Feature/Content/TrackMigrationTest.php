<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\TrackConverter;
use App\Content\TrackNumbers;
use App\Domain\Run\RunFreezer;

// The tracks of the game were made by hand (the lap, the fuel, the pay and the XP of a lap), and are made by the model now. The game must not notice: this is what every
// track gave, and what every car of the pack is frozen into on it.

/**
 * The four numbers that every track had by hand: the length, the kind, the lap, the fuel, what a lap pays and what it gives.
 *
 * @var array<string, array{0: int, 1: string, 2: int, 3: int, 4: int, 5: int}>
 */
const TRACKS_BY_HAND = [
    'zlomowo-street' => [2_600, 'standard', 75_000, 1_500, 1_550, 20],
    'kruszyn-quarry' => [2_400, 'standard', 95_000, 1_900, 2_200, 20],
    'suchy-pas-drag' => [1_600, 'standard', 45_000, 900, 1_170, 10],
    'wilcza-przelecz-switchbacks' => [2_900, 'standard', 85_000, 1_700, 2_500, 20],
    'autodrom-circuit' => [3_200, 'standard', 80_000, 1_600, 2_650, 20],
    'zlomowo-yard-dash' => [420, 'sprint', 12_000, 144, 200, 3],
    'zlomowo-ring-road' => [10_400, 'endurance', 300_000, 9_600, 6_300, 72],
    'autodrom-short-loop' => [520, 'sprint', 13_000, 156, 346, 3],
    'autodrom-endurance' => [14_400, 'endurance', 360_000, 11_520, 11_140, 81],
    'kruszyn-gravel-sprint' => [380, 'sprint', 15_000, 180, 292, 3],
    'kruszyn-quarry-marathon' => [8_300, 'endurance', 330_000, 10_560, 7_410, 61],
    'suchy-pas-short-strip' => [500, 'sprint', 14_000, 168, 306, 3],
    'suchy-pas-long-run' => [8_500, 'endurance', 240_000, 7_680, 6_050, 48],
    'wilcza-przelecz-hairpin' => [480, 'sprint', 14_000, 168, 350, 3],
    'wilcza-przelecz-crossing' => [11_600, 'endurance', 340_000, 10_880, 9_700, 70],
];

/** @return array<string, mixed> a track as it was made by hand */
function trackByHand(string $slug): array
{
    [$length, $kind, $lap, $fuel, $reward, $xp] = TRACKS_BY_HAND[$slug];

    return ['name' => ['en' => $slug], 'kind' => $kind, 'length_m' => $length, 'base_lap_ms' => $lap, 'fuel_per_lap_ml' => $fuel, 'reward_cents_per_lap' => $reward, 'xp_per_lap' => $xp, 'demand' => ['grip' => 100]];
}

describe('the tracks of the repository', function () {
    it('are all made by the model, and none says by hand what the model makes', function () {
        $rules = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);

        expect(array_keys($rules['tracks']))->toBe(array_keys(TRACKS_BY_HAND));

        foreach ($rules['tracks'] as $slug => $track) {
            expect(array_keys(array_intersect_key($track, array_flip(['speed_kmh', 'pay_cents_per_hour', 'xp_per_hour', 'base_lap_ms', 'fuel_per_lap_ml', 'reward_cents_per_lap', 'xp_per_lap']))))
                ->toBe(['speed_kmh', 'pay_cents_per_hour', 'xp_per_hour'], $slug);
        }

        expect($rules['track_model']['fuel_ml_per_s'])->toBe(['sprint' => 12, 'standard' => 20, 'endurance' => 32]);
    });

    it('give the lap, the fuel, the pay and the XP that they gave by hand, on every one of the fifteen', function () {
        $rules = app(Rules::class);

        foreach (TRACKS_BY_HAND as $slug => [$length, , $lap, $fuel, $reward, $xp]) {
            expect($rules->track($slug))->toMatchArray(['length_m' => $length, 'base_lap_ms' => $lap, 'fuel_per_lap_ml' => $fuel, 'reward_cents_per_lap' => $reward, 'xp_per_lap' => $xp], $slug);
        }
    });

    it('freeze every combustion car of the pack on every track into what it froze into before the model', function () {
        $snapshot = json_decode((string) file_get_contents(__DIR__.'/../../Support/frozen-before-track-model.json'), true, 512, JSON_THROW_ON_ERROR);
        $rules = app(Rules::class);
        $factory = app(RunInputsFactory::class);
        $tiresSlot = $rules->model()->slotOf('tires');
        $checked = [];
        $problems = [];

        foreach (array_keys($rules->tracks()) as $track) {
            $tires = $rules->trackReferenceTires($track);

            foreach (app(Catalog::class)->vehicles() as $car => $vehicle) {
                if (($vehicle['powertrain'] ?? null) !== 'ice') {
                    continue;
                }

                $variants = ['stock' => []] + ($tires !== null && $tiresSlot !== null ? ['reference-tires' => [$tiresSlot => $tires]] : []);

                foreach ($variants as $variant => $mounted) {
                    $config = (new RunFreezer)->freeze($factory->make($car, $track, $mounted), 'regression', 0)->config;
                    $key = "{$track}|{$car}|{$variant}";
                    $now = [$config['lap_ms']['dry'], $config['fuel_per_lap_ml'], $config['lap_cash_cents']['dry'], $config['lap_xp']['dry'], sha1((string) json_encode($config))];
                    $checked[] = $key;

                    if (($snapshot[$key] ?? null) !== $now) {
                        $problems[] = "{$key}: was ".json_encode($snapshot[$key] ?? null).', is '.json_encode($now);
                    }
                }
            }
        }

        // Every entry of the snapshot was looked at, and nothing that is not in it was (the snapshot is sorted by key).
        sort($checked);

        expect($problems)->toBe([])->and($checked)->toBe(array_keys($snapshot));
    });
});

describe('the converter', function () {
    it('turns the four numbers of every track by hand into a speed, the pay of an hour and the XP of an hour, and the model makes the same four numbers of them', function () {
        $loads = TrackNumbers::fuelLoads([]);

        foreach (TRACKS_BY_HAND as $slug => [$length, , $lap, $fuel, $reward, $xp]) {
            $converted = TrackConverter::convert(trackByHand($slug), $loads);

            expect(array_keys($converted['track']))->toBe(['name', 'kind', 'length_m', 'speed_kmh', 'pay_cents_per_hour', 'xp_per_hour', 'demand'], $slug)
                ->and(array_filter(array_column($converted['report'], 'converted'), static fn (bool $done): bool => ! $done))->toBe([], $slug)
                ->and(TrackNumbers::derive($converted['track'], $loads))->toMatchArray(['base_lap_ms' => $lap, 'fuel_per_lap_ml' => $fuel, 'reward_cents_per_lap' => $reward, 'xp_per_lap' => $xp], $slug);
        }
    });

    it('writes the speed with as few decimals as makes the lap to the millisecond, at most four', function () {
        $loads = TrackNumbers::fuelLoads([]);
        $speed = fn (string $slug): int|float => TrackConverter::convert(trackByHand($slug), $loads)['track']['speed_kmh'];

        expect($speed('zlomowo-street'))->toBe(124.8)
            ->and($speed('suchy-pas-drag'))->toBe(128)
            ->and($speed('suchy-pas-short-strip'))->toBe(128.57)
            ->and($speed('kruszyn-quarry'))->toBe(90.947)
            ->and($speed('kruszyn-quarry-marathon'))->toBe(90.5455);
    });

    it('leaves by hand what it cannot make exactly, and says so', function () {
        $loads = TrackNumbers::fuelLoads([]);

        // A fuel that is not what the engine burns in the time of the lap at the load of the kind: 1 600 ml instead of 1 500.
        $track = [...trackByHand('zlomowo-street'), 'fuel_per_lap_ml' => 1_600];
        $converted = TrackConverter::convert($track, $loads);

        expect($converted['track'])->toMatchArray(['speed_kmh' => 124.8, 'fuel_per_lap_ml' => 1_600])
            ->and(array_intersect_key($converted['track'], array_flip(['base_lap_ms', 'reward_cents_per_lap', 'xp_per_lap'])))->toBe([])
            ->and(array_values(array_filter($converted['report'], static fn (array $row): bool => ! $row['converted']))[0]['field'])->toBe('fuel_per_lap_ml');

        // A lap that no speed of four decimals makes: the lap stays by hand (and so do the numbers that the lap would have made, which are made of it).
        $slow = [...trackByHand('zlomowo-street'), 'length_m' => 100_000, 'base_lap_ms' => 9_000_001, 'fuel_per_lap_ml' => 180_000, 'reward_cents_per_lap' => 1_550, 'xp_per_lap' => 20];
        $kept = TrackConverter::convert($slow, $loads);

        expect($kept['track'])->toHaveKey('base_lap_ms', 9_000_001)->and($kept['track'])->not->toHaveKey('speed_kmh');
    });

    it('leaves a track that is made by the model as it is, and one that has no length', function () {
        $loads = TrackNumbers::fuelLoads([]);
        $made = ['length_m' => 2_600, 'speed_kmh' => 124.8, 'pay_cents_per_hour' => 74_400, 'xp_per_hour' => 960];

        expect(TrackConverter::convert($made, $loads))->toBe(['track' => $made, 'report' => []])
            ->and(TrackConverter::convert(['base_lap_ms' => 75_000], $loads)['track'])->toBe(['base_lap_ms' => 75_000]);
    });
});
