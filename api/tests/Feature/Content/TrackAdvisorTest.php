<?php

declare(strict_types=1);

use App\Content\TrackAdvisor;

// The button of the form of a track that suggests a speed: the free speed of the surface less what the corners, the climbs and the traffic take off.

describe('the speed that the calculator suggests', function () {
    it('is 180 km/h on asphalt and 130 on gravel for a track that has nothing to slow a car, with what the rules say when they say nothing', function () {
        $constants = TrackAdvisor::constants([]);

        expect(TrackAdvisor::speedKmh('asphalt', 0, 0, 0, $constants))->toBe(180)
            ->and(TrackAdvisor::speedKmh('gravel', 0, 0, 0, $constants))->toBe(130)
            ->and($constants['corner_loss_kmh'])->toBe(10)
            ->and($constants['climb_loss_kmh'])->toBe(5)
            ->and($constants['traffic_loss_kmh'])->toBe(5);
    });

    it('takes 10 km/h off for a corner in a kilometre, 5 for 10 m of climb in a kilometre and 5 for a step of traffic', function () {
        $constants = TrackAdvisor::constants([]);

        // The examples of the help: the Autodrome 3.6 corners a kilometre (144), a city street 4.5 corners and traffic 2 (125), a mountain pass 8 corners and 20 m (90).
        expect(TrackAdvisor::speedKmh('asphalt', 3.6, 0, 0, $constants))->toBe(144)
            ->and(TrackAdvisor::speedKmh('asphalt', 4.5, 0, 2, $constants))->toBe(125)
            ->and(TrackAdvisor::speedKmh('asphalt', 8, 20, 0, $constants))->toBe(90)
            ->and(TrackAdvisor::speedKmh('gravel', 2.5, 0, 1, $constants))->toBe(100);
    });

    it('is a speed that a track may have, whatever the numbers: never under 20 or over 300, and one decimal at the most', function () {
        $constants = TrackAdvisor::constants([]);

        expect(TrackAdvisor::speedKmh('asphalt', 40, 500, 3, $constants))->toBe(20)
            ->and(TrackAdvisor::speedKmh('asphalt', -5, -5, -1, TrackAdvisor::constants(['track_model' => ['advisor' => ['free_speed_kmh' => ['asphalt' => 300, 'gravel' => 300], 'corner_loss_kmh' => 0, 'climb_loss_kmh' => 0, 'traffic_loss_kmh' => 0]]])))->toBe(300)
            ->and(TrackAdvisor::speedKmh('asphalt', 3.33, 0, 0, $constants))->toBe(146.7)
            // Traffic does not go over 3 steps.
            ->and(TrackAdvisor::speedKmh('asphalt', 0, 0, 9, $constants))->toBe(165);
    });

    it('counts with what the rules say, and with the default for what they do not say', function () {
        $constants = TrackAdvisor::constants(['track_model' => ['advisor' => ['free_speed_kmh' => ['asphalt' => 200], 'corner_loss_kmh' => 20]]]);

        expect($constants['free_speed_kmh'])->toBe(['asphalt' => 200, 'gravel' => 130])
            ->and($constants['corner_loss_kmh'])->toBe(20)
            ->and($constants['climb_loss_kmh'])->toBe(5)
            ->and(TrackAdvisor::speedKmh('asphalt', 3, 0, 0, $constants))->toBe(140);
    });
});
