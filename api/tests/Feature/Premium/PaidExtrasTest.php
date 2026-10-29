<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Exceptions\IdempotencyConflictException;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\Purchases;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

// The three paid extras: the charges of the auto-refill and the VIP account (bought with coins), and the pass of the endless refill (bought with money, given by the
// operator until a payment provider is connected). Their effect on the laps is in tests/Feature/Run/PaidExtrasSettlementTest.php.

afterEach(fn () => Carbon::setTestNow());

/** The rules of the repository with a change made to the paid extras, for the tests of a game that sells less. @param  callable(array<string, mixed>): array<string, mixed>  $change */
function premiumRules(callable $change): void
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);

    app()->singleton(Rules::class, fn () => Rules::fromArray($change($data)));
}

function richDriver(int $coins = 1_000): User
{
    $driver = User::factory()->guest()->create();
    (new Ledger)->postPremium($driver->id, $coins, 'gift', "extras-coins:{$driver->id}");

    return $driver;
}

function buyRefills(User $driver, string $pack = 'refill-5', string $request = 'r-1'): TestResponse
{
    return test()->postJson('/api/v1/premium/autorefill', ['pack' => $pack, 'request_id' => $request], bearerFor($driver));
}

function buyVip(User $driver, string $request = 'v-1'): TestResponse
{
    return test()->postJson('/api/v1/premium/vip', ['request_id' => $request], bearerFor($driver));
}

describe('the shop of the extras', function () {
    it('shows what is sold and that the driver has nothing yet, and that the pass cannot be bought until a provider is connected', function () {
        $driver = User::factory()->guest()->create();

        $response = $this->getJson('/api/v1/premium', bearerFor($driver))->assertOk();

        expect($response->json('data.autorefill'))->toMatchArray(['enabled' => true, 'preference' => true, 'stock' => 0, 'stock_cap' => 100])
            ->and(array_column($response->json('data.autorefill.packs'), 'slug'))->toBe(['refill-1', 'refill-5', 'refill-20'])
            ->and($response->json('data.vip'))->toMatchArray(['enabled' => true, 'active' => false, 'until_ms' => null, 'remaining_ms' => 0, 'days' => 30, 'coins' => 150, 'cash_bonus_ppm' => 200_000, 'xp_bonus_ppm' => 200_000])
            ->and($response->json('data.infinity'))->toMatchArray(['enabled' => true, 'active' => false, 'until_ms' => null, 'days' => 30, 'price_minor' => 1_999, 'purchasable' => false])
            ->and($response->json('data.now_ms'))->toBeInt();
    });

    it('says the pass can be bought when a provider is connected', function () {
        config(['ride.premium.provider' => 'stripe']);

        $this->getJson('/api/v1/premium', bearerFor(User::factory()->guest()->create()))->assertJsonPath('data.infinity.purchasable', true);
    });

    it('has nothing of an extra that the rules do not mention, and offers nothing of one that is switched off', function () {
        premiumRules(function (array $data): array {
            unset($data['premium']['vip']);
            $data['premium']['infinity']['enabled'] = false;
            $data['premium']['autorefill']['enabled'] = false;

            return $data;
        });
        config(['ride.premium.provider' => 'stripe']);

        $response = $this->getJson('/api/v1/premium', bearerFor(User::factory()->guest()->create()))->assertOk();

        expect($response->json('data.vip'))->toBeNull()
            ->and($response->json('data.infinity'))->toMatchArray(['enabled' => false, 'active' => false, 'purchasable' => false])
            ->and($response->json('data.autorefill.enabled'))->toBeFalse();
    });
});

