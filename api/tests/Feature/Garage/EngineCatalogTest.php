<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Models\Car;
use App\Models\Part;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/** The stats of the stock Rdzak GT-4 (97 kW from a factory R4 1.8, 1 000 kg, 175 km/h) with these parts on it, by slot. */
function catalogStats(array $mounted = []): array
{
    return app(RunInputsFactory::class)->stats('rdzak-gt-4', $mounted)->stats;
}

/** A driver at T0 whose car is of this model and level (the seed is fixed, so it does not break down in the middle of a test). */
function catalogDriver(string $vehicle = 'rdzak-gt-4', int $level = 30): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    $run->car->forceFill(['vehicle_slug' => $vehicle, 'level' => $level])->save();
    (new Ledger)->post($driver->id, 50_000_000, 'gift', "engine-catalog-test:{$driver->id}");

    return [$driver, $run->car];
}

function catalogStock(User $driver, string $slug): Part
{
    return Part::factory()->create(['user_id' => $driver->id, 'part_slug' => $slug, 'slot' => app(Catalog::class)->part($slug)['slot']]);
}

function catalogOn(Car $car, string $slug, array $attributes = []): Part
{
    return Part::factory()->state(['part_slug' => $slug, 'slot' => app(Catalog::class)->part($slug)['slot']])->mountedOn($car)->create($attributes);
}

function catalogMount(User $driver, int $partId): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $partId], bearerFor($driver));
}

describe('the catalog of the engines', function () {
    it('has the 31 models of the board: a layout, a displacement and a tier, as the slot engine', function () {
        $catalog = app(Catalog::class);
        $engines = array_values(array_filter(app(Rules::class)->shopParts(), fn (string $slug): bool => $catalog->part($slug)['slot'] === 'engine'));
        $byLayout = [];

        foreach ($engines as $slug) {
            $byLayout[$catalog->part($slug)['engine']['layout']][] = $catalog->part($slug)['engine']['displacement_l'].' ('.$catalog->part($slug)['tier'].')';
        }

        expect($engines)->toHaveCount(31)
            ->and($byLayout['R4'])->toBe(['1 (1)', '1.3 (1)', '1.6 (2)', '1.8 (2)', '2 (3)', '2.2 (4)', '2.5 (5)'])
            ->and($byLayout['V6'])->toBe(['2.5 (3)', '2.8 (4)', '3.2 (5)', '3.5 (6)', '4 (7)'])
            ->and($byLayout['R6'])->toBe(['2 (4)', '2.5 (5)', '3 (6)', '3.5 (7)', '4 (8)'])
            ->and($byLayout['V8'])->toBe(['4 (5)', '4.5 (6)', '5 (7)', '5.7 (8)', '6.2 (8)', '6.5 (9)'])
            ->and($byLayout['V10'])->toBe(['5 (7)', '5.2 (8)', '5.7 (9)', '6 (10)'])
            ->and($byLayout['V12'])->toBe(['5.5 (8)', '6 (9)', '6.5 (10)', '7 (10)']);
    });

    it('makes the power and the mass of every model from its layout, its litres and its tier, and prices it by the litre and the tier', function () {
        $rules = app(Rules::class);
        $catalog = app(Catalog::class);
        $pricePerLitre = ['R4' => 675, 'V6' => 825, 'R6' => 975, 'V8' => 1125, 'V10' => 1800, 'V12' => 2400];

        foreach ($rules->shopParts() as $slug) {
            $part = $catalog->part($slug);

            if ($part['slot'] !== 'engine') {
                continue;
            }

            ['layout' => $layout, 'displacement_l' => $litres] = $part['engine'];

            expect($part['stats']['power_kw'])->toEqualWithDelta($rules->enginePowerKw($layout, $litres, $part['tier']), 0.005, "{$slug} power")
                ->and($part['stats']['engine_mass_kg'])->toEqualWithDelta($rules->engineMassKg($layout, $litres), 0.005, "{$slug} mass")
                // The litres times the price of a litre, grown by 35% for every tier above the first.
                ->and($part['price_cents'])->toBe((int) round($litres * $pricePerLitre[$layout] * 1.35 ** ($part['tier'] - 1) * 100), "{$slug} price");
        }

        // The examples of the board: R6 3.0 in tier 6 costs about $13 100; at tier 1 R6 2.0 makes 124 kW and R6 3.0 makes 186 kW, and weighs 166 and 204 kg.
        expect($catalog->part('engine-r6-3-0-t6')['price_cents'])->toBe(1_311_580)
            ->and($rules->enginePowerKw('R6', 2.0, 1))->toBe(124.0)
            ->and($rules->enginePowerKw('R6', 3.0, 1))->toBe(186.0)
            ->and($rules->engineMassKg('R6', 2.0))->toBe(166.0)
            ->and($rules->engineMassKg('R6', 3.0))->toBe(204.0);
    });

    it('fits the factory engine of every car of the pack in the bay of its body', function () {
        $rules = app(Rules::class);
        $catalog = app(Catalog::class);

        foreach ($catalog->vehicles() as $vehicle) {
            if (($vehicle['powertrain'] ?? null) !== 'ice') {
                continue;
            }

            $bay = $rules->engineBay($vehicle['class']);

            expect($bay)->not->toBeNull("{$vehicle['slug']} has a class with no bay")
                ->and($bay['layouts'])->toContain($vehicle['engine']['layout'])
                ->and($vehicle['engine']['displacement_l'])->toBeLessThanOrEqual($bay['max_displacement_l']);
        }
    });

    it('has the engine of a car as its power, as the board says: the litres, the layout, the efficiency of the tier and the tuning', function () {
        $rules = app(Rules::class);
        $catalog = app(Catalog::class);

        foreach ($catalog->vehicles() as $vehicle) {
            if (($vehicle['powertrain'] ?? null) !== 'ice') {
                continue;
            }

            $formula = $rules->enginePowerKw($vehicle['engine']['layout'], $vehicle['engine']['displacement_l'], $vehicle['tier']) * $vehicle['engine']['tune'];

            expect($vehicle['power_kw'])->toEqualWithDelta($formula, max(1.5, $formula * 0.02), "{$vehicle['slug']} power");
        }
    });
});

