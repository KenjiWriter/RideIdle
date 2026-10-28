<?php

declare(strict_types=1);

use App\Content\PackValidator;
use App\Content\RarityPace;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Market\Affixes;
use App\Domain\Run\Rarity;
use App\Domain\Run\RunFreezer;
use App\Models\Car;
use App\Models\CrateOpening;
use App\Models\Part;
use App\Models\User;
use App\Services\Ledger;
use App\Services\PartAffixes;
use Illuminate\Support\Carbon;

// The affixes of the parts: how many a rarity gives and which, drawn once from a seed and kept; what a crate and a merge do with them; what they do to a car (a
// statistic of the part, how fast it wears, the fuel and the breakdowns); how the client sees them; and the command that gives them to the parts from before.

afterEach(fn () => Carbon::setTestNow());

function affixDraw(): Affixes
{
    return new Affixes(app(Rules::class)->affixes());
}

/** A driver of level 1 with cash, and everything to open crates with. */
function affixDriver(int $cashCents = 200_000): User
{
    $driver = Car::factory()->create()->user;

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach(1));
    (new Ledger)->post($driver->id, $cashCents, 'gift', 'money:'.$driver->id);

    return $driver;
}

/** @return array<string, mixed> the part that the first crate of the driver gives, as the client gets it */
function affixCrate(User $driver, int $openings = 0): array
{
    // Crates that were opened before, all common, so that the next one is the one the guarantees of the group say.
    for ($index = 0; $index < $openings; $index++) {
        CrateOpening::query()->create([
            'user_id' => $driver->id, 'kind' => 'slot', 'pity_group' => 'standard', 'opening_index' => $index, 'slot' => 'tires', 'tier' => 1,
            'rarity' => 1, 'part_slug' => 'all-season-tires-t1', 'price_cents' => 45_000,
        ]);
    }

    return test()->postJson('/api/v1/shop/crates/slot/open', ['request_id' => 'a1', 'slot' => 'tires'], [...bearerFor($driver), 'X-Locale' => 'en'])->assertCreated()->json('opened');
}

describe('how many affixes a rarity gives, and which', function () {
    it('gives none to a common part, one to an uncommon, one or two to a rare, two to an epic and three to a legendary', function () {
        $draw = affixDraw();
        $counts = function (Rarity $rarity) use ($draw): array {
            $found = array_unique(array_map(fn (int $i): int => $draw->countFor("seed-{$i}", $rarity), range(1, 400)));
            sort($found);

            return $found;
        };

        expect($counts(Rarity::Common))->toBe([0])->and($counts(Rarity::Uncommon))->toBe([1])->and($counts(Rarity::Rare))->toBe([1, 2])
            ->and($counts(Rarity::Epic))->toBe([2])->and($counts(Rarity::Legendary))->toBe([3]);
    });

    it('gives a rare part a second affix half of the time', function () {
        $draw = affixDraw();
        $two = count(array_filter(range(1, 20_000), fn (int $i): bool => $draw->countFor("count-{$i}", Rarity::Rare) === 2));

        expect(abs($two / 20_000 - 0.5))->toBeLessThan(0.015);
    });

    it('draws among the affixes that fit the slot, never the same one twice, with an amount inside its range', function () {
        $definitions = app(Rules::class)->affixes()['definitions'];

        foreach (range(1, 300) as $i) {
            $affixes = affixDraw()->roll("legend-{$i}", 'tires', Rarity::Legendary);

            expect($affixes)->toHaveCount(3)->and(array_keys($affixes))->each->toBeIn(['grip', 'handling', 'braking', 'wear']);

            foreach ($affixes as $key => $amount) {
                expect($amount)->toBeGreaterThanOrEqual($definitions[$key]['min_ppm'])->and($amount)->toBeLessThanOrEqual($definitions[$key]['max_ppm']);
            }
        }
    });

    it('is the same for the same seed, and gives a slot as many as fit it', function () {
        $draw = affixDraw();

        expect($draw->roll('same', 'brakes', Rarity::Epic))->toBe($draw->roll('same', 'brakes', Rarity::Epic))
            // The tank only has the durability to give, so even a legendary one has that and nothing else.
            ->and(array_keys($draw->roll('tank', 'tank', Rarity::Legendary)))->toBe(['wear']);
    });

    it('draws by weight: the affixes of a stat are drawn more than the ones of a power', function () {
        $draw = affixDraw();
        $drawn = [];

        foreach (range(1, 12_000) as $i) {
            foreach (array_keys($draw->roll("weights-{$i}", 'suspension', Rarity::Uncommon)) as $key) {
                $drawn[$key] = ($drawn[$key] ?? 0) + 1;
            }
        }

        // Handling and clearance weigh 3, durability and reliability 2: a suspension has these four.
        expect($drawn['handling'] / 12_000)->toBeGreaterThan(0.28)->and($drawn['handling'] / 12_000)->toBeLessThan(0.38)
            ->and($drawn['wear'] / 12_000)->toBeGreaterThan(0.17)->and($drawn['wear'] / 12_000)->toBeLessThan(0.25);
    });

    it('keeps the ones a part has and gets the ones it lacks', function () {
        $has = ['grip' => 31_000];

        $epic = affixDraw()->roll('top-up', 'tires', Rarity::Epic, $has);

        expect($epic)->toHaveCount(2)->and($epic['grip'])->toBe(31_000)->and(array_key_first($epic))->toBe('grip');
        // A part that has enough is not given more.
        expect(affixDraw()->roll('top-up', 'tires', Rarity::Uncommon, $has))->toBe($has);
    });
});

