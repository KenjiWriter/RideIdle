<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Testing\TestResponse;

/** @param  array<string, mixed>  $body */
function buyPart(User $driver, array $body = ['part' => 'street-tires-t2', 'request_id' => 'b1'], array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/shop/purchases', $body, [...bearerFor($driver), ...$headers]);
}

/** A driver with a car and some money. */
function driverWithMoney(int $cents): User
{
    $car = Car::factory()->create();

    if ($cents > 0) {
        (new Ledger)->post($car->user_id, $cents, 'gift', 'seed-money:'.$car->user_id);
    }

    return $car->user;
}

describe('the shop', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/shop')->assertUnauthorized();
        $this->postJson('/api/v1/shop/purchases', ['part' => 'street-tires-t2', 'request_id' => 'b1'])->assertUnauthorized();
    });

    it('lists the parts of the rules with their price, tier, statistics and the name in the language of the driver', function () {
        $driver = driverWithMoney(10_000);

        $response = $this->getJson('/api/v1/shop', [...bearerFor($driver), 'X-Locale' => 'pl'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('wallet.cash_cents', 10_000)
            ->assertJsonPath('data.car.level', 3);

        $parts = collect($response->json('data.parts'))->keyBy('slug');

        // A car of level 3 sees up to tier 3: the tires, the suspension, the brakes and the exhaust, and what of the engine group and of the engines is of those tiers.
        expect($parts->keys()->all())->toBe([
            'street-tires-t2', 'all-season-tires-t1', 'offroad-tires-t2', 'rain-tires-t3', 'winter-tires-t3', 'sport-suspension-t1', 'rally-suspension-t2', 'disc-brakes-t2', 'sport-exhaust-t2',
            'turbo-osemka-t2', 'turbo-mini-twin-scroll-t3', 'roots-mini-t2', 'factory-cooling-t1', 'intercooler-factory-t2', 'radiator-aluminium-t3', 'manual-4-gearbox-t1', 'manual-5-gearbox-t2', 'manual-6-gearbox-t3',
            'engine-r4-1-0-t1', 'engine-r4-1-3-t1', 'engine-r4-1-6-t2', 'engine-r4-1-8-t2', 'engine-r4-2-0-t3', 'engine-v6-2-5-t3',
        ])
            ->and($parts['sport-exhaust-t2'])->toMatchArray([
                'name' => 'Wydech sportowy',
                'slot' => 'exhaust',
                'tier' => 2,
                'price_cents' => 47_500,
                'stats' => ['power_mult' => 1.02],
                'required_car_level' => 3,
                'mountable' => true,
            ]);
    });

    it('tells how the tires grip on every surface, and nothing of the sort for the other parts', function () {
        $driver = driverWithMoney(0);

        $parts = collect($this->getJson('/api/v1/shop', bearerFor($driver))->assertOk()->json('data.parts'))->keyBy('slug');

        expect($parts['rain-tires-t3']['surfaces'])->toBe(['asphalt_dry' => 0.9, 'asphalt_wet' => 1.05, 'gravel_dry' => 0.55, 'mud' => 0.5, 'snow' => 0.55])
            ->and($parts['offroad-tires-t2']['surfaces']['gravel_dry'])->toEqual(1.0)
            ->and($parts['sport-exhaust-t2']['surfaces'])->toBeNull();
    });

    it('says that a part cannot be mounted by a car of too low a level', function () {
        $car = Car::factory()->create(['level' => 2]);

        $parts = collect($this->getJson('/api/v1/shop', bearerFor($car->user))->assertOk()->json('data.parts'))->keyBy('slug');

        expect($parts['sport-suspension-t1']['mountable'])->toBeTrue()
            ->and($parts['street-tires-t2'])->toMatchArray(['required_car_level' => 3, 'mountable' => false]);
    });

    it('lists the parts for a driver who has no car, and mounts nothing', function () {
        $driver = User::factory()->guest()->create();

        $this->getJson('/api/v1/shop', bearerFor($driver))
            ->assertOk()
            ->assertJsonPath('data.car', null)
            ->assertJsonPath('data.parts.0.mountable', false);
    });
});

