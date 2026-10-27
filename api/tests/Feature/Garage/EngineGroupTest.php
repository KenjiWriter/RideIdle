<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Models\Part;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/** The stock Rdzak GT-4: 97 kW, 1 000 kg, 175 km/h, an engine with four cylinders. */
function engineStats(array $mounted = []): array
{
    return app(RunInputsFactory::class)->stats('rdzak-gt-4', $mounted)->stats;
}

function engineFuel(array $mounted = []): float
{
    return app(RunInputsFactory::class)->stats('rdzak-gt-4', $mounted)->fuelUseMultiplier;
}

/** The wear of a part of the engine per kilometre on the first track, with these parts on the Rdzak. */
function enginePerKm(string $slot, array $mounted): int
{
    $factory = app(RunInputsFactory::class);
    $plan = $factory->wearPlan('rdzak-gt-4', 'zlomowo-street', $mounted, ['mileage_m' => 0, 'serviced_at_m' => 0]);

    return $plan->perKmPpb[$slot];
}

/** A driver at T0 whose car is of this model and level (the seed is fixed, so it does not break down in the middle of a test). */
function engineDriver(string $vehicle = 'rdzak-gt-4', int $level = 30, int $cash = 5_000_000): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    $run->car->forceFill(['vehicle_slug' => $vehicle, 'level' => $level])->save();
    (new Ledger)->post($driver->id, $cash, 'gift', "engine-test:{$driver->id}");

    return [$driver, $run];
}

function engineStock(User $driver, string $slug): Part
{
    $slot = app(Catalog::class)->part($slug)['slot'];

    return Part::factory()->create(['user_id' => $driver->id, 'part_slug' => $slug, 'slot' => $slot]);
}

function engineMount(User $driver, int $partId): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $partId], bearerFor($driver));
}

describe('the parts of the engine group', function () {
    it('has the 31 parts of the catalog in the shop, by what they are: 10 turbos, 6 superchargers, 5 intercoolers, 4 radiators and 6 gearboxes', function () {
        $catalog = app(Catalog::class);
        $slugs = app(Rules::class)->shopParts();
        $bySlot = [];

        foreach ($slugs as $slug) {
            $part = $catalog->part($slug);
            $bySlot[$part['slot']][] = $slug;
        }

        $boosters = $bySlot['boost'];
        $turbos = array_filter($boosters, fn (string $slug): bool => isset($catalog->part($slug)['requires']['min_cylinders']));
        $coolers = $bySlot['cooling'];
        $intercoolers = array_filter($coolers, fn (string $slug): bool => ($catalog->part($slug)['requires']['booster'] ?? false) === true);

        expect($boosters)->toHaveCount(16)
            ->and($turbos)->toHaveCount(10)
            ->and($coolers)->toHaveCount(9)
            ->and($intercoolers)->toHaveCount(5)
            ->and($bySlot['gearbox'])->toHaveCount(6)
            ->and(count($slugs))->toBe(40)
            ->and(count(array_unique($slugs)))->toBe(40);
    });

    it('has the numbers of the board: a turbo for six cylinders, the lag, the fuel and the load of each', function () {
        $catalog = app(Catalog::class);

        expect($catalog->part('turbo-grande-t6'))->toMatchArray(['slot' => 'boost', 'tier' => 6, 'price_cents' => 2_100_000, 'stats' => ['power_mult' => 1.4, 'lag' => 6, 'fuel_mult' => 1.2, 'engine_load' => 13], 'requires' => ['min_cylinders' => 6]])
            ->and($catalog->part('biturbo-pro-t9'))->toMatchArray(['tier' => 9, 'price_cents' => 9_500_000, 'requires' => ['min_cylinders' => 8]])
            ->and($catalog->part('roots-mini-t2'))->toMatchArray(['tier' => 2, 'price_cents' => 360_000, 'stats' => ['power_mult' => 1.12, 'lag' => 0, 'fuel_mult' => 1.1, 'engine_load' => 4]])
            ->and($catalog->part('roots-mini-t2')['requires'] ?? null)->toBeNull()
            ->and($catalog->part('intercooler-race-t8'))->toMatchArray(['slot' => 'cooling', 'tier' => 8, 'price_cents' => 2_200_000, 'stats' => ['boost_gain' => 0.05, 'engine_load' => -11, 'mass_kg' => 8], 'requires' => ['booster' => true]])
            ->and($catalog->part('radiator-oil-water-t7'))->toMatchArray(['slot' => 'cooling', 'tier' => 7, 'price_cents' => 1_180_000, 'stats' => ['engine_load' => -9, 'mass_kg' => 4]])
            ->and($catalog->part('dual-clutch-gearbox-t8'))->toMatchArray(['slot' => 'gearbox', 'tier' => 8, 'stats' => ['gears' => 7, 'top_speed_cap_kmh' => 340, 'launch' => 2]]);
    });

    it('has a name of every part in all five languages', function () {
        $catalog = app(Catalog::class);

        foreach (app(Rules::class)->shopParts() as $slug) {
            $name = $catalog->part($slug)['name'];

            foreach (['en', 'pl', 'fr', 'de', 'pt-BR'] as $language) {
                expect($name[$language] ?? '')->not->toBe('', "{$slug} has no name in {$language}");
            }
        }
    });
});