describe('the crates', function () {
    it('give a part the affixes of its rarity, drawn from the seed of the part, and the client sees them', function () {
        $driver = affixDriver();

        $opened = affixCrate($driver);
        $part = Part::query()->findOrFail($opened['id']);
        $rarity = Rarity::from($part->rarity);
        $expected = affixDraw()->roll(app(PartAffixes::class)->seed($part, $rarity), 'tires', $rarity);

        expect($part->affixes ?? [])->toBe($expected)
            ->and(count($part->affixes ?? []))->toBe(affixDraw()->countFor(app(PartAffixes::class)->seed($part, $rarity), $rarity))
            ->and(array_column($opened['affixes'], 'key'))->toBe(array_keys($expected));
    });

    it('give a part that was drawn rare or better affixes at once', function () {
        $driver = affixDriver();
        // Nine crates before, so that the tenth is the one that the guarantee of the group makes a rare one or better.
        $opened = affixCrate($driver, 9);

        expect($opened['rarity_rank'])->toBeGreaterThanOrEqual(3)->and(count($opened['affixes']))->toBeGreaterThanOrEqual(1);
    });
});

describe('merging', function () {
    it('keeps the affixes of the part that becomes the next rarity, and gives it what it lacks', function () {
        $driver = User::factory()->guest()->create();
        $part = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 3, 'affixes' => ['handling' => 33_000, 'wear' => 150_000]]);

        app(PartAffixes::class)->give($part, Rarity::Legendary);

        expect($part->refresh()->affixes)->toHaveCount(3)->and(array_slice($part->affixes, 0, 2, true))->toBe(['handling' => 33_000, 'wear' => 150_000]);
    });

    it('does not change a part that has what its rarity asks, and does not give a common one anything', function () {
        $driver = User::factory()->guest()->create();
        $part = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 2, 'affixes' => ['grip' => 25_000]]);
        $common = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 1]);

        app(PartAffixes::class)->give($part, Rarity::Uncommon);
        app(PartAffixes::class)->give($common, Rarity::Common);

        expect($part->refresh()->affixes)->toBe(['grip' => 25_000])->and($common->refresh()->affixes)->toBeNull();
    });
});