describe('buying charges of the auto-refill', function () {
    it('takes the coins of the pack and adds its charges, in the ledger, and says so in the answer', function () {
        $driver = richDriver(100);

        buyRefills($driver, 'refill-5')->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('spent_coins', 9)
            ->assertJsonPath('bought_charges', 5)
            ->assertJsonPath('data.balance', 91)
            ->assertJsonPath('data.autorefill.stock', 5);

        $ledger = new Ledger;

        expect($ledger->premiumBalance($driver->id))->toBe(91)
            ->and($ledger->refillBalance($driver->id))->toBe(5)
            ->and($ledger->isConsistent($driver->id, Ledger::PREMIUM))->toBeTrue()
            ->and($ledger->isConsistent($driver->id, Ledger::REFILL))->toBeTrue()
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('currency', 'refill')->sole()->delta)->toBe(5)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'autorefill')->sole()->delta)->toBe(-9);
    });

    it('charges once for a request that is sent again, and again for a new one', function () {
        $driver = richDriver(100);

        buyRefills($driver, 'refill-5', 'same')->assertCreated();
        buyRefills($driver, 'refill-5', 'same')->assertOk()
            ->assertJsonPath('repeated', true)
            ->assertJsonPath('spent_coins', 0)
            ->assertJsonPath('data.balance', 91)
            ->assertJsonPath('data.autorefill.stock', 5);
        buyRefills($driver, 'refill-1', 'other')->assertCreated()->assertJsonPath('data.autorefill.stock', 6)->assertJsonPath('data.balance', 89);
    });

    it('does not mix the requests of two drivers who happen to choose the same id', function () {
        $first = richDriver(100);
        $second = richDriver(100);

        buyRefills($first, 'refill-1', 'same')->assertCreated();
        buyRefills($second, 'refill-1', 'same')->assertCreated()->assertJsonPath('data.autorefill.stock', 1);
    });

    it('refuses a driver who cannot pay with 422 insufficient_premium and changes nothing', function () {
        $driver = richDriver(8);

        buyRefills($driver, 'refill-5')->assertUnprocessable()
            ->assertJsonPath('code', 'insufficient_premium')
            ->assertJsonPath('details.available', 8)
            ->assertJsonPath('details.required', 9);

        expect((new Ledger)->premiumBalance($driver->id))->toBe(8)
            ->and((new Ledger)->refillBalance($driver->id))->toBe(0)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->count())->toBe(1);
    });

    it('refuses a pack that would take the stock over its cap, whole, and still answers a repeat of the request that filled it', function () {
        $driver = richDriver(10_000);
        buyRefills($driver, 'refill-20', 'a')->assertCreated();
        buyRefills($driver, 'refill-20', 'b')->assertCreated();
        buyRefills($driver, 'refill-20', 'c')->assertCreated();
        buyRefills($driver, 'refill-20', 'd')->assertCreated();
        buyRefills($driver, 'refill-5', 'e')->assertCreated()->assertJsonPath('data.autorefill.stock', 85);
        buyRefills($driver, 'refill-5', 'f')->assertCreated();
        buyRefills($driver, 'refill-5', 'g')->assertCreated()->assertJsonPath('data.autorefill.stock', 95);

        $coins = (new Ledger)->premiumBalance($driver->id);

        buyRefills($driver, 'refill-20', 'h')->assertUnprocessable()
            ->assertJsonPath('code', 'autorefill_stock_full')
            ->assertJsonPath('details.stock', 95)
            ->assertJsonPath('details.cap', 100);
        buyRefills($driver, 'refill-5', 'i')->assertCreated()->assertJsonPath('data.autorefill.stock', 100);
        buyRefills($driver, 'refill-1', 'j')->assertUnprocessable()->assertJsonPath('code', 'autorefill_stock_full');
        buyRefills($driver, 'refill-5', 'i')->assertOk()->assertJsonPath('repeated', true);

        expect((new Ledger)->premiumBalance($driver->id))->toBe($coins - 9);
    });

    it('is refused for a pack that does not exist and without a request id', function () {
        $driver = richDriver();

        buyRefills($driver, 'refill-99')->assertUnprocessable()->assertJsonPath('code', 'autorefill_pack_not_found');
        $this->postJson('/api/v1/premium/autorefill', ['pack' => 'refill-1'], bearerFor($driver))->assertUnprocessable();
    });

    it('is not sold by a game that has switched it off, and what the driver has is kept', function () {
        $driver = richDriver();
        (new Ledger)->postRefills($driver->id, 7, 'gift', "stock:{$driver->id}");

        premiumRules(function (array $data): array {
            $data['premium']['autorefill']['enabled'] = false;

            return $data;
        });

        buyRefills($driver, 'refill-1')->assertUnprocessable()->assertJsonPath('code', 'autorefill_unavailable');
        $this->getJson('/api/v1/premium', bearerFor($driver))->assertJsonPath('data.autorefill.stock', 7)->assertJsonPath('data.autorefill.enabled', false);
        expect((new Ledger)->refillBalance($driver->id))->toBe(7)->and((new Ledger)->premiumBalance($driver->id))->toBe(1_000);
    });

    it('answers in the language of the driver', function () {
        $driver = richDriver(1);

        $this->postJson('/api/v1/premium/autorefill', ['pack' => 'refill-5', 'request_id' => 'x'], [...bearerFor($driver), 'X-Locale' => 'pl'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Za mało monet premium: masz 1, potrzebujesz 9.');
    });

    it('is for a signed-in driver only', function () {
        $this->postJson('/api/v1/premium/autorefill', ['pack' => 'refill-1', 'request_id' => 'x'])->assertUnauthorized();
        $this->postJson('/api/v1/premium/vip', ['request_id' => 'x'])->assertUnauthorized();
        $this->patchJson('/api/v1/premium/autorefill', ['enabled' => false])->assertUnauthorized();
    });
});

