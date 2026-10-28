<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Run\Rarity;
use App\Models\Car;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Testing\TestResponse;

/** A driver with a car (level 3), the experience of a player level and some money. */
function crateDriver(int $level = 1, int $cashCents = 0): User
{
    $driver = Car::factory()->create()->user;

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    if ($cashCents > 0) {
        (new Ledger)->post($driver->id, $cashCents, 'gift', 'money:'.$driver->id);
    }

    return $driver;
}

function shopOf(User $driver): TestResponse
{
    return test()->getJson('/api/v1/shop', [...bearerFor($driver), 'X-Locale' => 'en']);
}

function openCrate(User $driver, string $kind = 'slot', ?string $slot = 'tires', string $requestId = 'c1', array $headers = []): TestResponse
{
    return test()->postJson("/api/v1/shop/crates/{$kind}/open", array_filter(['request_id' => $requestId, 'slot' => $slot]), [...bearerFor($driver), 'X-Locale' => 'en', ...$headers]);
}

/** Writes down that the driver opened crates of a group with these rarities, as if they had. */
function openedCrates(User $driver, array $rarities, string $group = 'standard'): void
{
    foreach ($rarities as $index => $rarity) {
        CrateOpening::query()->create([
            'user_id' => $driver->id, 'kind' => 'slot', 'pity_group' => $group, 'opening_index' => $index, 'slot' => 'tires', 'tier' => 1,
            'rarity' => $rarity, 'part_slug' => 'all-season-tires-t1', 'price_cents' => 45_000,
        ]);
    }
}

/** @return array<string, array<string, mixed>> the kinds of crate of the shop, by key */
function crateKinds(User $driver): array
{
    return collect(shopOf($driver)->assertOk()->json('data.crates.kinds'))->keyBy('key')->all();
}

describe('the crates of the shop', function () {
    it('requires a token', function () {
        $this->postJson('/api/v1/shop/crates/slot/open', ['request_id' => 'c1', 'slot' => 'tires'])->assertUnauthorized();
    });

    it('shows the odds and the guarantees of both groups before anything is bought', function () {
        $driver = crateDriver();

        $groups = shopOf($driver)->assertOk()->json('data.crates.groups');

        expect($groups['standard'])->toEqual([
            'odds' => ['common' => 62, 'uncommon' => 25, 'rare' => 10, 'epic' => 2.7, 'legendary' => 0.3],
            'every' => 10, 'min_rarity' => 'rare', 'until' => 10, 'legendary_every' => 150, 'legendary_until' => 150,
        ])->and($groups['premium'])->toEqual([
            'odds' => ['common' => 30, 'uncommon' => 35, 'rare' => 24, 'epic' => 9, 'legendary' => 2],
            'every' => 40, 'min_rarity' => 'epic', 'until' => 40, 'legendary_every' => 150, 'legendary_until' => 150,
        ]);
    });

    it('opens the crate of a slot to a driver of level 1 for the tires and the suspension of tier 1, and the other kinds not yet', function () {
        $kinds = crateKinds(crateDriver());

        expect($kinds['slot'])->toMatchArray(['open' => true, 'choose_slot' => true, 'group' => 'standard', 'min_player_level' => 1, 'price_cents' => null])
            ->and(array_column($kinds['slot']['slots'], 'price_cents', 'slot'))->toBe(['tires' => 45_000, 'suspension' => 75_000])
            ->and(array_column($kinds['slot']['slots'], 'tier', 'slot'))->toBe(['tires' => 1, 'suspension' => 1])
            ->and($kinds['mixed'])->toMatchArray(['open' => false, 'min_player_level' => 3, 'slots' => []])
            ->and($kinds['premium'])->toMatchArray(['open' => false, 'min_player_level' => 6, 'slots' => []]);
    });

    it('opens the tier 2 at level 3, with a price of 100% of the mean of the models for a slot and 150% for a mixed crate', function () {
        $kinds = crateKinds(crateDriver(level: 3));

        // The tires of tier 2 are $570 and $620, the rally suspension $950, the disc brakes $760 and the sport exhaust $475.
        expect(array_column($kinds['slot']['slots'], 'price_cents', 'slot'))->toBe(['tires' => 59_500, 'suspension' => 95_000, 'brakes' => 76_000, 'exhaust' => 47_500])
            ->and(array_column($kinds['slot']['slots'], 'tier', 'slot'))->toBe(['tires' => 2, 'suspension' => 2, 'brakes' => 2, 'exhaust' => 2])
            ->and(array_column($kinds['slot']['slots'][0]['models'], 'slug'))->toBe(['street-tires-t2', 'offroad-tires-t2'])
            // 150% of the mean of 595, 950, 760 and 475 is $1,042.50, in whole dollars.
            ->and($kinds['mixed'])->toMatchArray(['open' => true, 'choose_slot' => false, 'price_cents' => 104_300])
            ->and($kinds['premium']['open'])->toBeFalse();
    });

    it('opens the tier 3 of the tires at level 6, and the premium crate costs twice the mean', function () {
        $kinds = crateKinds(crateDriver(level: 6));

        expect(array_column($kinds['slot']['slots'], 'tier', 'slot'))->toBe(['tires' => 3, 'suspension' => 2, 'brakes' => 2, 'exhaust' => 2])
            ->and(array_column($kinds['slot']['slots'][0]['models'], 'slug'))->toBe(['rain-tires-t3', 'winter-tires-t3'])
            ->and($kinds['premium'])->toMatchArray(['open' => true, 'group' => 'premium'])
            ->and(array_column($kinds['premium']['slots'], 'price_cents', 'slot'))->toBe(['tires' => 150_000, 'suspension' => 190_000, 'brakes' => 152_000, 'exhaust' => 95_000]);
    });

    it('names the models in the language of the driver', function () {
        $driver = crateDriver(level: 3);

        $names = collect(test()->getJson('/api/v1/shop', [...bearerFor($driver), 'X-Locale' => 'pl'])->json('data.crates.kinds.0.slots.0.models'))->pluck('name')->all();

        expect($names)->toBe(['Opony uliczne', 'Opony terenowe']);
    });
});

