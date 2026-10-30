<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\WorldForm;
use App\Content\TrackAdvisor;
use App\Content\TrackNumbers;
use App\Content\Units\Measures;
use App\Filament\Forms\WorldFields;

// The world form of the panel counts in the units of the game: the length of a lap and the speed of a track are written and shown in them, and the document keeps metres and km/h.

/** @return array<string, mixed> the rules of the repository, with the units of a game of ships */
function shipRules(): array
{
    $rules = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
    $rules['units'] = Measures::preset('nautical')->toRules();

    return $rules;
}

describe('the form of the world', function () {
    it('shows the length and the speed of a track in the units of the game, and gives the metres and the km/h of the document back for what is shown', function () {
        $rules = shipRules();
        $state = WorldForm::toState($rules);
        $street = $state['tracks'][0];

        // 2 600 m is 1.403888 nautical miles in a box and 124.8 km/h is 67.386609 knots: enough decimals to come back to the same metre and the same speed.
        expect($street['length'])->toBe(1.403888)
            ->and($street['speed'])->toBe(67.386609)
            ->and(WorldForm::apply($rules, $state)['tracks'])->toEqual($rules['tracks'])
            ->and(JsonDocument::encode(WorldForm::apply($rules, $state)))->toBe(JsonDocument::encode($rules));
    });

    it('turns what a person writes in the units of the game into the metres and the km/h of the document', function () {
        $rules = shipRules();
        $state = WorldForm::toState($rules);
        $state['tracks'][0]['length'] = '1,4';
        $state['tracks'][0]['speed'] = '20';

        $made = WorldForm::apply($rules, $state)['tracks']['zlomowo-street'];

        // 1.4 nautical miles is 2 592.8 m, and 20 knots is 37.04 km/h.
        expect($made['length_m'])->toBe(2_593)->and($made['speed_kmh'])->toBe(37.04);
    });

    it('is the form of the car game when the rules say no units: nothing is turned', function () {
        $rules = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
        $state = WorldForm::toState($rules);

        expect($state['tracks'][0])->toMatchArray(['length' => 2_600, 'speed' => 124.8, 'pay_per_hour' => 744, 'xp_per_hour' => 960]);

        $state['tracks'][0]['length'] = '3100';
        $state['tracks'][0]['speed'] = '130.5';

        expect(WorldForm::apply($rules, $state)['tracks']['zlomowo-street'])->toMatchArray(['length_m' => 3_100, 'speed_kmh' => 130.5]);
    });

    it('leaves what is not a number for the schema to refuse, whatever the unit', function () {
        $rules = shipRules();
        $state = WorldForm::toState($rules);
        $state['tracks'][0]['length'] = 'long';
        $state['tracks'][0]['speed'] = 'fast';

        expect(WorldForm::apply($rules, $state)['tracks']['zlomowo-street'])->toMatchArray(['length_m' => 'long', 'speed_kmh' => 'fast']);
    });
});