describe('the wish to have the tank filled', function () {
    it('is on until the driver switches it off, and can be switched on again', function () {
        $driver = User::factory()->guest()->create();

        $this->patchJson('/api/v1/premium/autorefill', ['enabled' => false], bearerFor($driver))->assertOk()->assertJsonPath('data.autorefill.preference', false);
        expect(Wallet::query()->find($driver->id)->auto_refill_enabled)->toBeFalse();

        $this->patchJson('/api/v1/premium/autorefill', ['enabled' => true], bearerFor($driver))->assertOk()->assertJsonPath('data.autorefill.preference', true);
        $this->patchJson('/api/v1/premium/autorefill', [], bearerFor($driver))->assertUnprocessable();
    });

    it('cannot be set in a game that does not sell the auto-refill', function () {
        premiumRules(function (array $data): array {
            unset($data['premium']['autorefill']);

            return $data;
        });

        $this->patchJson('/api/v1/premium/autorefill', ['enabled' => false], bearerFor(User::factory()->guest()->create()))
            ->assertUnprocessable()->assertJsonPath('code', 'autorefill_unavailable');
    });
});

describe('the VIP account', function () {
    it('costs the coins of the rules and lasts the days of the rules from the moment it is bought', function () {
        clockAt(0);
        $driver = richDriver(500);

        buyVip($driver)->assertCreated()
            ->assertJsonPath('spent_coins', 150)
            ->assertJsonPath('data.balance', 350)
            ->assertJsonPath('data.vip.active', true)
            ->assertJsonPath('data.vip.until_ms', RUN_T0 + 30 * 86_400_000)
            ->assertJsonPath('data.vip.remaining_ms', 30 * 86_400_000);

        clockAt(10 * 86_400_000);

        $this->getJson('/api/v1/premium', bearerFor($driver))->assertJsonPath('data.vip.remaining_ms', 20 * 86_400_000);
    });

    it('is extended from its end when it is bought again before the end, and from now when it has ended', function () {
        clockAt(0);
        $driver = richDriver(1_000);
        buyVip($driver, 'a')->assertCreated();

        clockAt(10 * 86_400_000);
        buyVip($driver, 'b')->assertCreated()->assertJsonPath('data.vip.until_ms', RUN_T0 + 60 * 86_400_000);

        clockAt(100 * 86_400_000);
        $this->getJson('/api/v1/premium', bearerFor($driver))->assertJsonPath('data.vip.active', false)->assertJsonPath('data.vip.remaining_ms', 0);
        buyVip($driver, 'c')->assertCreated()->assertJsonPath('data.vip.until_ms', RUN_T0 + 130 * 86_400_000)->assertJsonPath('data.vip.active', true);
    });

    it('is charged once for a request that is sent again', function () {
        clockAt(0);
        $driver = richDriver(500);

        buyVip($driver, 'same')->assertCreated();
        buyVip($driver, 'same')->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('spent_coins', 0)->assertJsonPath('data.balance', 350)
            ->assertJsonPath('data.vip.until_ms', RUN_T0 + 30 * 86_400_000);

        expect(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'vip')->count())->toBe(1);
    });

    it('is refused without the coins, with 422 insufficient_premium, and leaves the account as it was', function () {
        $driver = richDriver(149);

        buyVip($driver)->assertUnprocessable()->assertJsonPath('code', 'insufficient_premium')->assertJsonPath('details.required', 150);

        expect(Wallet::query()->find($driver->id)->vip_until)->toBeNull()
            ->and((new Ledger)->premiumBalance($driver->id))->toBe(149);
    });

    it('is not sold by a game that has switched it off', function () {
        $driver = richDriver();

        premiumRules(function (array $data): array {
            $data['premium']['vip']['enabled'] = false;

            return $data;
        });

        buyVip($driver)->assertUnprocessable()->assertJsonPath('code', 'vip_unavailable');
        expect((new Ledger)->premiumBalance($driver->id))->toBe(1_000);
    });

    it('is not sold by a game that does not have it', function () {
        $driver = richDriver();

        premiumRules(function (array $data): array {
            unset($data['premium']['vip']);

            return $data;
        });

        buyVip($driver)->assertUnprocessable()->assertJsonPath('code', 'vip_unavailable');
        expect((new Ledger)->premiumBalance($driver->id))->toBe(1_000);
    });

    it('keeps what the driver has when the game switches it off, and says it is not active', function () {
        clockAt(0);
        $driver = richDriver();
        buyVip($driver)->assertCreated();

        premiumRules(function (array $data): array {
            $data['premium']['vip']['enabled'] = false;

            return $data;
        });

        $this->getJson('/api/v1/premium', bearerFor($driver))
            ->assertJsonPath('data.vip.active', false)
            ->assertJsonPath('data.vip.until_ms', RUN_T0 + 30 * 86_400_000);
    });
});