describe('what an engine does to the car', function () {
    it('gives the car its power instead of the one of the factory engine, and its mass instead of the mass of the factory engine', function () {
        // R4 2.5 of tier 5: 50 x 2.5 x 1.3 = 162.5 kW and 55 + 25 x 2.5 = 117.5 kg, where the factory R4 1.8 weighs 100: the car is 17.5 kg heavier.
        $stats = catalogStats(['engine' => 'engine-r4-2-5-t5']);

        expect($stats['accel'])->toEqualWithDelta(162.5 / 1.0175, 1e-9)
            ->and($stats['top_speed'])->toEqualWithDelta(175 * (162.5 / 97) ** (1 / 3), 1e-9);

        // The model that is the factory engine of the Rdzak (R4 1.8) changes nothing but the 0.25 kW the factory rounds.
        expect(catalogStats(['engine' => 'engine-r4-1-8-t2'])['accel'])->toEqualWithDelta(96.75, 1e-9);
    });

    it('has the booster multiply the power of the engine, and the power of the garage count the engine alone', function () {
        $factory = app(RunInputsFactory::class);
        $mounted = ['engine' => 'engine-r4-2-5-t5', 'boost' => 'turbo-osemka-t2'];

        expect(catalogStats($mounted)['accel'])->toEqualWithDelta(162.5 * 1.25 * 0.97 / 1.0175, 1e-9)
            ->and($factory->power('rdzak-gt-4', $mounted))->toEqualWithDelta(162.5 * 1.25, 1e-9)
            ->and($factory->enginePowerKw('rdzak-gt-4', $mounted))->toEqualWithDelta(162.5, 1e-9)
            ->and($factory->enginePowerKw('rdzak-gt-4'))->toBe(97.0);
    });

    it('scales the power of an engine with its rarity, its upgrade and its condition, and not its mass', function () {
        $uncommon = catalogStats(['engine' => ['slug' => 'engine-r4-2-5-t5', 'rarity' => 2]]);
        $upgraded = catalogStats(['engine' => ['slug' => 'engine-r4-2-5-t5', 'rarity' => 1, 'upgrade' => 3]]);
        // Half of its life is gone: it gives 0.6 + 0.4 x 0.5 = 80% of its power.
        $worn = catalogStats(['engine' => ['slug' => 'engine-r4-2-5-t5', 'rarity' => 1, 'wear' => 500_000_000]]);

        expect($uncommon['accel'])->toEqualWithDelta(162.5 * 1.1 / 1.0175, 1e-6)
            ->and($upgraded['accel'])->toEqualWithDelta(162.5 * 1.09 / 1.0175, 1e-6)
            ->and($worn['accel'])->toEqualWithDelta(162.5 * 0.8 / 1.0175, 1e-6);
    });

    it('burns what the power of the engine asks, and not the gain of the booster twice', function () {
        $factory = app(RunInputsFactory::class);
        $reference = app(Rules::class)->fuelReferencePowerKw();
        $fuel = fn (array $mounted): float => $factory->stats('rdzak-gt-4', $mounted)->fuelUseMultiplier;

        expect($fuel(['engine' => 'engine-r4-2-5-t5']))->toEqualWithDelta((1 + 0.6 * 162.5 / $reference) / 1.6, 1e-9)
            ->and($fuel(['engine' => 'engine-r4-2-5-t5', 'boost' => 'turbo-osemka-t2']))->toEqualWithDelta((1 + 0.6 * 162.5 / $reference) / 1.6 * 1.15, 1e-9);
    });

    it('wears like the rest of the engine group, with the load of the booster, and its wear is on the list of the car', function () {
        $factory = app(RunInputsFactory::class);
        $plan = fn (array $mounted) => $factory->wearPlan('rdzak-gt-4', 'zlomowo-street', $mounted, ['mileage_m' => 0, 'serviced_at_m' => 0]);

        expect($plan(['engine' => 'engine-r4-2-5-t5'])->perKmPpb)->toHaveKey('engine')
            ->and($plan(['engine' => 'engine-r4-2-5-t5', 'boost' => 'turbo-osemka-t2'])->perKmPpb['engine'])->toBeGreaterThan($plan(['engine' => 'engine-r4-2-5-t5'])->perKmPpb['engine']);
    });
});

