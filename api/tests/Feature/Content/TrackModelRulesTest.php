<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\PackValidator;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\TrackNumbers;
use App\Domain\Run\RunFreezer;

// A track says its length and its speed, and what an hour of it pays and gives; the game makes the lap of the reference car, its fuel, and the pay and the XP of a lap.

/** @return array<string, mixed> the rules of the repository */
function modelRules(): array
{
    return json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The rules with a track that says what the model needs and none of the four numbers that it makes.
 *
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $track  what the track says over it
 * @return array<string, mixed>
 */
function withModelTrack(array $rules, string $slug = 'zlomowo-street', array $track = []): array
{
    $shown = ['zlomowo-street' => [124.8, 74_400, 960], 'zlomowo-yard-dash' => [126, 60_000, 900], 'zlomowo-ring-road' => [124.8, 75_600, 864]][$slug];
    $rules['tracks'][$slug] = [
        ...array_diff_key($rules['tracks'][$slug], array_flip(['base_lap_ms', 'fuel_per_lap_ml', 'reward_cents_per_lap', 'xp_per_lap'])),
        'speed_kmh' => $shown[0],
        'pay_cents_per_hour' => $shown[1],
        'xp_per_hour' => $shown[2],
        ...$track,
    ];

    return $rules;
}

/** @return array<string, mixed> the rules of the repository with a track that is made by the model */
function rulesWithModelTrack(string $slug = 'zlomowo-street', array $track = []): array
{
    return withModelTrack(modelRules(), $slug, $track);
}

/**
 * What the validator says of the rules of the repository after a change: the pointers of its errors and of its warnings.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $change
 * @return array{errors: list<string>, warnings: list<string>}
 */
function validatedRules(Closure $change): array
{
    $result = PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => $change]));

    return [
        'errors' => array_map(static fn (array $issue): string => "{$issue['file']} {$issue['pointer']}", $result->errors()),
        'warnings' => array_map(static fn (array $issue): string => "{$issue['file']} {$issue['pointer']}", $result->warnings()),
    ];
}

/** The validator on rules whose track is made by the model, with some of what it says changed. */
function validatedTrack(string $slug, array $track): array
{
    return validatedRules(fn (array $rules): array => withModelTrack($rules, $slug, $track));
}