describe('what the affixes do to a car', function () {
    it('multiply the statistic of the part by 1 + the amount, the power of an engine or of a gain of power, and nothing else', function () {
        $rules = app(Rules::class);
        $tires = ['grip' => 100, 'handling' => 98, 'braking' => 100];

        expect($rules->scaledStats($tires, Rarity::Common, 0, 1_000_000, ['grip' => 40_000]))->toEqual(['grip' => 104, 'handling' => 98, 'braking' => 100])
            // A limit and a mass are not gains, and an affix of the wear is not about a statistic.
            ->and($rules->scaledStats(['power_kw' => 200, 'engine_mass_kg' => 150], Rarity::Common, 0, 1_000_000, ['power' => 20_000, 'wear' => 100_000]))->toEqual(['power_kw' => 204, 'engine_mass_kg' => 150])
            ->and($rules->scaledStats(['power_mult' => 1.10], Rarity::Common, 0, 1_000_000, ['power' => 20_000]))->toEqual(['power_mult' => 1.122])
            // The rarity multiplies as before, and the affix on top of it.
            ->and($rules->scaledStats(['grip' => 100], Rarity::Epic, 0, 1_000_000, ['grip' => 50_000]))->toEqual(['grip' => 144.9])
            // An affix that the pack does not have gives nothing.
            ->and($rules->scaledStats($tires, Rarity::Common, 0, 1_000_000, ['nothing' => 90_000]))->toBe($tires);
    });

    it('give a worn part less of the affix, as of the rest of it', function () {
        $rules = app(Rules::class);
        $fresh = $rules->scaledStats(['grip' => 100], Rarity::Common, 0, 1_000_000, ['grip' => 50_000])['grip'];
        $worn = $rules->scaledStats(['grip' => 100], Rarity::Common, 0, 500_000, ['grip' => 50_000])['grip'];
        $wornWithout = $rules->scaledStats(['grip' => 100], Rarity::Common, 0, 500_000)['grip'];

        expect($fresh)->toEqual(105)->and($worn)->toBeLessThan($fresh)->and($worn)->toBeGreaterThan($wornWithout);
    });

    it('make a lap shorter when the tires have an affix of grip', function () {
        $factory = app(RunInputsFactory::class)->withoutBots();
        $lap = fn (array $affixes): int => (new RunFreezer)->freeze($factory->make('rdzak-gt-4', 'zlomowo-street', ['tires' => ['slug' => 'street-tires-t2', 'rarity' => 1, 'affixes' => $affixes]]), 'affix', 0)->config['lap_ms']['dry'];

        expect($lap(['grip' => 50_000]))->toBeLessThan($lap([]));
    });

    it('make a part wear slower by the amount of the affix of durability, and the others as before', function () {
        $factory = app(RunInputsFactory::class);
        $odometer = ['mileage_m' => 0, 'serviced_at_m' => 0];
        $set = fn (array $affixes): array => [
            'tires' => ['slug' => 'street-tires-t2', 'rarity' => 1, 'affixes' => $affixes],
            'brakes' => ['slug' => 'disc-brakes-t2', 'rarity' => 1],
        ];

        $plain = $factory->wearPlan('rdzak-gt-4', 'zlomowo-street', $set([]), $odometer);
        $durable = $factory->wearPlan('rdzak-gt-4', 'zlomowo-street', $set(['wear' => 200_000]), $odometer);

        expect($durable->perKmPpb['tires'])->toBe((int) round($plain->perKmPpb['tires'] * 0.8))
            ->and($durable->perKmPpb['brakes'])->toBe($plain->perKmPpb['brakes']);
    });

    it('make the car burn less fuel by the affix of efficiency of a part', function () {
        $factory = app(RunInputsFactory::class);
        $fuel = fn (array $affixes): float => $factory->stats('rdzak-gt-4', ['exhaust' => ['slug' => 'sport-exhaust-t2', 'rarity' => 1, 'affixes' => $affixes]])->fuelUseMultiplier;

        expect($fuel(['fuel' => 50_000]))->toEqualWithDelta($fuel([]) * 0.95, 0.000001);
    });

    it('make a breakdown less likely by the affixes of reliability of the parts, each taking its share', function () {
        $factory = app(RunInputsFactory::class);
        $chance = fn (array $brakes, array $tires): int => $factory->make('rdzak-gt-4', 'zlomowo-street', [
            'brakes' => ['slug' => 'disc-brakes-t2', 'rarity' => 1, 'affixes' => $brakes],
            'tires' => ['slug' => 'street-tires-t2', 'rarity' => 1, 'affixes' => $tires],
        ])->breakChancePerLapPpm;

        $plain = $chance([], []);

        expect($plain)->toBeGreaterThan(0)
            ->and($chance(['reliability' => 100_000], []))->toBe((int) round($plain * 0.9))
            ->and($chance(['reliability' => 100_000], ['reliability' => 100_000]))->toBe((int) round($plain * 0.81));
    });
});

describe('what the client sees', function () {
    it('is the affixes of a part with their name in the language of the driver and their amount in percent', function () {
        [$driver] = driverWithRun();
        Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 3, 'affixes' => ['grip' => 35_000, 'wear' => 120_000]]);

        $inventory = test()->getJson('/api/v1/garage', [...bearerFor($driver), 'X-Locale' => 'pl'])->assertOk()->json('data.inventory');

        expect($inventory[0]['affixes'])->toEqual([
            ['key' => 'grip', 'name' => 'Przyczepność', 'effect' => 'stat', 'stat' => 'grip', 'value_ppm' => 35_000, 'percent' => 3.5],
            ['key' => 'wear', 'name' => 'Trwałość', 'effect' => 'wear', 'stat' => null, 'value_ppm' => 120_000, 'percent' => 12.0],
        ])->and($inventory[0]['stats']['grip'])->toBeGreaterThan(100 * 1.22);
    });

    it('is none for a part that has none, and the shop offers none', function () {
        [$driver] = driverWithRun();
        Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);

        expect(test()->getJson('/api/v1/garage', bearerFor($driver))->json('data.inventory.0.affixes'))->toBe([])
            ->and(test()->getJson('/api/v1/shop', bearerFor($driver))->json('data.parts.0.affixes') ?? [])->toBe([]);
    });
});