describe('opening a crate', function () {
    it('takes the price and puts a new part of a rarity in the inventory', function () {
        $driver = crateDriver(cashCents: 100_000);

        $response = openCrate($driver)->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('wallet.cash_cents', 55_000)
            ->assertJsonPath('opened.slug', 'all-season-tires-t1')
            ->assertJsonPath('opened.slot', 'tires')
            ->assertJsonPath('opened.tier', 1)
            ->assertJsonPath('opened.cost_cents', 45_000)
            ->assertJsonPath('opened.required_car_level', 0)
            ->assertJsonPath('opened.mountable', true);

        $opened = $response->json('opened');
        $part = Part::query()->findOrFail($opened['id']);

        expect($opened['rarity'])->toBeIn(['common', 'uncommon', 'rare', 'epic', 'legendary'])
            ->and($opened['rarity_rank'])->toBeBetween(1, 5)
            ->and($part->user_id)->toBe($driver->id)
            ->and($part->car_id)->toBeNull()
            ->and($part->rarity)->toBe($opened['rarity_rank'])
            ->and($opened['mounted'])->toBeFalse();
    });

    it('gives the part its statistics at the rarity that was drawn', function () {
        $driver = crateDriver(cashCents: 100_000);
        openedCrates($driver, array_fill(0, 149, 1));

        // The 150th crate without a legendary part is a legendary one, so the grip of all-season tires (85) is multiplied by 1.6, and by the affixes it was drawn with.
        $opened = openCrate($driver)->assertCreated()->json('opened');
        $affixes = Part::query()->findOrFail($opened['id'])->affixes;

        expect($opened['rarity'])->toBe('legendary')
            ->and($affixes)->toHaveCount(3)
            ->and($opened['stats'])->toEqual(app(Rules::class)->scaledStats(['grip' => 85, 'handling' => 83, 'braking' => 85], Rarity::Legendary, 0, 1_000_000, $affixes));
    });

    it('writes down the draw: the kind, the group, the place, the part and the price', function () {
        $driver = crateDriver(cashCents: 200_000);

        $opened = openCrate($driver)->assertCreated()->json('opened');

        $rows = CrateOpening::query()->where('user_id', $driver->id)->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->only(['kind', 'pity_group', 'opening_index', 'slot', 'tier', 'part_slug', 'price_cents', 'part_id']))->toBe([
                'kind' => 'slot', 'pity_group' => 'standard', 'opening_index' => 0, 'slot' => 'tires', 'tier' => 1, 'part_slug' => 'all-season-tires-t1', 'price_cents' => 45_000, 'part_id' => $opened['id'],
            ])
            ->and($rows[0]->rarity)->toBe($opened['rarity_rank']);

        openCrate($driver, 'slot', 'suspension', 'c2')->assertCreated();

        expect(CrateOpening::query()->where('user_id', $driver->id)->orderBy('opening_index')->pluck('opening_index')->all())->toBe([0, 1]);
    });

    it('opens nothing twice for a request that comes again, and says so', function () {
        $driver = crateDriver(cashCents: 100_000);

        openCrate($driver, requestId: 'same')->assertCreated();
        openCrate($driver, requestId: 'same')->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('opened', null)->assertJsonPath('wallet.cash_cents', 55_000);

        expect(CrateOpening::query()->where('user_id', $driver->id)->count())->toBe(1)
            ->and(Part::query()->where('user_id', $driver->id)->count())->toBe(1)
            ->and(LedgerEntry::query()->where('idem_key', "crate:{$driver->id}:same")->count())->toBe(1);
    });

    it('lets the driver choose the slot of a slot crate, and refuses one that is not open or none', function () {
        $driver = crateDriver(cashCents: 500_000);

        openCrate($driver, 'slot', 'suspension', 'a')->assertCreated()->assertJsonPath('opened.slug', 'sport-suspension-t1');
        // The brakes and the exhaust are of tier 2, which a driver of level 1 does not open yet.
        openCrate($driver, 'slot', 'brakes', 'b')->assertStatus(422)->assertJsonPath('code', 'crate_slot_unavailable');
        openCrate($driver, 'slot', null, 'c')->assertStatus(422)->assertJsonPath('code', 'crate_slot_unavailable');
        openCrate($driver, 'slot', 'engine', 'd')->assertStatus(422)->assertJsonPath('code', 'crate_slot_unavailable');

        expect(CrateOpening::query()->count())->toBe(1);
    });

    it('draws the slot of a mixed crate, ignores the one that was sent, and charges the one price', function () {
        $driver = crateDriver(level: 3, cashCents: 500_000);

        $opened = openCrate($driver, 'mixed', 'tires')->assertCreated()->assertJsonPath('wallet.cash_cents', 500_000 - 104_300)->json('opened');
        $row = CrateOpening::query()->where('user_id', $driver->id)->firstOrFail();

        expect($opened['slot'])->toBeIn(['tires', 'suspension', 'brakes', 'exhaust'])
            ->and($row->slot)->toBe($opened['slot'])
            ->and($row->kind)->toBe('mixed')
            ->and($opened['tier'])->toBe(2);
    });

    it('draws the models of the tires of tier 3 for a premium crate, from the group of its own', function () {
        $driver = crateDriver(level: 6, cashCents: 500_000);

        $opened = openCrate($driver, 'premium', 'tires')->assertCreated()->assertJsonPath('wallet.cash_cents', 350_000)->json('opened');

        expect($opened['slug'])->toBeIn(['rain-tires-t3', 'winter-tires-t3'])
            ->and(CrateOpening::query()->where('user_id', $driver->id)->value('pity_group'))->toBe('premium');
    });

    it('asks for the level of the kind, and says what is missing', function () {
        $driver = crateDriver(level: 3, cashCents: 500_000);

        openCrate($driver, 'premium', 'tires')
            ->assertStatus(422)
            ->assertJsonPath('code', 'crate_level_required')
            ->assertJsonPath('details', ['required' => 6, 'current' => 3])
            ->assertJsonPath('message', 'Requires player level 6 (you are level 3). You get XP by driving.');

        // A mixed crate asks for level 3, which this driver has, and not for a driver of level 1.
        openCrate($driver, 'mixed', null, 'x')->assertCreated();
        openCrate(crateDriver(level: 1, cashCents: 500_000), 'mixed', null, 'y')->assertStatus(422)->assertJsonPath('code', 'crate_level_required');
    });

    it('does not open a kind of crate that does not exist', function () {
        openCrate(crateDriver(cashCents: 100_000), 'huge')->assertStatus(422)->assertJsonPath('code', 'crate_not_found');
    });

    it('refuses what the driver cannot pay, and takes and writes down nothing then', function () {
        $driver = crateDriver(cashCents: 10_000);

        openCrate($driver)->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        expect(CrateOpening::query()->count())->toBe(0)
            ->and(Part::query()->where('user_id', $driver->id)->count())->toBe(0)
            ->and((new Ledger)->balance($driver->id))->toBe(10_000);
    });

    it('validates the request', function () {
        $driver = crateDriver(cashCents: 100_000);

        test()->postJson('/api/v1/shop/crates/slot/open', ['slot' => 'tires'], bearerFor($driver))->assertStatus(422);
    });

    it('says in the language of the driver what it refuses', function () {
        openCrate(crateDriver(cashCents: 100_000), 'huge', null, 'z', ['X-Locale' => 'pl'])->assertStatus(422)->assertJsonPath('message', 'Nie ma takiej skrzynki.');
    });
});