describe('the bay of the body and the engine that goes into it', function () {
    it('lets an engine into the bay when the layout is allowed and the litres fit, and refuses the rest with a reason', function () {
        [$driver] = catalogDriver();
        $fits = catalogStock($driver, 'engine-v6-2-8-t4');
        $layout = catalogStock($driver, 'engine-v8-4-0-t5');
        $size = catalogStock($driver, 'engine-v6-3-2-t5');

        // The Rdzak is a hatchback: R4 and V6 up to 3.0 litres.
        catalogMount($driver, $layout->id)->assertStatus(422)->assertJsonPath('code', 'engine_bay_layout')
            ->assertJsonPath('message', 'The engine bay of this car does not take a V8 engine (it takes R4, V6).');
        catalogMount($driver, $size->id)->assertStatus(422)->assertJsonPath('code', 'engine_bay_size')
            ->assertJsonPath('message', 'This engine is too big for the bay of this car (3.2 l, and the bay holds 3 l at the most).');
        catalogMount($driver, $fits->id)->assertCreated()->assertJsonPath('data.workshop.jobs.0.part.id', $fits->id);
    });

    it('says in the garage and in the shop what the bay does not take', function () {
        [$driver] = catalogDriver();
        catalogStock($driver, 'engine-v8-4-0-t5');
        catalogStock($driver, 'engine-v6-3-2-t5');
        catalogStock($driver, 'engine-r4-2-5-t5');

        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.inventory.0.mount_next.locked', ['reason' => 'bay_layout', 'layout' => 'V8', 'allowed' => ['R4', 'V6']])
            ->assertJsonPath('data.inventory.1.mount_next.locked', ['reason' => 'bay_size', 'displacement_l' => 3.2, 'max_displacement_l' => 3])
            ->assertJsonPath('data.inventory.2.mount_next.locked', null)
            ->assertJsonPath('data.inventory.0.engine', ['layout' => 'V8', 'displacement_l' => 4])
            ->assertJsonPath('data.inventory.0.stats.power_kw', 296.4);

        $shop = collect(test()->getJson('/api/v1/shop', bearerFor($driver))->assertOk()->json('data.parts'))->keyBy('slug');

        expect($shop['engine-v8-4-0-t5']['blocked_by'])->toBe(['reason' => 'bay_layout', 'layout' => 'V8', 'allowed' => ['R4', 'V6']])
            ->and($shop['engine-v8-4-0-t5']['mountable'])->toBeFalse()
            ->and($shop['engine-r4-2-5-t5']['blocked_by'])->toBeNull()
            ->and($shop['engine-r4-2-5-t5']['mountable'])->toBeTrue();
    });

    it('takes a bigger engine in a bigger body: the muscle car takes a V10, the hatchback does not', function () {
        [$driver] = catalogDriver('bizon-v8');
        $v10 = catalogStock($driver, 'engine-v10-5-0-t7');

        catalogMount($driver, $v10->id)->assertCreated();
    });

    it('sells an engine at the price of the board, and holds the tier of the engine to the level of the car', function () {
        [$driver, $car] = catalogDriver('rdzak-gt-4', 3);

        test()->postJson('/api/v1/shop/purchases', ['part' => 'engine-r4-2-5-t5', 'request_id' => 'a'], bearerFor($driver))
            ->assertStatus(422)->assertJsonPath('code', 'part_not_for_sale');

        $car->forceFill(['level' => 12])->save();

        // 2.5 litres at $675 a litre, grown by 35% for each of the four tiers above the first.
        test()->postJson('/api/v1/shop/purchases', ['part' => 'engine-r4-2-5-t5', 'request_id' => 'b'], bearerFor($driver))
            ->assertCreated()->assertJsonPath('purchase.cost_cents', (int) round(2.5 * 675 * 1.35 ** 4 * 100));
    });
});