describe('what the parts of the engine group do to the car', function () {
    it('gives a booster its power and takes its lag off the acceleration, not off the top speed', function () {
        $stock = engineStats();
        $turbo = engineStats(['boost' => 'turbo-osemka-t2']);
        $roots = engineStats(['boost' => 'roots-mini-t2']);

        // 97 kW x 1.25 on 1 000 kg, less 3% for a lag of 3; a supercharger answers at once.
        expect($turbo['accel'])->toEqualWithDelta(97 * 1.25 * 0.97, 1e-9)
            ->and($roots['accel'])->toEqualWithDelta(97 * 1.12, 1e-9)
            ->and($turbo['top_speed'])->toEqualWithDelta($stock['top_speed'] * 1.25 ** (1 / 3), 1e-9)
            ->and(engineStats(['boost' => 'centrifugal-t6'])['accel'])->toEqualWithDelta(97 * 1.24 * 0.97, 1e-9);
    });

    it('multiplies the fuel use by the fuel of the booster on top of what the power does', function () {
        $reference = app(Rules::class)->fuelReferencePowerKw();

        // The use grows with the power (0.6 of it) and the booster asks 15% more.
        expect(engineFuel(['boost' => 'turbo-osemka-t2']))->toEqualWithDelta((1 + 0.6 * 97 * 1.25 / $reference) / 1.6 * 1.15, 1e-9)
            ->and(engineFuel(['boost' => 'roots-mini-t2']))->toBeLessThan(engineFuel(['boost' => 'turbo-osemka-t2']))
            ->and(engineFuel())->toEqualWithDelta((1 + 0.6 * 97 / $reference) / 1.6, 1e-9);
    });

    it('grows the gain of a booster with its intercooler, and the intercooler does nothing without one', function () {
        // The gain of the turbo is 25%, and the intercooler gives 2% of it: 25.5%. It weighs 5 kg.
        $cooled = engineStats(['boost' => 'turbo-osemka-t2', 'cooling' => 'intercooler-front-t4']);

        expect($cooled['accel'])->toEqualWithDelta(97 * 1.255 * 0.97 / 1.005, 1e-9);

        // Alone it adds its weight and nothing else.
        $alone = engineStats(['cooling' => 'intercooler-front-t4']);

        expect($alone['accel'])->toEqualWithDelta(97 / 1.005, 1e-9)
            ->and($alone['top_speed'])->toEqualWithDelta(engineStats()['top_speed'], 1e-9);
    });

    it('adds the mass of the parts to the body: handling and braking follow it, and a radiator that is lighter than the factory one helps', function () {
        $stock = engineStats();
        $light = engineStats(['cooling' => 'radiator-aluminium-t3']);
        $heavy = engineStats(['cooling' => 'intercooler-race-t8']);

        expect($light['accel'])->toEqualWithDelta(97 / 0.997, 1e-9)
            ->and($light['handling'])->toBeGreaterThan($stock['handling'])
            ->and($heavy['handling'])->toBeLessThan($stock['handling'])
            ->and($heavy['braking'])->toBeLessThan($stock['braking']);
    });

    it('gives a gearbox its launch and its limit of speed', function () {
        $stock = engineStats();

        expect(engineStats(['gearbox' => 'manual-6-gearbox-t3'])['accel'])->toEqualWithDelta(97 * 1.02, 1e-9)
            ->and(engineStats(['gearbox' => 'automatic-6-gearbox-t4'])['accel'])->toEqualWithDelta(97 * 0.98, 1e-9)
            ->and(engineStats(['gearbox' => 'manual-5-gearbox-t2'])['accel'])->toEqualWithDelta($stock['accel'], 1e-9)
            // Manual 4 stops the car at 150 km/h, and Manual 6 lets the 175 km/h of the body through.
            ->and(engineStats(['gearbox' => 'manual-4-gearbox-t1'])['top_speed'])->toBe(150.0)
            ->and(engineStats(['gearbox' => 'manual-6-gearbox-t3'])['top_speed'])->toEqualWithDelta($stock['top_speed'], 1e-9);
    });

    it('lets the load of the engine wear its parts faster: the booster adds, the intercooler and the radiator take off, and never under nothing', function () {
        $rules = app(Rules::class);
        $factory = app(RunInputsFactory::class);
        $surface = $rules->track('zlomowo-street')['surface'];
        $wear = fn (string $slot, array $mounted, int $load): int => $rules->wearPerKmPpb($slot, $surface, $factory->power('rdzak-gt-4', $mounted), $load);

        $turbo = ['boost' => 'turbo-osemka-t2'];
        $cooled = [...$turbo, 'cooling' => 'intercooler-front-t4'];
        $roots = ['boost' => 'roots-mini-t2', 'cooling' => 'radiator-oil-water-t7'];

        // A load of 8, of 8 - 4, and of 4 - 9 which is nothing; the tires do not feel it.
        expect(enginePerKm('boost', $turbo))->toBe($wear('boost', $turbo, 8))
            ->and(enginePerKm('boost', $cooled))->toBe($wear('boost', $cooled, 4))
            ->and(enginePerKm('boost', $roots))->toBe($wear('boost', $roots, 0))
            ->and(enginePerKm('boost', $turbo))->toBeGreaterThan(enginePerKm('boost', $cooled))
            // An intercooler without a booster takes nothing off, and a car without a booster has no load.
            ->and(enginePerKm('cooling', ['cooling' => 'intercooler-front-t4']))->toBe($wear('cooling', ['cooling' => 'intercooler-front-t4'], 0))
            ->and(enginePerKm('tires', [...$turbo, 'tires' => 'street-tires-t2']))->toBe($wear('tires', [...$turbo, 'tires' => 'street-tires-t2'], 0));
    });

    it('wears the parts of the engine 18% faster for a load of 18, and the rest not at all', function () {
        $rules = app(Rules::class);

        $plain = $rules->wearPerKmPpb('engine', 'asphalt', 97);

        expect($rules->wearPerKmPpb('engine', 'asphalt', 97, 18))->toBe((int) round($plain * 1.18))
            ->and($rules->wearPerKmPpb('gearbox', 'asphalt', 97, 18))->toBe((int) round($rules->wearPerKmPpb('gearbox', 'asphalt', 97) * 1.18))
            ->and($rules->wearPerKmPpb('tires', 'asphalt', 97, 18))->toBe($rules->wearPerKmPpb('tires', 'asphalt', 97));
    });
});

