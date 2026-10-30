<?php

declare(strict_types=1);

use App\Content\Units\DistanceUnit;
use App\Content\Units\Measures;

// A game counts distance and speed in units of its own: the numbers of the rules stay in metres, kilometres and km/h, and the units are what a person writes and is shown.

/** @return array<string, mixed> the units of a game of ships, as its rules say them */
function shipUnits(): array
{
    return [
        'lap' => ['name' => ['en' => 'nautical mile', 'pl' => 'mila morska'], 'short' => ['en' => 'nm', 'pl' => 'Mm'], 'metres' => 1852, 'decimals' => 2],
        'travel' => ['name' => ['en' => 'nautical mile', 'pl' => 'mila morska'], 'short' => ['en' => 'nm', 'pl' => 'Mm'], 'metres' => 1852, 'decimals' => 0],
        'speed' => ['name' => ['en' => 'knot', 'pl' => 'węzeł'], 'short' => ['en' => 'kn', 'pl' => 'w'], 'kmh' => 1.852, 'decimals' => 0],
        'fuel_economy' => ['per' => 1],
    ];
}

describe('the units of a game', function () {
    it('are the metres, the kilometres and the km/h of the car game, and the litres of 100 km, when the rules say nothing', function () {
        $measures = Measures::fromRules([]);

        expect([$measures->lap->metres, $measures->travel->metres, $measures->speed->kmh, $measures->fuelEconomyPer])->toBe([1, 1000, 1, 100])
            ->and([$measures->lap->shortFor('en'), $measures->travel->shortFor('pl'), $measures->speed->shortFor('pt-BR')])->toBe(['m', 'km', 'km/h'])
            ->and([$measures->lap->nameFor('pl'), $measures->travel->nameFor('fr'), $measures->speed->nameFor('de'), $measures->lap->nameFor('pt-BR')])->toBe(['metr', 'kilomètre', 'Kilometer pro Stunde', 'metro']);
    });

    it('are what the rules say, a language at a time, and the car game for what they do not say', function () {
        $measures = Measures::fromRules(['units' => shipUnits()]);

        expect([$measures->lap->metres, $measures->speed->kmh, $measures->fuelEconomyPer])->toBe([1852, 1.852, 1])
            ->and([$measures->lap->shortFor('en'), $measures->lap->shortFor('pl'), $measures->lap->shortFor('fr')])->toBe(['nm', 'Mm', 'nm'])
            ->and($measures->speed->nameFor('pl'))->toBe('węzeł');

        // Only the travel unit is said: the rest is the car game's.
        $partly = Measures::fromRules(['units' => ['travel' => shipUnits()['travel']]]);

        expect([$partly->lap->metres, $partly->travel->metres, $partly->speed->kmh])->toBe([1, 1852, 1]);
    });

    it('fall back to the car game for a part of the rules that is not a unit', function (array $units) {
        $measures = Measures::fromRules(['units' => $units]);

        expect([$measures->lap->metres, $measures->travel->metres, $measures->speed->kmh, $measures->fuelEconomyPer])->toBe([1, 1000, 1, 100]);
    })->with([
        'a distance of no metres' => [['lap' => ['metres' => 0], 'travel' => ['metres' => -5], 'speed' => ['kmh' => 'fast'], 'fuel_economy' => ['per' => 7]]],
        'not a list of units' => [['lap' => 'metre', 'travel' => null, 'speed' => []]],
    ]);

    it('have the decimals that are sound, and none otherwise', function () {
        $measures = Measures::fromRules(['units' => ['lap' => [...shipUnits()['lap'], 'decimals' => 9], 'travel' => [...shipUnits()['travel'], 'decimals' => 3]]]);

        expect([$measures->lap->decimals, $measures->travel->decimals])->toBe([0, 3]);
    });

    it('come back as the rules write them, for a game that starts from a preset', function (string $preset) {
        $measures = Measures::preset($preset);

        expect(Measures::fromRules(['units' => $measures->toRules()]))->toEqual($measures);
    })->with(Measures::PRESETS);

    it('have the presets of the metric system, of the ships and of the miles', function () {
        expect(Measures::preset('metric')->travel->metres)->toBe(1000)
            ->and(Measures::preset('nautical')->travel->metres)->toBe(1852)
            ->and(Measures::preset('nautical')->speed->kmh)->toBe(1.852)
            ->and(Measures::preset('nautical')->fuelEconomyPer)->toBe(1)
            ->and(Measures::preset('imperial')->travel->metres)->toBe(1609.344)
            ->and(Measures::preset('imperial')->speed->shortFor('en'))->toBe('mph')
            ->and(Measures::preset('whatever'))->toEqual(Measures::metric());
    });

    it('are given to the app with their names in every language', function () {
        $units = Measures::fromRules(['units' => shipUnits()])->toArray();

        expect(array_keys($units))->toBe(['lap', 'travel', 'speed', 'fuel_economy_per'])
            ->and($units['lap'])->toMatchArray(['metres' => 1852, 'decimals' => 2, 'short' => ['en' => 'nm', 'pl' => 'Mm']])
            ->and($units['speed']['kmh'])->toBe(1.852)
            ->and($units['fuel_economy_per'])->toBe(1)
            ->and(Measures::metric()->toArray()['lap']['name'])->toEqual(['pl' => 'metr', 'en' => 'metre', 'fr' => 'mètre', 'de' => 'Meter', 'pt-BR' => 'metro']);
    });
});

describe('what a person writes and is shown, in the unit', function () {
    it('is the distance in the unit with the decimals of the unit, and a box that gets back to the same metre', function () {
        $ships = Measures::fromRules(['units' => shipUnits()]);

        // 2 600 m is 1.4 nautical miles for a person who reads it, and a box holds enough decimals to give 2 600 m back.
        expect($ships->lap->shown(2_600))->toBe(1.4)
            ->and($ships->lap->inBox(2_600))->toBe(1.403888)
            ->and($ships->lap->toMetres($ships->lap->inBox(2_600)))->toBe(2_600)
            ->and($ships->travel->shown(37_000))->toBe(20)
            ->and($ships->lap->toMetres(1.4))->toBe(2_593);

        // The metres of the car game are the metres of the document.
        expect(Measures::metric()->lap->shown(2_600))->toBe(2_600)->and(Measures::metric()->lap->toMetres(2_600))->toBe(2_600);
    });

    it('gives every whole metre back, in a box, for any unit', function (int|float $metres) {
        $state = 4_242;

        for ($case = 0; $case < 500; $case++) {
            $state = ($state * 1_103_515_245 + 12_345) % 2_147_483_648;
            $length = 200 + intdiv($state, 1_024) % 40_000;
            $unit = Measures::preset('nautical')->lap;

            expect($unit->toMetres($unit->inBox($length)))->toBe($length);
        }

        $unit = new DistanceUnit(['en' => 'x'], ['en' => 'x'], $metres, 2);

        expect($unit->toMetres($unit->inBox(2_600)))->toBe(2_600);
    })->with([1, 1000, 1852, 1609.344, 185.2]);

    it('is the speed in the unit, and the km/h of the document with at most four decimals', function () {
        $ships = Measures::fromRules(['units' => shipUnits()])->speed;

        expect($ships->shown(37.04))->toBe(20)
            ->and($ships->toKmh(20))->toBe(37.04)
            ->and($ships->toKmh($ships->inBox(124.8)))->toBe(124.8)
            ->and($ships->toKmh($ships->inBox(90.5455)))->toBe(90.5455)
            ->and(Measures::metric()->speed->toKmh(124.8))->toBe(124.8);
    });
});