describe('the numbers that the game reads', function () {
    it('are made of the length, the speed, the kind and what an hour pays, as the ones that were said are: 75 s, 1.5 l, $15.50 and 20 XP on the first track', function () {
        $track = Rules::fromArray(rulesWithModelTrack())->track('zlomowo-street');

        expect($track)->toMatchArray(['base_lap_ms' => 75_000, 'fuel_per_lap_ml' => 1_500, 'reward_cents_per_lap' => 1_550, 'xp_per_lap' => 20])
            // And what they come to for whoever shows them.
            ->and($track)->toMatchArray(['speed_kmh' => 124.8, 'pay_cents_per_hour' => 74_400, 'xp_per_hour' => 960, 'fuel_ml_per_s' => 20]);
    });

    it('are the ones that were said, when a document says them, and the model makes the rest from the time that was said', function () {
        // A track of the games from before the speed: the four numbers by hand, and nothing that says what an hour pays. What it comes to is made of them.
        $track = Rules::fromArray(modelRules())->track('zlomowo-street');

        expect($track)->toMatchArray(['base_lap_ms' => 75_000, 'fuel_per_lap_ml' => 1_500, 'reward_cents_per_lap' => 1_550, 'xp_per_lap' => 20, 'pay_cents_per_hour' => 74_400, 'xp_per_hour' => 960, 'fuel_ml_per_s' => 20])
            ->and(Rules::fromArray(modelRules())->trackSpeedKmh('zlomowo-street'))->toBe(124.8)
            ->and(Rules::fromArray(modelRules())->trackSpeedKmh('suchy-pas-drag'))->toBe(128);

        // A lap said by hand wins over the speed, and the fuel is made of the lap that counts.
        $rules = rulesWithModelTrack('zlomowo-street', ['base_lap_ms' => 90_000]);

        expect(Rules::fromArray($rules)->track('zlomowo-street'))->toMatchArray(['base_lap_ms' => 90_000, 'fuel_per_lap_ml' => 1_800, 'reward_cents_per_lap' => 1_860, 'xp_per_lap' => 24]);

        // So does a fuel said by hand.
        $rules = rulesWithModelTrack('zlomowo-street', ['fuel_per_lap_ml' => 1_234]);

        expect(Rules::fromArray($rules)->track('zlomowo-street'))->toMatchArray(['base_lap_ms' => 75_000, 'fuel_per_lap_ml' => 1_234]);
    });

    it('burn what the kind of the track says, for every second of the lap, and the rules can say it otherwise', function () {
        // A sprint of 12 s at 12 ml, a track of 75 s at 20, an endurance track of 5 min at 32.
        $rules = Rules::fromArray(rulesWithModelTrack('zlomowo-yard-dash'));
        $byDefault = Rules::fromArray(modelRules());

        expect($rules->track('zlomowo-yard-dash')['fuel_per_lap_ml'])->toBe(144)
            ->and([$byDefault->trackFuelMlPerSecond('sprint'), $byDefault->trackFuelMlPerSecond('standard'), $byDefault->trackFuelMlPerSecond('endurance')])->toBe([12, 20, 32]);

        $changed = rulesWithModelTrack('zlomowo-yard-dash');
        $changed['track_model'] = ['fuel_ml_per_s' => ['sprint' => 24, 'standard' => 20, 'endurance' => 32]];

        // The load of the sprints doubles and so does the fuel of every one, and the standard tracks are as they were.
        expect(Rules::fromArray($changed)->track('zlomowo-yard-dash')['fuel_per_lap_ml'])->toBe(288)
            ->and(Rules::fromArray($changed)->track('zlomowo-street')['fuel_per_lap_ml'])->toBe(1_500)
            ->and(Rules::fromArray($changed)->trackFuelMlPerSecond('sprint'))->toBe(24);
    });

    it('double with the length of the track, and what an hour gives stays', function () {
        $short = Rules::fromArray(rulesWithModelTrack())->track('zlomowo-street');
        $long = Rules::fromArray(rulesWithModelTrack('zlomowo-street', ['length_m' => 5_200]))->track('zlomowo-street');

        expect($long)->toMatchArray(['base_lap_ms' => 150_000, 'fuel_per_lap_ml' => 3_000, 'reward_cents_per_lap' => 3_100, 'xp_per_lap' => 40])
            ->and([$long['pay_cents_per_hour'], $long['xp_per_hour'], $long['speed_kmh']])->toBe([$short['pay_cents_per_hour'], $short['xp_per_hour'], $short['speed_kmh']]);
    });

    it('leave a track as it is when there is not enough in it to make them, so that the schema is the one that refuses it', function () {
        $loads = TrackNumbers::fuelLoads([]);

        expect(TrackNumbers::derive(['length_m' => 2_600], $loads))->toBe(['length_m' => 2_600])
            ->and(TrackNumbers::derive(['speed_kmh' => 124.8], $loads))->toBe(['speed_kmh' => 124.8])
            ->and(TrackNumbers::derive(['length_m' => 2_600, 'speed_kmh' => 0], $loads))->toBe(['length_m' => 2_600, 'speed_kmh' => 0]);
    });

    it('freeze into a run that is the one of a track with the four numbers by hand', function () {
        $freeze = fn (array $rules): array => (new RunFreezer)->freeze((new RunInputsFactory(app(Catalog::class), Rules::fromArray($rules)))->make('rdzak-gt-4', 'zlomowo-street'), 'seed', 0)->config;

        expect($freeze(rulesWithModelTrack()))->toBe($freeze(modelRules()))
            ->and($freeze(rulesWithModelTrack())['lap_ms']['dry'])->toBe(75_000)
            ->and($freeze(rulesWithModelTrack())['fuel_per_lap_ml'])->toBe(1_500);
    });
});