describe('the pass of the endless refill', function () {
    it('is given by the operator for the days of the rules, recorded as a purchase of nothing, and extended from its end', function () {
        clockAt(0);
        $driver = User::factory()->guest()->create();

        $this->artisan('premium:pass', ['--id' => $driver->id])->assertSuccessful();

        $purchase = Purchase::query()->where('user_id', $driver->id)->sole();

        expect($purchase->product)->toBe('infinity')
            ->and($purchase->coins)->toBe(0)
            ->and($purchase->amount_minor)->toBe(0)
            ->and($purchase->provider)->toBe('operator')
            ->and(Wallet::query()->find($driver->id)->infinity_until->getTimestampMs())->toBe(RUN_T0 + 30 * 86_400_000);

        $this->artisan('premium:pass', ['days' => 7, '--id' => $driver->id])->assertSuccessful();

        $this->getJson('/api/v1/premium', bearerFor($driver))
            ->assertJsonPath('data.infinity.active', true)
            ->assertJsonPath('data.infinity.until_ms', RUN_T0 + 37 * 86_400_000);
    });

    it('is given once when the command is run again with the same key', function () {
        clockAt(0);
        $driver = User::factory()->guest()->create();

        $this->artisan('premium:pass', ['--id' => $driver->id, '--key' => 'promo-1'])->assertSuccessful();
        $this->artisan('premium:pass', ['--id' => $driver->id, '--key' => 'promo-1'])->assertSuccessful();

        expect(Purchase::query()->where('user_id', $driver->id)->count())->toBe(1)
            ->and(Wallet::query()->find($driver->id)->infinity_until->getTimestampMs())->toBe(RUN_T0 + 30 * 86_400_000);
    });

    it('is a conflict when a payment is told for another driver', function () {
        $first = User::factory()->guest()->create();
        $second = User::factory()->guest()->create();
        app(Purchases::class)->fulfilPass($first->id, 'stripe', 'pay_1');

        expect(fn () => app(Purchases::class)->fulfilPass($second->id, 'stripe', 'pay_1'))->toThrow(IdempotencyConflictException::class);
    });

    it('records what the provider charged, and ends when the payment is taken back', function () {
        clockAt(0);
        $driver = User::factory()->guest()->create();
        $purchase = app(Purchases::class)->fulfilPass($driver->id, 'stripe', 'pay_1', null, 1_500, 'EUR');

        expect($purchase->amount_minor)->toBe(1_500)->and($purchase->currency)->toBe('EUR');
        $this->getJson('/api/v1/premium', bearerFor($driver))->assertJsonPath('data.infinity.active', true);

        app(Purchases::class)->refund($purchase);

        $this->getJson('/api/v1/premium', bearerFor($driver))->assertJsonPath('data.infinity.active', false);
        expect($purchase->refresh()->status)->toBe('refunded')
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->count())->toBe(0);
    });

    it('is refused by the command when the game does not sell it, and for bad days or no account', function () {
        $driver = User::factory()->guest()->create();

        $this->artisan('premium:pass', ['days' => 0, '--id' => $driver->id])->assertFailed();
        $this->artisan('premium:pass', ['--id' => 999_999])->assertFailed();
        $this->artisan('premium:pass', [])->assertFailed();

        premiumRules(function (array $data): array {
            $data['premium']['infinity']['enabled'] = false;

            return $data;
        });

        $this->artisan('premium:pass', ['--id' => $driver->id])->assertFailed();
        expect(Purchase::query()->count())->toBe(0);
    });

    it('is in the export of the data of the account', function () {
        clockAt(0);
        $driver = richDriver();
        buyRefills($driver, 'refill-5')->assertCreated();
        buyVip($driver)->assertCreated();
        Artisan::call('premium:pass', ['--id' => $driver->id]);

        $wallet = exported(['--id' => $driver->id])['game']['wallet'];

        expect($wallet)->toMatchArray(['auto_refills' => 5, 'auto_refill_enabled' => true])
            ->and($wallet['vip_until'])->toBeString()
            ->and($wallet['infinity_until'])->toBeString();
    });
});