describe('buying a part', function () {
    it('charges the price and puts the part in the inventory, in one go', function () {
        $driver = driverWithMoney(100_000);

        buyPart($driver)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'street-tires-t2')
            ->assertJsonPath('data.slot', 'tires')
            ->assertJsonPath('data.mounted', false)
            ->assertJsonPath('data.car_id', null)
            ->assertJsonPath('purchase', ['cost_cents' => 57_000, 'repeated' => false])
            ->assertJsonPath('wallet.cash_cents', 43_000);

        $part = Part::query()->where('user_id', $driver->id)->sole();
        $entry = LedgerEntry::query()->where('reason', 'purchase')->sole();

        expect($part->part_slug)->toBe('street-tires-t2')
            ->and($part->slot)->toBe('tires')
            ->and($part->car_id)->toBeNull()
            ->and($entry->delta)->toBe(-57_000)
            ->and((new Ledger)->isConsistent($driver->id))->toBeTrue();
    });

    it('lets a driver own several of the same part', function () {
        $driver = driverWithMoney(200_000);

        buyPart($driver, ['part' => 'sport-exhaust-t2', 'request_id' => 'a'])->assertCreated();
        buyPart($driver, ['part' => 'sport-exhaust-t2', 'request_id' => 'b'])->assertCreated();

        expect(Part::query()->where('user_id', $driver->id)->count())->toBe(2);
    });

    it('charges and gives once when the same request is sent again', function () {
        $driver = driverWithMoney(100_000);

        buyPart($driver)->assertCreated();
        buyPart($driver)
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('purchase', ['cost_cents' => 0, 'repeated' => true])
            ->assertJsonPath('wallet.cash_cents', 43_000);

        expect(Part::query()->count())->toBe(1)->and(LedgerEntry::query()->where('reason', 'purchase')->count())->toBe(1);
    });

    it('refuses a request id that was used for another part', function () {
        $driver = driverWithMoney(100_000);

        buyPart($driver, ['part' => 'street-tires-t2', 'request_id' => 'same'])->assertCreated();
        buyPart($driver, ['part' => 'sport-exhaust-t2', 'request_id' => 'same'])->assertStatus(409)->assertJsonPath('code', 'request_reused');

        expect(Part::query()->count())->toBe(1);
    });

    it('does not mix the request ids of two drivers', function () {
        $first = driverWithMoney(100_000);
        $second = driverWithMoney(100_000);

        buyPart($first)->assertCreated();
        buyPart($second)->assertCreated()->assertJsonPath('purchase.repeated', false);

        expect(Part::query()->count())->toBe(2);
    });

    it('changes nothing when the driver cannot pay', function () {
        $driver = driverWithMoney(10_000);

        buyPart($driver)
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_funds')
            ->assertJsonPath('available_cents', 10_000)
            ->assertJsonPath('required_cents', 57_000);

        expect(Part::query()->count())->toBe(0)->and((new Ledger)->balance($driver->id))->toBe(10_000);
    });

    it('refuses a part that the shop does not sell, even when the pack has it', function (string $slug) {
        $driver = driverWithMoney(1_000_000);

        buyPart($driver, ['part' => $slug, 'request_id' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'part_not_for_sale')
            ->assertJsonPath('message', 'This part is not for sale.');

        expect(Part::query()->count())->toBe(0)->and((new Ledger)->balance($driver->id))->toBe(1_000_000);
    })->with(['in the pack but not in the shop' => 'slick-tires-t7', 'not in the pack' => 'golden-wheels']);

    it('answers in the language of the driver', function () {
        $driver = driverWithMoney(0);

        buyPart($driver, ['part' => 'golden-wheels', 'request_id' => 'x'], ['X-Locale' => 'pl'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tej części nie ma w sprzedaży.');
    });

    it('validates the body', function () {
        $driver = driverWithMoney(100_000);

        buyPart($driver, ['part' => 'street-tires-t2'])->assertStatus(422)->assertJsonValidationErrors('request_id');
        buyPart($driver, ['request_id' => 'x'])->assertStatus(422)->assertJsonValidationErrors('part');
        buyPart($driver, ['part' => 'street-tires-t2', 'request_id' => str_repeat('x', 65)])->assertStatus(422)->assertJsonValidationErrors('request_id');
    });
});