describe('the guarantees of the crates', function () {
    it('counts down to the guarantee with every crate that gave nothing rare', function () {
        $driver = crateDriver();
        openedCrates($driver, array_fill(0, 3, 1));

        $groups = shopOf($driver)->json('data.crates.groups');

        expect($groups['standard'])->toMatchArray(['until' => 7, 'legendary_until' => 147])
            // The other group has its own count.
            ->and($groups['premium'])->toMatchArray(['until' => 40, 'legendary_until' => 150]);
    });

    it('gives a rare part or a better one at the 10th crate, and begins the count again', function () {
        $driver = crateDriver(cashCents: 100_000);
        openedCrates($driver, array_fill(0, 9, 1));

        expect(shopOf($driver)->json('data.crates.groups.standard.until'))->toBe(1);

        $rank = openCrate($driver)->assertCreated()->json('opened.rarity_rank');

        expect($rank)->toBeGreaterThanOrEqual(3)
            ->and(shopOf($driver)->json('data.crates.groups.standard.until'))->toBe(10);
    });

    it('does not count the openings before a rare part', function () {
        $driver = crateDriver();
        openedCrates($driver, [1, 1, 1, 1, 3, 1, 2]);

        expect(shopOf($driver)->json('data.crates.groups.standard.until'))->toBe(8);
    });

    it('gives a legendary part at the 150th crate without one', function () {
        $driver = crateDriver(cashCents: 100_000);
        openedCrates($driver, [...array_fill(0, 74, 3), ...array_fill(0, 75, 4)]);

        expect(shopOf($driver)->json('data.crates.groups.standard.legendary_until'))->toBe(1);

        openCrate($driver)->assertCreated()->assertJsonPath('opened.rarity', 'legendary');

        expect(shopOf($driver)->json('data.crates.groups.standard.legendary_until'))->toBe(150);
    });

    it('keeps the count of the premium crates apart from the standard ones', function () {
        $driver = crateDriver(level: 6, cashCents: 500_000);
        openedCrates($driver, array_fill(0, 39, 1), 'premium');

        $groups = shopOf($driver)->json('data.crates.groups');

        expect($groups['premium']['until'])->toBe(1)->and($groups['standard']['until'])->toBe(10);

        $rank = openCrate($driver, 'premium', 'tires')->assertCreated()->json('opened.rarity_rank');

        // The 40th premium crate is an epic part or a legendary one.
        expect($rank)->toBeGreaterThanOrEqual(4);
    });
});

describe('the rarity of a part on a car', function () {
    it('shows the rarity of the parts in the inventory', function () {
        $driver = crateDriver(cashCents: 100_000);
        $opened = openCrate($driver)->assertCreated()->json('opened');

        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.inventory.0.id', $opened['id'])
            ->assertJsonPath('data.inventory.0.rarity', $opened['rarity'])
            ->assertJsonPath('data.inventory.0.rarity_rank', $opened['rarity_rank']);
    });

    it('makes the car better the rarer the part on it is', function () {
        $grip = function (int $rarity): float {
            $car = Car::factory()->create();
            Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires', 'rarity' => $rarity])->mountedOn($car)->create();

            return (float) test()->getJson('/api/v1/garage', bearerFor($car->user))->assertOk()->json('data.car.stats.grip');
        };

        // The grip of the street tires of tier 2 is 100.
        expect([$grip(1), $grip(2), $grip(3), $grip(4), $grip(5)])->toBe([100.0, 110.0, 122.0, 138.0, 160.0]);
    });
});
