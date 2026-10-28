<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\PackValidator;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Models\Part;

// The affixes of the surface (what the tires grip on) and the unique effects of the legendary parts: each is checked against the same pack without the effect, so
// that what the test sees is the effect and not the part.

/** The factory of the pack, or of the pack without the unique effects of the legendary parts. */
function affixFactory(bool $withUnique = true): RunInputsFactory
{
    if ($withUnique) {
        return app(RunInputsFactory::class)->withoutBots();
    }

    $source = contentWith(['rules/starter' => function (array $rules): array {
        unset($rules['affixes']['unique']);

        return $rules;
    }]);

    return new RunInputsFactory(Catalog::fromSource($source), Rules::fromSource($source));
}

/** The slug of the first part of a slot (with a statistic, when one is asked for) in the pack. */
function affixPartOf(string $slot, ?string $stat = null): string
{
    foreach (Catalog::fromSource(repositoryContent())->parts() as $slug => $part) {
        if ($part['slot'] === $slot && ($stat === null || ($part['stats'][$stat] ?? 0) > 0)) {
            return $slug;
        }
    }

    throw new RuntimeException("The pack has no part of {$slot}.");
}

/** @return array<string, int>|string a mounted part */
function affixMounted(string $slug, int $rarity = 5, array $extra = []): array
{
    return ['slug' => $slug, 'rarity' => $rarity, ...$extra];
}

function affixLap(RunInputsFactory $factory, array $mounted, string $track = 'zlomowo-street', string $weather = 'dry', string $car = 'rdzak-gt-4'): int
{
    return (new RunFreezer)->freeze($factory->make($car, $track, $mounted), 'affix-unique', 0)->config['lap_ms'][$weather];
}

describe('the affixes of the surface', function () {
    it('make the tires grip more in the rain, and only there', function () {
        $factory = affixFactory();
        $tires = fn (array $affixes): array => ['tires' => affixMounted('street-tires-t2', 1, ['affixes' => $affixes])];

        expect(affixLap($factory, $tires(['wet' => 80_000]), weather: 'rain'))->toBeLessThan(affixLap($factory, $tires([]), weather: 'rain'))
            ->and(affixLap($factory, $tires(['wet' => 80_000])))->toBe(affixLap($factory, $tires([])));
    });

    it('make the tires grip more on a loose surface, and not on the asphalt', function () {
        $factory = affixFactory();
        $tires = fn (array $affixes): array => ['tires' => affixMounted('street-tires-t2', 1, ['affixes' => $affixes])];

        expect(affixLap($factory, $tires(['loose' => 80_000]), 'kruszyn-quarry'))->toBeLessThan(affixLap($factory, $tires([]), 'kruszyn-quarry'))
            ->and(affixLap($factory, $tires(['loose' => 80_000])))->toBe(affixLap($factory, $tires([])));
    });

    it('give a worn pair of tires less of the affix', function () {
        $factory = affixFactory();
        $tires = fn (array $affixes, int $wear): array => ['tires' => affixMounted('street-tires-t2', 1, ['affixes' => $affixes, 'wear' => $wear])];
        $gain = fn (int $wear): int => affixLap($factory, $tires([], $wear), 'kruszyn-quarry') - affixLap($factory, $tires(['loose' => 80_000], $wear), 'kruszyn-quarry');

        expect($gain(0))->toBeGreaterThan($gain(600_000_000))->and($gain(600_000_000))->toBeGreaterThan(0);
    });

    it('are said by the client with the surfaces they are about', function () {
        [$driver] = driverWithRun();
        Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 3, 'affixes' => ['wet' => 50_000]]);

        $affix = test()->getJson('/api/v1/garage', [...bearerFor($driver), 'X-Locale' => 'en'])->assertOk()->json('data.inventory.0.affixes.0');

        expect($affix)->toMatchArray(['key' => 'wet', 'name' => 'Wet grip', 'effect' => 'surface', 'surfaces' => ['asphalt_wet', 'mud'], 'percent' => 5]);
    });
});