describe('what a car can take', function () {
    it('refuses a turbo for six cylinders on an engine of four, and an intercooler with no booster, and says why', function () {
        [$driver] = engineDriver();
        $grande = engineStock($driver, 'turbo-grande-t6');
        $intercooler = engineStock($driver, 'intercooler-factory-t2');

        engineMount($driver, $grande->id)->assertStatus(422)
            ->assertJsonPath('code', 'part_requires_cylinders')
            ->assertJsonPath('message', 'This part needs an engine with at least 6 cylinders (this car has 4).');
        engineMount($driver, $intercooler->id)->assertStatus(422)->assertJsonPath('code', 'part_requires_booster');
    });

    it('takes the turbo for four cylinders, and then the intercooler: a booster that is on its way counts', function () {
        [$driver] = engineDriver();
        $turbo = engineStock($driver, 'turbo-osemka-t2');
        $intercooler = engineStock($driver, 'intercooler-factory-t2');

        engineMount($driver, $turbo->id)->assertCreated();
        engineMount($driver, $intercooler->id)->assertCreated()->assertJsonCount(2, 'data.workshop.jobs');
    });

    it('takes the turbo for six cylinders on a V6', function () {
        [$driver] = engineDriver('kornet-v6');
        $grande = engineStock($driver, 'turbo-grande-t6');

        engineMount($driver, $grande->id)->assertCreated()->assertJsonPath('data.workshop.jobs.0.part.slug', 'turbo-grande-t6');
    });

    it('does not count a booster that is on its way off for the intercooler', function () {
        [$driver, $run] = engineDriver();
        $turbo = Part::factory()->state(['part_slug' => 'turbo-osemka-t2', 'slot' => 'boost'])->mountedOn($run->car)->create();
        $intercooler = engineStock($driver, 'intercooler-factory-t2');

        // With the booster on the car it is taken.
        engineMount($driver, $intercooler->id)->assertCreated();

        // Once the taking off of the booster is queued, the next intercooler is refused.
        test()->postJson('/api/v1/garage/jobs', ['kind' => 'unmount', 'part' => $turbo->id], bearerFor($driver))->assertCreated();
        $second = engineStock($driver, 'intercooler-front-t4');

        engineMount($driver, $second->id)->assertStatus(422)->assertJsonPath('code', 'part_requires_booster');
    });

    it('says in the garage what a part of the inventory lacks, before the driver asks for the job', function () {
        [$driver] = engineDriver();
        engineStock($driver, 'turbo-grande-t6');
        engineStock($driver, 'intercooler-factory-t2');
        engineStock($driver, 'roots-mini-t2');

        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.inventory.0.mount_next.locked', ['reason' => 'cylinders', 'required' => 6, 'current' => 4])
            ->assertJsonPath('data.inventory.1.mount_next.locked', ['reason' => 'booster'])
            ->assertJsonPath('data.inventory.2.mount_next.locked', null)
            ->assertJsonPath('data.inventory.0.requires', ['min_cylinders' => 6])
            ->assertJsonPath('data.inventory.2.requires', null);
    });

    it('says in the shop what the car lacks besides its level, and does not call such a part mountable', function () {
        [$driver] = engineDriver();

        $parts = collect(test()->getJson('/api/v1/shop', bearerFor($driver))->assertOk()->json('data.parts'))->keyBy('slug');

        expect($parts['turbo-grande-t6']['blocked_by'])->toBe(['reason' => 'cylinders', 'required' => 6, 'current' => 4])
            ->and($parts['turbo-grande-t6']['mountable'])->toBeFalse()
            ->and($parts['intercooler-factory-t2']['blocked_by'])->toBe(['reason' => 'booster'])
            ->and($parts['roots-mini-t2']['blocked_by'])->toBeNull()
            ->and($parts['roots-mini-t2']['mountable'])->toBeTrue()
            ->and($parts['street-tires-t2']['blocked_by'])->toBeNull()
            ->and($parts['biturbo-pro-t9']['requires'])->toBe(['min_cylinders' => 8]);
    });

    it('lets a V8 take the Bi-turbo Pro, and counts a booster on the car for the shop', function () {
        [$driver, $run] = engineDriver('bizon-v8');
        Part::factory()->state(['part_slug' => 'roots-mini-t2', 'slot' => 'boost'])->mountedOn($run->car)->create();

        $parts = collect(test()->getJson('/api/v1/shop', bearerFor($driver))->assertOk()->json('data.parts'))->keyBy('slug');

        expect($parts['biturbo-pro-t9']['blocked_by'])->toBeNull()
            ->and($parts['intercooler-large-t5']['blocked_by'])->toBeNull();
    });
});

