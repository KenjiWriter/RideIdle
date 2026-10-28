<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\RuleRefusedException;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Services\AccountErasure;
use App\Services\AccountExport;
use App\Services\Ledger;
use App\Services\Purchases;

// A purchase of coins with real money: the record of what was paid and the coins that came with it, told by a provider that may tell it twice.

function purchases(): Purchases
{
    return app(Purchases::class);
}

function payingDriver(): User
{
    return User::factory()->guest()->create();
}

describe('a payment that was made', function () {
    it('gives the coins of the pack and records what was paid and for what', function () {
        $user = payingDriver();

        $purchase = purchases()->fulfil($user->id, 'coins-medium', 'test', 'pay_1');

        expect($purchase->coins)->toBe(220)
            ->and($purchase->amount_minor)->toBe(1999)
            ->and($purchase->currency)->toBe('PLN')
            ->and($purchase->status)->toBe('paid')
            ->and($purchase->provider_ref)->toBe('pay_1')
            ->and((new Ledger)->premiumBalance($user->id))->toBe(220)
            ->and(LedgerEntry::query()->where('user_id', $user->id)->where('currency', 'premium')->value('reason'))->toBe('purchase');
    });

    it('records what the provider charged when it says so, because the catalog may change later', function () {
        $user = payingDriver();

        $purchase = purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1', 1_200, 'EUR');

        expect($purchase->amount_minor)->toBe(1_200)
            ->and($purchase->currency)->toBe('EUR')
            ->and($purchase->coins)->toBe(50);
    });

    it('is told twice and gives the coins once', function () {
        $user = payingDriver();

        $first = purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');
        $again = purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');

        expect($again->id)->toBe($first->id)
            ->and(Purchase::query()->count())->toBe(1)
            ->and((new Ledger)->premiumBalance($user->id))->toBe(50);
    });

    it('is not the same payment when the provider is another, and gives its own coins', function () {
        $user = payingDriver();

        purchases()->fulfil($user->id, 'coins-small', 'stripe', 'pay_1');
        purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');

        expect(Purchase::query()->count())->toBe(2)
            ->and((new Ledger)->premiumBalance($user->id))->toBe(100);
    });

    it('refuses a payment that was recorded for another driver or another pack, and changes nothing', function () {
        $user = payingDriver();
        $other = payingDriver();
        purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');

        expect(fn () => purchases()->fulfil($other->id, 'coins-small', 'test', 'pay_1'))->toThrow(IdempotencyConflictException::class)
            ->and(fn () => purchases()->fulfil($user->id, 'coins-large', 'test', 'pay_1'))->toThrow(IdempotencyConflictException::class)
            ->and((new Ledger)->premiumBalance($other->id))->toBe(0)
            ->and((new Ledger)->premiumBalance($user->id))->toBe(50);
    });

    it('refuses a pack that the rules do not sell, and records nothing', function () {
        $user = payingDriver();

        try {
            purchases()->fulfil($user->id, 'coins-free', 'test', 'pay_1');
            $thrown = null;
        } catch (RuleRefusedException $e) {
            $thrown = $e;
        }

        expect($thrown?->reason)->toBe('product_not_found')
            ->and(Purchase::query()->count())->toBe(0)
            ->and((new Ledger)->premiumBalance($user->id))->toBe(0);
    });

    it('is recorded with the long reference of a provider, whose ledger key is shortened', function () {
        $user = payingDriver();
        $reference = 'cs_live_'.str_repeat('a', 110);

        $purchase = purchases()->fulfil($user->id, 'coins-small', 'stripe', $reference);

        expect($purchase->provider_ref)->toBe($reference)
            ->and((new Ledger)->premiumBalance($user->id))->toBe(50);

        purchases()->fulfil($user->id, 'coins-small', 'stripe', $reference);

        expect((new Ledger)->premiumBalance($user->id))->toBe(50);
    });
});

describe('a payment that was taken back', function () {
    it('takes its coins away and marks the purchase', function () {
        $user = payingDriver();
        $purchase = purchases()->fulfil($user->id, 'coins-medium', 'test', 'pay_1');

        $refunded = purchases()->refund($purchase);

        expect($refunded->status)->toBe('refunded')
            ->and($refunded->refunded_at)->not->toBeNull()
            ->and((new Ledger)->premiumBalance($user->id))->toBe(0)
            ->and((new Ledger)->isConsistent($user->id, Ledger::PREMIUM))->toBeTrue();
    });

    it('is told twice and takes the coins once', function () {
        $user = payingDriver();
        $purchase = purchases()->fulfil($user->id, 'coins-medium', 'test', 'pay_1');
        (new Ledger)->postPremium($user->id, 100, 'gift', 'gift:1');

        purchases()->refund($purchase);
        purchases()->refund(Purchase::query()->findOrFail($purchase->id));

        expect((new Ledger)->premiumBalance($user->id))->toBe(100);
    });

    it('leaves a debt when the coins were spent, and the next purchase pays it off', function () {
        $user = payingDriver();
        $purchase = purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');
        (new Ledger)->postPremium($user->id, -40, 'speedup', 'speedup:1');

        purchases()->refund($purchase);

        expect((new Ledger)->premiumBalance($user->id))->toBe(-40);

        purchases()->fulfil($user->id, 'coins-medium', 'test', 'pay_2');

        expect((new Ledger)->premiumBalance($user->id))->toBe(180);
    });
});

describe('the account that paid', function () {
    it('is exported with the coins and the purchases, without the ids that only the shop uses', function () {
        $user = payingDriver();
        purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');

        $game = (new AccountExport)->for($user)['game'];

        expect($game['wallet']['premium_coins'])->toBe(50)
            ->and($game['purchases'])->toHaveCount(1)
            ->and($game['purchases'][0])->toMatchArray(['product' => 'coins-small', 'coins' => 50, 'amount_minor' => 499, 'currency' => 'PLN', 'status' => 'paid'])
            ->and($game['purchases'][0])->not->toHaveKeys(['id', 'user_id'])
            ->and(collect($game['ledger'])->pluck('currency')->all())->toBe(['premium']);
    });

    it('is erased, and what it paid stays in the books with the account cut off', function () {
        $user = payingDriver();
        purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');
        $other = payingDriver();
        purchases()->fulfil($other->id, 'coins-large', 'test', 'pay_2');

        (new AccountErasure)->erase($user);

        expect(Purchase::query()->orderBy('id')->pluck('user_id', 'provider_ref')->all())->toBe(['pay_1' => null, 'pay_2' => $other->id])
            ->and(LedgerEntry::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and((new Ledger)->premiumBalance($other->id))->toBe(600);
    });

    it('can still have the payment taken back after the account was erased, and nothing is taken from anybody', function () {
        $user = payingDriver();
        $purchase = purchases()->fulfil($user->id, 'coins-small', 'test', 'pay_1');
        (new AccountErasure)->erase($user);

        $refunded = purchases()->refund($purchase->fresh());

        expect($refunded->status)->toBe('refunded')
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});