describe('the parts from before', function () {
    it('get their affixes once, by the command, and the rest are left as they are', function () {
        $driver = User::factory()->guest()->create();
        $old = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 4]);
        $drawn = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 2, 'affixes' => ['grip' => 25_000]]);
        $common = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => 1]);

        $this->artisan('parts:roll-affixes', ['--dry-run' => true])->expectsOutput('1 part(s) would get affixes.')->assertSuccessful();
        expect($old->refresh()->affixes)->toBeNull();

        $this->artisan('parts:roll-affixes')->expectsOutput('1 part(s) got affixes.')->assertSuccessful();

        expect($old->refresh()->affixes)->toHaveCount(2)->and($old->affixes)->toBe(affixDraw()->roll(app(PartAffixes::class)->seed($old, Rarity::Epic), 'tires', Rarity::Epic))
            ->and($drawn->refresh()->affixes)->toBe(['grip' => 25_000])->and($common->refresh()->affixes)->toBeNull();

        $this->artisan('parts:roll-affixes')->expectsOutput('0 part(s) got affixes.')->assertSuccessful();
    });
});

describe('what the pace of the rarities counts', function () {
    it('is the affixes that a part of a rarity has on average: the chance of each one times its mean amount', function () {
        $draw = affixDraw();

        // An epic part of the tires has two of grip, handling, braking and durability (weights 3, 3, 3 and 2), so grip is there 6 times in 11.
        expect($draw->expected('tires', Rarity::Epic))->toBe(['grip' => 19_091, 'handling' => 19_091, 'braking' => 19_091, 'wear' => 50_909])
            ->and($draw->expected('tires', Rarity::Common))->toBe([])
            // One affix fits a tank, and a legendary part has it for sure.
            ->and($draw->expected('tank', Rarity::Legendary))->toBe(['wear' => 140_000]);
    });

    it('makes the gain of the crates more than it is without the affixes', function () {
        $with = app(RarityPace::class)->compute(30);
        $without = new RarityPace(Rules::fromSource(contentWith(['rules/starter' => function (array $rules): array {
            unset($rules['affixes']);

            return $rules;
        }])), app(RunInputsFactory::class));

        expect($with['gain_by_crates'][10])->toBeGreaterThan($without->compute(30)['gain_by_crates'][10]);
    });
});

describe('the content', function () {
    it('has the eight affixes of the first version, each with a name in five languages, inside its range and for slots that have the stat it changes', function () {
        $definitions = app(Rules::class)->affixes()['definitions'];

        expect(array_keys($definitions))->toBe(['grip', 'handling', 'braking', 'clearance', 'power', 'wear', 'fuel', 'reliability']);

        foreach ($definitions as $key => $affix) {
            expect(array_keys($affix['name']))->toBe(['en', 'pl', 'fr', 'de', 'pt-BR'], $key)->and($affix['max_ppm'])->toBeGreaterThanOrEqual($affix['min_ppm']);
        }
    });

    it('is refused when an affix gives at most less than at least, or a rarity gives at most fewer than at least, or a stat affix has no stat', function () {
        $backwards = PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => function (array $rules): array {
            $rules['affixes']['definitions']['grip']['max_ppm'] = 10_000;
            $rules['affixes']['per_rarity']['rare'] = ['min' => 2, 'max' => 1];

            return $rules;
        }]));
        $statless = PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => function (array $rules): array {
            unset($rules['affixes']['definitions']['grip']['stat']);

            return $rules;
        }]));
        $strayStat = PackValidator::fromConfig()->validateSource(contentWith(['rules/starter' => function (array $rules): array {
            $rules['affixes']['definitions']['wear']['stat'] = 'grip';

            return $rules;
        }]));

        expect(array_column($backwards->errors(), 'pointer'))->toContain('/affixes/definitions/grip/max_ppm', '/affixes/per_rarity/rare')
            ->and($statless->isValid())->toBeFalse()->and($strayStat->isValid())->toBeFalse();
    });

    it('is a pack that has none, and the parts then have none that count', function () {
        $without = contentWith(['rules/starter' => function (array $rules): array {
            unset($rules['affixes']);

            return $rules;
        }]);

        expect(PackValidator::fromConfig()->validateSource($without)->isValid())->toBeTrue()
            ->and(Rules::fromSource($without)->affixes())->toBe(['per_rarity' => [], 'definitions' => []])
            ->and(Rules::fromSource($without)->scaledStats(['grip' => 100], Rarity::Common, 0, 1_000_000, ['grip' => 50_000]))->toBe(['grip' => 100]);
    });
});