describe('the shop and the tiers', function () {
    it('shows the engine group as the car rises: up to one tier above what it can have mounted', function () {
        [$driver, $run] = engineDriver('rdzak-gt-4', 3);
        $tiers = fn (): array => collect(test()->getJson('/api/v1/shop', bearerFor($driver))->assertOk()->json('data.parts'))->pluck('tier')->unique()->sort()->values()->all();

        // A car of level 3 can mount tier 2, and sees tier 3.
        expect($tiers())->toBe([1, 2, 3]);

        $run->car->forceFill(['level' => 12])->save();
        expect($tiers())->toBe([1, 2, 3, 4, 5, 6]);

        $run->car->forceFill(['level' => 30])->save();
        expect($tiers())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9])
            ->and(test()->getJson('/api/v1/shop', bearerFor($driver))->json('data.parts'))->toHaveCount(40);
    });

    it('does not sell a part that the shop does not show to the car yet', function () {
        [$driver, $run] = engineDriver('rdzak-gt-4', 3, 20_000_000);

        test()->postJson('/api/v1/shop/purchases', ['part' => 'biturbo-pro-t9', 'request_id' => 'a'], bearerFor($driver))
            ->assertStatus(422)->assertJsonPath('code', 'part_not_for_sale');

        test()->postJson('/api/v1/shop/purchases', ['part' => 'roots-mini-t2', 'request_id' => 'b'], bearerFor($driver))->assertCreated();

        $run->car->forceFill(['level' => 30])->save();
        test()->postJson('/api/v1/shop/purchases', ['part' => 'biturbo-pro-t9', 'request_id' => 'c'], bearerFor($driver))->assertCreated();
    });
});

describe('the crates', function () {
    it('draw parts for the slots of the shop that they always did, and not for the engine group', function () {
        [$driver] = engineDriver();

        $slots = collect(test()->getJson('/api/v1/shop', bearerFor($driver))->assertOk()->json('data.crates.kinds'))
            ->flatMap(fn (array $kind): array => array_column($kind['slots'], 'slot'))
            ->unique()->sort()->values()->all();

        // Which of the four are open depends on the level of the driver; the slots of the engine group are never among them.
        expect($slots)->not->toBeEmpty()->and(array_diff($slots, ['brakes', 'exhaust', 'suspension', 'tires']))->toBe([]);
    });
});
