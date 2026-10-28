<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\RuleRefusedException;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger;

// The premium coins are a second balance of the wallet, moved through the same ledger as the cash: one lock, one key per posting, an entry for every change.

function coinsLedger(): Ledger
{
    return new Ledger;
}

function coinsDriver(): User
{
    return User::factory()->guest()->create();
}

it('credits coins, on the premium side of the ledger and not on the cash', function () {
    $user = coinsDriver();

    $entry = coinsLedger()->postPremium($user->id, 50, 'gift', 'gift:1');

    expect($entry->currency)->toBe('premium')
        ->and($entry->delta)->toBe(50)
        ->and($entry->balance_after)->toBe(50)
        ->and(coinsLedger()->premiumBalance($user->id))->toBe(50)
        ->and(coinsLedger()->balance($user->id))->toBe(0);
});

it('keeps the two balances apart: a posting of cash does not touch the coins and the other way round', function () {
    $user = coinsDriver();

    coinsLedger()->post($user->id, 1_000, 'settlement', 'settle:1');
    coinsLedger()->postPremium($user->id, 20, 'gift', 'gift:1');
    coinsLedger()->post($user->id, -300, 'refuel', 'refuel:1');
    coinsLedger()->postPremium($user->id, -5, 'speedup', 'speedup:1');

    expect(coinsLedger()->balance($user->id))->toBe(700)
        ->and(coinsLedger()->premiumBalance($user->id))->toBe(15)
        ->and(coinsLedger()->isConsistent($user->id))->toBeTrue()
        ->and(coinsLedger()->isConsistent($user->id, Ledger::PREMIUM))->toBeTrue()
        ->and(LedgerEntry::query()->where('user_id', $user->id)->where('currency', 'premium')->count())->toBe(2);
});

it('posts a repeated key once: the first entry comes back and nothing changes', function () {
    $user = coinsDriver();

    $first = coinsLedger()->postPremium($user->id, 50, 'gift', 'gift:1');
    $again = coinsLedger()->postPremium($user->id, 50, 'gift', 'gift:1');

    expect($again->id)->toBe($first->id)
        ->and(coinsLedger()->premiumBalance($user->id))->toBe(50)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('refuses a key that was used for another posting, also when it was one of the other currency', function () {
    $user = coinsDriver();
    coinsLedger()->post($user->id, 50, 'gift', 'gift:1');

    expect(fn () => coinsLedger()->postPremium($user->id, 50, 'gift', 'gift:1'))->toThrow(IdempotencyConflictException::class)
        ->and(coinsLedger()->premiumBalance($user->id))->toBe(0);
});

it('does not let spending take the balance below zero, and says what is missing', function () {
    $user = coinsDriver();
    coinsLedger()->postPremium($user->id, 30, 'gift', 'gift:1');

    try {
        coinsLedger()->postPremium($user->id, -31, 'speedup', 'speedup:1');
        $thrown = null;
    } catch (RuleRefusedException $e) {
        $thrown = $e;
    }

    expect($thrown?->reason)->toBe('insufficient_premium')
        ->and($thrown?->replace)->toBe(['available' => 30, 'required' => 31])
        ->and(coinsLedger()->premiumBalance($user->id))->toBe(30)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('lets a payment that is taken back leave a debt, and a later purchase pays it off', function () {
    $user = coinsDriver();
    coinsLedger()->postPremium($user->id, 50, 'purchase', 'purchase:1');
    coinsLedger()->postPremium($user->id, -40, 'speedup', 'speedup:1');

    $refund = coinsLedger()->postPremium($user->id, -50, 'refund', 'refund:1', allowDebt: true);

    expect($refund->balance_after)->toBe(-40)
        ->and(coinsLedger()->premiumBalance($user->id))->toBe(-40);

    // A driver in debt cannot spend (the balance is below zero), and the next pack comes first against the debt.
    expect(fn () => coinsLedger()->postPremium($user->id, -1, 'speedup', 'speedup:2'))->toThrow(RuleRefusedException::class);

    coinsLedger()->postPremium($user->id, 220, 'purchase', 'purchase:2');

    expect(coinsLedger()->premiumBalance($user->id))->toBe(180)
        ->and(coinsLedger()->isConsistent($user->id, Ledger::PREMIUM))->toBeTrue();
});

it('refuses a posting that changes nothing', function () {
    coinsLedger()->postPremium(coinsDriver()->id, 0, 'gift', 'gift:0');
})->throws(InvalidArgumentException::class);
