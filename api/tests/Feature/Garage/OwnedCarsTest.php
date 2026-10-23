<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\User;
use App\Models\WorkshopJob;
use Illuminate\Database\QueryException;

describe('cars and parts', function () {
    it('gives a run a car of the driver at the level a new car has', function () {
        $user = User::factory()->guest()->create();

        $run = runService()->start($user->id, 'rdzak-gt-4', 'zlomowo-street', dryInputs(), 45_000, 0);

        expect($run->car)->toBeInstanceOf(Car::class)
            ->and($run->car->user_id)->toBe($user->id)
            ->and($run->car->vehicle_slug)->toBe('rdzak-gt-4')
            ->and($run->car->level)->toBe(3);
    });

    it('drives the car it is given instead of making a new one', function () {
        $car = Car::factory()->create(['level' => 6]);

        $run = runService()->start($car->user_id, 'rdzak-gt-4', 'zlomowo-street', dryInputs(), 45_000, 0, $car);

        expect($run->car_id)->toBe($car->id)->and(Car::query()->count())->toBe(1);
    });

    it('names the parts that sit on a car by slot, and not the ones in the inventory', function () {
        $car = Car::factory()->create();
        Part::factory()->state(['part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust'])->mountedOn($car)->create();
        Part::factory()->state(['part_slug' => 'disc-brakes-t2', 'slot' => 'brakes'])->mountedOn($car)->create();
        Part::factory()->create(['user_id' => $car->user_id, 'part_slug' => 'street-tires-t2']);

        expect($car->mountedSlugs())->toBe(['brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2']);
    });

    it('lets the database refuse a second part in one slot of a car', function () {
        $car = Car::factory()->create();
        Part::factory()->mountedOn($car)->create();

        expect(fn () => Part::factory()->mountedOn($car)->create(['part_slug' => 'street-tires-t1']))->toThrow(QueryException::class);
    });

    it('lets the inventory hold as many parts of a slot as the driver has', function () {
        $user = User::factory()->guest()->create();

        Part::factory()->count(3)->create(['user_id' => $user->id]);

        expect(Part::query()->where('user_id', $user->id)->whereNull('car_id')->count())->toBe(3);
    });

    it('removes the cars, the parts and the jobs with the driver', function () {
        $run = aRun();
        $part = Part::factory()->create(['user_id' => $run->user_id]);
        WorkshopJob::factory()->create(['user_id' => $run->user_id, 'car_id' => $run->car_id, 'part_id' => $part->id]);

        User::query()->whereKey($run->user_id)->delete();

        expect(RaceRun::query()->count())->toBe(0)
            ->and(Car::query()->count())->toBe(0)
            ->and(Part::query()->count())->toBe(0)
            ->and(WorkshopJob::query()->count())->toBe(0);
    });
});