describe('the unique effect of a legendary part', function () {
    it('makes the adaptive compound lose half of what the tires lose on a surface that does not suit them', function () {
        $with = affixFactory();
        $without = affixFactory(false);
        // Off-road tires on dry asphalt are the ones that lose.
        $offroad = fn (int $rarity): array => ['tires' => affixMounted('offroad-tires-t2', $rarity)];

        expect(affixLap($with, $offroad(5)))->toBeLessThan(affixLap($without, $offroad(5)))
            // A part that is not legendary has no unique effect, and the pack without it does the same for all of them.
            ->and(affixLap($with, $offroad(4)))->toBe(affixLap($without, $offroad(4)));
    });

    it('makes the adaptive dampers take half of the mass penalty off the handling of a heavy car', function () {
        $with = affixFactory();
        $without = affixFactory(false);
        $heavy = collect(Catalog::fromSource(repositoryContent())->vehicles())->sortByDesc('mass_kg')->keys()->first();
        $dampers = ['suspension' => affixMounted(affixPartOf('suspension', 'handling'))];

        expect($with->stats($heavy, $dampers)->stats['handling'])->toBeGreaterThan($without->stats($heavy, $dampers)->stats['handling'])
            // The braking, which the mass also costs, is not what the dampers are about.
            ->and($with->stats($heavy, $dampers)->stats['braking'])->toBe($without->stats($heavy, $dampers)->stats['braking']);
    });

    it('makes fade-free brakes brake as new down to half of their condition, and less below it', function () {
        $with = affixFactory();
        $without = affixFactory(false);
        $brakes = fn (int $wear): array => ['brakes' => affixMounted(affixPartOf('brakes', 'braking'), 5, ['wear' => $wear])];
        $braking = fn (RunInputsFactory $factory, int $wear): float => $factory->stats('rdzak-gt-4', $brakes($wear))->stats['braking'];

        // Fresh, and 40% worn (60% of the life left, over the half): the same as new. 70% worn (30% left): worn, but less than the same brakes without the effect.
        expect($braking($with, 0))->toBe($braking($without, 0))
            ->and($braking($with, 400_000_000))->toBe($braking($with, 0))->and($braking($without, 400_000_000))->toBeLessThan($braking($with, 400_000_000))
            ->and($braking($with, 700_000_000))->toBeLessThan($braking($with, 0))->and($braking($with, 700_000_000))->toBeGreaterThan($braking($without, 700_000_000));
    });

    it('makes the free flow of the exhaust give 2% more power and 3% less fuel', function () {
        $with = affixFactory();
        $without = affixFactory(false);
        $exhaust = ['exhaust' => affixMounted(affixPartOf('exhaust', 'power_mult'))];

        expect($with->power('rdzak-gt-4', $exhaust))->toEqualWithDelta($without->power('rdzak-gt-4', $exhaust) * 1.02, 0.001)
            // The 3% off the fuel, less what the 2% more power burns.
            ->and($with->stats('rdzak-gt-4', $exhaust)->fuelUseMultiplier)->toBeLessThan($without->stats('rdzak-gt-4', $exhaust)->fuelUseMultiplier * 0.99)
            ->and(affixFactory()->power('rdzak-gt-4', ['exhaust' => affixMounted(affixPartOf('exhaust', 'power_mult'), 4)]))->toEqualWithDelta($without->power('rdzak-gt-4', ['exhaust' => affixMounted(affixPartOf('exhaust', 'power_mult'), 4)]), 0.0001);
    });

    it('makes the tuned block give 1.5% more power and a tenth fewer breakdowns', function () {
        $with = affixFactory();
        $without = affixFactory(false);
        $engine = ['engine' => affixMounted(affixPartOf('engine', 'power_kw'))];
        $breaks = fn (RunInputsFactory $factory): int => $factory->make('rdzak-gt-4', 'zlomowo-street', $engine)->breakChancePerLapPpm;

        expect($with->enginePowerKw('rdzak-gt-4', $engine))->toEqualWithDelta($without->enginePowerKw('rdzak-gt-4', $engine) * 1.015, 0.01)
            ->and($breaks($with))->toBe((int) round($breaks($without) * 0.9));
    });

    it('makes the compensated lag of the booster take half of its lag off the start', function () {
        $with = affixFactory();
        $without = affixFactory(false);
        $boost = ['boost' => affixMounted(affixPartOf('boost', 'lag'))];

        expect($with->stats('rdzak-gt-4', $boost)->stats['accel'])->toBeGreaterThan($without->stats('rdzak-gt-4', $boost)->stats['accel']);
    });

    it('is said by the client for a legendary part and for no other', function () {
        [$driver] = driverWithRun();
        $legend = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 5]);
        $epic = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 4]);
        $exhaust = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => affixPartOf('exhaust'), 'slot' => 'exhaust', 'rarity' => 5]);

        $inventory = collect(test()->getJson('/api/v1/garage', [...bearerFor($driver), 'X-Locale' => 'pl'])->assertOk()->json('data.inventory'))->keyBy('id');

        expect($inventory[$legend->id]['unique'])->toEqual(['name' => 'Adaptacyjna mieszanka', 'special' => 'surface_loss', 'percent' => 50, 'mods' => null])
            ->and($inventory[$epic->id]['unique'])->toBeNull()
            ->and($inventory[$exhaust->id]['unique']['mods'])->toEqual([['effect' => 'stat', 'stat' => 'power', 'percent' => 2], ['effect' => 'fuel', 'stat' => null, 'percent' => 3]]);
    });
});

describe('the content of the unique effects and of the surface affixes', function () {
    it('has a unique effect for the slots that the crates draw and for the engine and the booster, each with a name in five languages', function () {
        $unique = app(Rules::class)->affixes()['unique'] ?? [];

        expect(array_keys($unique))->toBe(['tires', 'suspension', 'brakes', 'exhaust', 'engine', 'boost']);

        foreach ($unique as $slot => $effect) {
            expect(array_keys($effect['name']))->toBe(['en', 'pl', 'fr', 'de', 'pt-BR'], $slot);
        }
    });

    it('is refused by the schema when a surface affix names no surface, or a unique effect is neither a special effect nor modifiers, or both', function () {
        $refused = fn (Closure $change): bool => ! PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => function (array $rules) use ($change): array {
            $change($rules);

            return $rules;
        }]))->isValid();

        expect($refused(function (array &$rules): void {
            unset($rules['affixes']['definitions']['wet']['surfaces']);
        }))->toBeTrue()
            ->and($refused(function (array &$rules): void {
                $rules['affixes']['definitions']['wet']['surfaces'] = ['ice'];
            }))->toBeTrue()
            ->and($refused(function (array &$rules): void {
                $rules['affixes']['definitions']['grip']['surfaces'] = ['mud'];
            }))->toBeTrue()
            ->and($refused(function (array &$rules): void {
                unset($rules['affixes']['unique']['tires']['special'], $rules['affixes']['unique']['tires']['ppm']);
            }))->toBeTrue()
            ->and($refused(function (array &$rules): void {
                $rules['affixes']['unique']['exhaust']['special'] = 'lag_cut';
                $rules['affixes']['unique']['exhaust']['ppm'] = 500_000;
            }))->toBeTrue()
            ->and($refused(function (array &$rules): void {
                $rules['affixes']['unique']['tires']['special'] = 'something-else';
            }))->toBeTrue();
    });
});
