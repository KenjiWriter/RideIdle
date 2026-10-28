<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger;
use App\Services\Purchases;
use Illuminate\Support\Facades\Artisan;

// The shop of the premium currency, as the client sees it: the balance, the packs with their price and what a bigger pack gives more, and the last purchases.

describe('the premium shop', function () {
    it('lists the currency and the packs with the price and the bonus of a bigger one, for a driver who has no coins', function () {
        $user = User::factory()->guest()->create();

        $response = $this->getJson('/api/v1/premium', [...bearerFor($user), 'X-Locale' => 'en'])->assertOk();

        $response->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.currency', ['code' => 'ridecoin', 'name' => 'RideCoin'])
            ->assertJsonPath('data.price_currency', 'PLN')
            ->assertJsonPath('data.purchases', []);

        $products = $response->json('data.products');

        expect(array_column($products, 'slug'))->toBe(['coins-small', 'coins-medium', 'coins-large', 'coins-huge'])
            ->and(array_column($products, 'coins'))->toBe([50, 220, 600, 1300])
            ->and(array_column($products, 'price_minor'))->toBe([499, 1999, 4999, 9999])
            ->and(array_column($products, 'bonus_percent'))->toBe([0, 10, 20, 30])
            ->and($products[0]['name'])->toBe('Small pack');
    });

    it('names the packs in the language of the driver', function () {
        $user = User::factory()->guest()->create();

        $this->getJson('/api/v1/premium', [...bearerFor($user), 'X-Locale' => 'pl'])
            ->assertOk()
            ->assertJsonPath('data.products.0.name', 'Mała paczka')
            ->assertJsonPath('data.products.3.name', 'Ogromna paczka');
    });

    it('shows the balance and the last purchases, newest first, and only the driver\'s own', function () {
        $user = User::factory()->guest()->create();
        $other = User::factory()->guest()->create();
        app(Purchases::class)->fulfil($user->id, 'coins-small', 'test', 'pay_1');
        app(Purchases::class)->fulfil($user->id, 'coins-large', 'test', 'pay_2');
        app(Purchases::class)->fulfil($other->id, 'coins-huge', 'test', 'pay_3');

        $response = $this->getJson('/api/v1/premium', bearerFor($user))->assertOk();

        expect($response->json('data.balance'))->toBe(650)
            ->and(array_column($response->json('data.purchases'), 'product'))->toBe(['coins-large', 'coins-small'])
            ->and($response->json('data.purchases.0'))->toMatchArray(['coins' => 600, 'amount_minor' => 4999, 'currency' => 'PLN', 'status' => 'paid']);
    });

    it('is for a signed-in driver only', function () {
        $this->getJson('/api/v1/premium')->assertUnauthorized();
    });

    it('sells nothing when the rules have no catalog, and the balance is still the driver\'s', function () {
        $user = User::factory()->guest()->create();
        (new Ledger)->postPremium($user->id, 5, 'gift', 'gift:1');
        $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($data['premium']);
        app()->singleton(Rules::class, fn () => Rules::fromArray($data));

        $this->getJson('/api/v1/premium', bearerFor($user))
            ->assertOk()
            ->assertJsonPath('data.balance', 5)
            ->assertJsonPath('data.currency', null)
            ->assertJsonPath('data.products', []);
    });
});

describe('giving coins that were not paid for', function () {
    it('puts them in the wallet with the reason in the ledger', function () {
        $user = User::factory()->guest()->create(['email' => 'support@example.com']);

        $this->artisan('premium:grant', ['coins' => '30', '--id' => $user->id, '--reason' => 'support'])
            ->expectsOutputToContain('has 30 premium coins now')
            ->assertSuccessful();

        $entry = LedgerEntry::query()->where('user_id', $user->id)->sole();

        expect($entry->currency)->toBe('premium')
            ->and($entry->reason)->toBe('support')
            ->and($entry->delta)->toBe(30);
    });

    it('gives once for a key that is run again', function () {
        $user = User::factory()->guest()->create();

        Artisan::call('premium:grant', ['coins' => '30', '--id' => $user->id, '--key' => 'promo-1']);
        Artisan::call('premium:grant', ['coins' => '30', '--id' => $user->id, '--key' => 'promo-1']);

        expect((new Ledger)->premiumBalance($user->id))->toBe(30);
    });

    it('refuses coins that are not a whole number of 1 to 1 000 000, and a reason that is not a word', function () {
        $user = User::factory()->guest()->create();

        $this->artisan('premium:grant', ['coins' => '0', '--id' => $user->id])->assertFailed();
        $this->artisan('premium:grant', ['coins' => '-5', '--id' => $user->id])->assertFailed();
        $this->artisan('premium:grant', ['coins' => '1000001', '--id' => $user->id])->assertFailed();
        $this->artisan('premium:grant', ['coins' => '5', '--id' => $user->id, '--reason' => 'Free Money!'])->assertFailed();

        expect((new Ledger)->premiumBalance($user->id))->toBe(0);
    });

    it('does not guess the account: exactly one of the ways to name it', function () {
        User::factory()->guest()->create();

        $this->artisan('premium:grant', ['coins' => '5'])->assertFailed();
    });
});
