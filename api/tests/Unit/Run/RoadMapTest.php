<?php

declare(strict_types=1);

use App\Domain\Run\RoadMap;

function theRoads(): RoadMap
{
    return new RoadMap([
        ['from' => 'a', 'to' => 'b', 'km' => 38],
        ['from' => 'b', 'to' => 'c', 'km' => 23],
        ['from' => 'b', 'to' => 'd', 'km' => 14],
        ['from' => 'a', 'to' => 'd', 'km' => 90],
        ['from' => 'x', 'to' => 'y', 'km' => 5],
    ]);
}

it('measures a road in both directions', function () {
    expect(theRoads()->distanceKm('a', 'b'))->toBe(38)
        ->and(theRoads()->distanceKm('b', 'a'))->toBe(38);
});

it('takes the shortest way, also when it goes through another city', function () {
    // a to c has no road of its own: 38 + 23. a to d is 52 by b, and not the 90 of the direct road.
    expect(theRoads()->distanceKm('a', 'c'))->toBe(61)
        ->and(theRoads()->distanceKm('a', 'd'))->toBe(52)
        ->and(theRoads()->distanceKm('c', 'd'))->toBe(37);
});

it('is no distance at all to the city one is in', function () {
    expect(theRoads()->distanceKm('a', 'a'))->toBe(0);
});

it('has no answer for a city that no road leads to, or does not exist', function () {
    expect(theRoads()->distanceKm('a', 'x'))->toBeNull()
        ->and(theRoads()->distanceKm('a', 'nowhere'))->toBeNull();
});

it('lists the cities that a road leads to from a city', function () {
    expect(theRoads()->neighbours('b'))->toBe(['a', 'c', 'd'])
        ->and(theRoads()->neighbours('c'))->toBe(['b'])
        ->and(theRoads()->neighbours('nowhere'))->toBe([]);
});