describe('the engine and the booster that needs cylinders', function () {
    it('counts the cylinders of the engine that is in the car for a turbo that asks for six', function () {
        [$driver, $car] = catalogDriver();
        $turbo = catalogStock($driver, 'turbo-grande-t6');

        // The factory engine of the Rdzak has four cylinders.
        catalogMount($driver, $turbo->id)->assertStatus(422)->assertJsonPath('code', 'part_requires_cylinders');

        // A V6 in the engine bay changes that.
        catalogOn($car, 'engine-v6-2-5-t3');

        catalogMount($driver, $turbo->id)->assertCreated();
    });

    it('refuses an engine that leaves the booster on the car too few cylinders, and the taking off of the engine that the booster needs', function () {
        [$driver, $car] = catalogDriver();
        $engine = catalogOn($car, 'engine-v6-2-5-t3');
        catalogOn($car, 'turbo-grande-t6');
        $smaller = catalogStock($driver, 'engine-r4-2-5-t5');

        // A turbo for six cylinders on a car that would have four.
        catalogMount($driver, $smaller->id)->assertStatus(422)->assertJsonPath('code', 'engine_booster_cylinders')
            ->assertJsonPath('message', 'The booster on this car needs an engine with at least 6 cylinders, and the one that would be in the car has 4.');

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'unmount', 'part' => $engine->id], bearerFor($driver))
            ->assertStatus(422)->assertJsonPath('code', 'engine_booster_cylinders');

        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.car.slots.3.part.unmount_next.locked', ['reason' => 'booster_cylinders', 'required' => 6, 'current' => 4])
            ->assertJsonPath('data.inventory.0.mount_next.locked', ['reason' => 'booster_cylinders', 'required' => 6, 'current' => 4]);
    });

    it('lets the engine come out when the booster is gone', function () {
        [$driver, $car] = catalogDriver();
        $engine = catalogOn($car, 'engine-v6-2-5-t3');

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'unmount', 'part' => $engine->id], bearerFor($driver))->assertCreated();
    });
});

describe('the garage and the engine', function () {
    it('names the engine that is in the car and what it makes, the factory one or the part, with a decimal in the litres', function () {
        [$driver, $car] = catalogDriver('liman-3-0');

        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.car.vehicle.engine', 'R6 3.0')
            ->assertJsonPath('data.car.vehicle.power_kw', 242)
            ->assertJsonPath('data.car.slots.3.part', null)
            ->assertJsonPath('data.car.slots.3.built_in', true);

        // A V8 4.5 of tier 6 in its place: 57 x 4.5 x 1.375 = 352.69 kW.
        catalogOn($car, 'engine-v8-4-5-t6');

        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.car.vehicle.engine', 'V8 4.5')
            ->assertJsonPath('data.car.vehicle.power_kw', 353)
            ->assertJsonPath('data.car.slots.3.part.slug', 'engine-v8-4-5-t6')
            ->assertJsonPath('data.car.slots.3.part.engine', ['layout' => 'V8', 'displacement_l' => 4.5]);
    });
});