describe('what the form says of a track', function () {
    it('is the lap, the speed, the fuel, what a lap pays and gives, for the reference car, in the units of the game and the language of the panel', function () {
        app()->setLocale('en');
        $loads = TrackNumbers::fuelLoads([]);
        $state = ['length' => 2_600, 'speed' => 124.8, 'kind' => 'standard', 'pay_per_hour' => 744, 'xp_per_hour' => 960];

        expect(WorldFields::say($state, Measures::metric(), $loads))->toBe('Reference car: a lap of 1 min 15 s, 124.8 km/h on average, 1.50 l of fuel a lap (57.7 l/100 km), 48.0 laps an hour. A lap pays $15.50 and gives 20 XP.');

        // The same track counted in knots and nautical miles: 1.4 nm at 67.4 kn, the fuel in litres per nautical mile.
        $ships = ['length' => 1.403888, 'speed' => 67.386609] + $state;

        expect(WorldFields::say($ships, Measures::preset('nautical'), $loads))->toBe('Reference car: a lap of 1 min 15 s, 67.4 kn on average, 1.50 l of fuel a lap (1.1 l/nm), 48.0 laps an hour. A lap pays $15.50 and gives 20 XP.');

        app()->setLocale('pl');

        expect(WorldFields::say($state, Measures::metric(), $loads))->toContain('1 min 15 s')->toContain('124,8 km/h')->toContain('57,7 l/100 km')->toContain('15,50 $');
    });

    it('asks for the numbers it lacks, and does not say what it cannot make', function () {
        app()->setLocale('en');
        $loads = TrackNumbers::fuelLoads([]);

        expect(WorldFields::say(['length' => 2_600], Measures::metric(), $loads))->toBe(__('admin.world.preview_wait'))
            ->and(WorldFields::say(['length' => '', 'speed' => 'x', 'pay_per_hour' => 744, 'xp_per_hour' => 960], Measures::metric(), $loads))->toBe(__('admin.world.preview_wait'))
            ->and(WorldFields::say(['length' => 2_600, 'speed' => 0, 'pay_per_hour' => 744, 'xp_per_hour' => 960], Measures::metric(), $loads))->toBe(__('admin.world.preview_wait'));
    });

    it('counts a number said by hand as the game does: over what the model makes', function () {
        app()->setLocale('en');
        $state = ['length' => 2_600, 'speed' => 124.8, 'pay_per_hour' => 744, 'xp_per_hour' => 960, 'base_lap_ms' => 90_000];

        expect(WorldFields::say($state, Measures::metric(), TrackNumbers::fuelLoads([])))->toContain('a lap of 1 min 30 s')->toContain('1.80 l of fuel a lap');
    });
});

describe('the speed that the button suggests', function () {
    it('is counted per unit of travel of the game: 4.5 corners in a nautical mile is 2.43 in a kilometre', function () {
        $rules = shipRules();
        $advisor = TrackAdvisor::constants($rules);

        // In the car game 4.5 corners a kilometre and traffic 2 is 125 km/h.
        expect(WorldFields::advisedSpeed('asphalt', 4.5, 0, 2, Measures::metric(), $advisor))->toBe(125);

        // In nautical miles: 4.5 corners in 1.852 km is 2.43 a kilometre, which takes off 24.3 km/h and 10 for the traffic: 145.7 km/h.
        expect(WorldFields::advisedSpeed('asphalt', 4.5, 0, 2, Measures::preset('nautical'), $advisor))->toBe(145.7);
    });
});

describe('the move of a track to the model', function () {
    it('empties the boxes of the numbers that the model makes exactly, and fills the speed and the hour', function () {
        $loads = TrackNumbers::fuelLoads([]);
        $state = ['length' => 2_600, 'kind' => 'standard', 'base_lap_ms' => 75_000, 'fuel_per_lap_ml' => 1_500, 'reward' => 15.5, 'xp_per_lap' => 20];

        expect(WorldFields::moved($state, Measures::metric(), $loads))->toBe([
            'speed' => 124.8,
            'pay_per_hour' => 744,
            'xp_per_hour' => 960,
            'base_lap_ms' => null,
            'fuel_per_lap_ml' => null,
            'reward' => null,
            'xp_per_lap' => null,
        ]);
    });

    it('keeps the box of a number that cannot be made exactly', function () {
        $loads = TrackNumbers::fuelLoads([]);
        $state = ['length' => 2_600, 'kind' => 'standard', 'base_lap_ms' => 75_000, 'fuel_per_lap_ml' => 1_700, 'reward' => 15.5, 'xp_per_lap' => 20];
        $boxes = WorldFields::moved($state, Measures::metric(), $loads);

        expect($boxes)->not->toHaveKey('fuel_per_lap_ml')->and($boxes['base_lap_ms'])->toBeNull()->and($boxes['reward'])->toBeNull();
    });
});