describe('the rules that say them', function () {
    it('are valid as the repository has them, with nothing to say about them', function () {
        expect(validatedRules(fn (array $rules): array => $rules))->toBe(['errors' => [], 'warnings' => []]);
    });

    it('are valid when a track says its speed and what an hour pays and gives, and none of the four numbers', function () {
        expect(validatedTrack('zlomowo-street', []))->toBe(['errors' => [], 'warnings' => []])
            ->and(validatedRules(fn (array $rules): array => [...withModelTrack($rules), 'track_model' => ['fuel_ml_per_s' => ['sprint' => 12, 'standard' => 20, 'endurance' => 32]]]))->toBe(['errors' => [], 'warnings' => []]);
    });

    it('are refused when a track says neither its speed nor its lap, nor what a lap or an hour pays, nor what it gives', function (string $drop) {
        $result = validatedRules(function (array $rules) use ($drop): array {
            $rules = withModelTrack($rules);
            unset($rules['tracks']['zlomowo-street'][$drop]);

            return $rules;
        });

        expect($result['errors'])->not->toBe([]);
    })->with(['speed_kmh', 'pay_cents_per_hour', 'xp_per_hour']);

    it('are refused when the speed is out of its limits or the load of a kind is nothing', function (Closure $change) {
        expect(validatedRules(fn (array $rules): array => $change(withModelTrack($rules)))['errors'])->not->toBe([]);
    })->with([
        'under 20 km/h' => [fn (array $rules): array => [...$rules, 'tracks' => [...$rules['tracks'], 'zlomowo-street' => [...$rules['tracks']['zlomowo-street'], 'speed_kmh' => 19.9]]]],
        'over 300 km/h' => [fn (array $rules): array => [...$rules, 'tracks' => [...$rules['tracks'], 'zlomowo-street' => [...$rules['tracks']['zlomowo-street'], 'speed_kmh' => 300.1]]]],
        'a lap longer than the arithmetic holds' => [fn (array $rules): array => [...$rules, 'tracks' => [...$rules['tracks'], 'zlomowo-street' => [...$rules['tracks']['zlomowo-street'], 'length_m' => 100_000_000_001]]]],
        'no load for a kind' => [fn (array $rules): array => [...$rules, 'track_model' => ['fuel_ml_per_s' => ['sprint' => 12, 'standard' => 20]]]],
        'a load of nothing' => [fn (array $rules): array => [...$rules, 'track_model' => ['fuel_ml_per_s' => ['sprint' => 0, 'standard' => 20, 'endurance' => 32]]]],
    ]);

    it('are refused when the speed has more than four decimals or the experience of an hour more than two', function () {
        expect(validatedTrack('zlomowo-street', ['speed_kmh' => 124.80001])['errors'])->toContain('rules/starter.json /tracks/zlomowo-street/speed_kmh')
            ->and(validatedTrack('zlomowo-street', ['xp_per_hour' => 960.001])['errors'])->toContain('rules/starter.json /tracks/zlomowo-street/xp_per_hour')
            ->and(validatedTrack('zlomowo-street', ['speed_kmh' => 124.8001, 'xp_per_hour' => 960.01])['errors'])->toBe([])
            // The noise of the arithmetic of whoever made the number (665.45 times 3 is 1996.3500000000001) is not a decimal.
            ->and(validatedTrack('zlomowo-street', ['xp_per_hour' => 665.45 * 3, 'speed_kmh' => 0.1 + 0.2 + 124.5])['errors'])->toBe([]);
    });

    it('are refused when a track made by the model is a stunt, and are warned about when one that was said by hand is', function () {
        // 150 m is under the shortest track, and at 124.8 km/h it is a lap of 4.3 s.
        $modelled = validatedTrack('zlomowo-street', ['length_m' => 150]);

        expect($modelled['errors'])->toContain('rules/starter.json /tracks/zlomowo-street/length_m')->toContain('rules/starter.json /tracks/zlomowo-street/speed_kmh');

        $byHand = validatedRules(function (array $rules): array {
            $rules['tracks']['zlomowo-yard-dash'] = [
                ...array_diff_key($rules['tracks']['zlomowo-yard-dash'], array_flip(['speed_kmh', 'pay_cents_per_hour', 'xp_per_hour'])),
                'length_m' => 150,
                'base_lap_ms' => 4_000,
                'fuel_per_lap_ml' => 48,
                'reward_cents_per_lap' => 67,
                'xp_per_lap' => 1,
            ];

            return $rules;
        });

        expect($byHand['errors'])->toBe([])
            ->and($byHand['warnings'])->toContain('rules/starter.json /tracks/zlomowo-yard-dash/length_m')->toContain('rules/starter.json /tracks/zlomowo-yard-dash/base_lap_ms');
    });

    it('say it when a number that was said by hand is not what the model would make, and only then', function () {
        // The lap of 90 s over the speed that makes 75 s: 20% more. (The fuel and the pay that were not said are made of the lap that counts, so they are not said.)
        $result = validatedTrack('zlomowo-street', ['base_lap_ms' => 90_000]);

        expect($result['errors'])->toBe([])->and($result['warnings'])->toBe(['rules/starter.json /tracks/zlomowo-street/base_lap_ms']);

        // A fuel that is 1% off is not worth a word, one that is 20% off is.
        expect(validatedTrack('zlomowo-street', ['fuel_per_lap_ml' => 1_515])['warnings'])->toBe([])
            ->and(validatedTrack('zlomowo-street', ['fuel_per_lap_ml' => 1_800])['warnings'])->toBe(['rules/starter.json /tracks/zlomowo-street/fuel_per_lap_ml']);
    });

    it('say it when the whole number of XP of a lap takes a tenth of what the hour gives', function () {
        // 750 XP an hour over a lap of 12 s is 2.5 XP, and a lap gives 3: a fifth more.
        expect(validatedTrack('zlomowo-yard-dash', ['xp_per_hour' => 750])['warnings'])->toBe(['rules/starter.json /tracks/zlomowo-yard-dash/xp_per_hour'])
            ->and(validatedTrack('zlomowo-yard-dash', ['xp_per_hour' => 900])['warnings'])->toBe([]);
    });
});
