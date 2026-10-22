<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;

function ledger(): Ledger
{
    return new Ledger();
}

function aDriver(): User
{
    return User::factory()->guest()->create();
}

it('credits a driver and creates the wallet on the first posting', function () {
    $user = aDriver();

    $entry = ledger()->post($user->id, 46_500, 'settlement', 'settle:1:0');

    expect($entry->delta)->toBe(46_500)
        ->and($entry->balance_after)->toBe(46_500)
        ->and($entry->currency)->toBe('cash')
        ->and($entry->reason)->toBe('settlement')
        ->and(ledger()->balance($user->id))->toBe(46_500)
        ->and(Wallet::query()->count())->toBe(1);
});

it('creates the wallet once, before any lock is taken', function () {
    $user = aDriver();

    ledger()->ensureWallet($user->id);
    ledger()->ensureWallet($user->id);

    expect(Wallet::query()->count())->toBe(1)
        ->and(ledger()->balance($user->id))->toBe(0);
});

it('keeps the balance after every posting', function () {
    $user = aDriver();

    ledger()->post($user->id, 10_000, 'settlement', 'a');
    $second = ledger()->post($user->id, -2_500, 'refuel', 'b');
    $third = ledger()->post($user->id, 400, 'settlement', 'c');

    expect($second->balance_after)->toBe(7_500)
        ->and($third->balance_after)->toBe(7_900)
        ->and(ledger()->balance($user->id))->toBe(7_900)
        ->and(ledger()->isConsistent($user->id))->toBeTrue();
});

it('does nothing the second time a posting with the same key arrives', function () {
    $user = aDriver();

    $first = ledger()->post($user->id, 46_500, 'settlement', 'settle:1:0');
    $again = ledger()->post($user->id, 46_500, 'settlement', 'settle:1:0');

    expect($again->is($first))->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(ledger()->balance($user->id))->toBe(46_500);
});

it('refuses to reuse a key for a different posting', function (int $userOffset, int $delta, string $reason) {
    $user = aDriver();
    $other = aDriver();
    ledger()->post($user->id, 1_000, 'settlement', 'shared-key');

    $target = $userOffset === 0 ? $user : $other;

    expect(fn () => ledger()->post($target->id, $delta, $reason, 'shared-key'))->toThrow(IdempotencyConflictException::class);
    expect(ledger()->balance($user->id))->toBe(1_000)
        ->and(LedgerEntry::query()->count())->toBe(1);
})->with([
    'other amount' => [0, 2_000, 'settlement'],
    'other reason' => [0, 1_000, 'refuel'],
    'other driver' => [1, 1_000, 'settlement'],
]);

it('refuses a cost that the balance cannot cover and changes nothing', function () {
    $user = aDriver();
    ledger()->post($user->id, 1_000, 'settlement', 'a');

    try {
        ledger()->post($user->id, -1_001, 'refuel', 'b');
        $this->fail('The posting should have been refused.');
    } catch (InsufficientFundsException $exception) {
        expect($exception->availableCents)->toBe(1_000)->and($exception->requiredCents)->toBe(1_001);
    }

    expect(ledger()->balance($user->id))->toBe(1_000)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('allows spending exactly the whole balance', function () {
    $user = aDriver();
    ledger()->post($user->id, 1_000, 'settlement', 'a');

    ledger()->post($user->id, -1_000, 'purchase', 'b');

    expect(ledger()->balance($user->id))->toBe(0)
        ->and(ledger()->isConsistent($user->id))->toBeTrue();
});

it('refuses a refused cost again with the same key, so it cannot sneak in later', function () {
    $user = aDriver();

    expect(fn () => ledger()->post($user->id, -100, 'refuel', 'cost-1'))->toThrow(InsufficientFundsException::class);

    ledger()->post($user->id, 500, 'settlement', 'income-1');

    // A new posting is a new decision; the refused one left no trace that could block or duplicate it.
    ledger()->post($user->id, -100, 'refuel', 'cost-1');

    expect(ledger()->balance($user->id))->toBe(400);
});

it('rejects invalid postings', function (int $delta, string $reason, string $key) {
    ledger()->post(aDriver()->id, $delta, $reason, $key);
})->throws(InvalidArgumentException::class)->with([
    'zero amount' => [0, 'settlement', 'a'],
    'no reason' => [100, '', 'a'],
    'no key' => [100, 'settlement', ''],
    'key too long' => [100, 'settlement', str_repeat('k', 129)],
]);

it('refuses a driver that does not exist', function () {
    ledger()->post(999_999, 100, 'settlement', 'a');
})->throws(Exception::class);

it('detects a balance that no longer matches the ledger', function () {
    $user = aDriver();
    ledger()->post($user->id, 1_000, 'settlement', 'a');

    Wallet::query()->whereKey($user->id)->update(['cash_cents' => 1_001]);

    expect(ledger()->isConsistent($user->id))->toBeFalse();
});

it('keeps the balance equal to the ledger after many random postings', function () {
    $user = aDriver();
    $random = new Random\Randomizer(new Random\Engine\Mt19937(3));

    for ($i = 0; $i < 300; $i++) {
        $delta = $random->getInt(-2_000, 3_000) ?: 1;

        try {
            ledger()->post($user->id, $delta, 'test', "k{$i}");
        } catch (InsufficientFundsException) {
            // Refused costs are part of the scenario.
        }

        // Retried half of the time: must change nothing.
        if ($random->getInt(0, 1) === 1) {
            try {
                ledger()->post($user->id, $delta, 'test', "k{$i}");
            } catch (InsufficientFundsException) {
            }
        }
    }

    expect(ledger()->isConsistent($user->id))->toBeTrue()
        ->and(ledger()->balance($user->id))->toBeGreaterThanOrEqual(0);
});

it('keeps the wallets of two drivers apart', function () {
    $a = aDriver();
    $b = aDriver();

    ledger()->post($a->id, 1_000, 'settlement', 'a');
    ledger()->post($b->id, 50, 'settlement', 'b');

    expect(ledger()->balance($a->id))->toBe(1_000)
        ->and(ledger()->balance($b->id))->toBe(50);
});

it('grants experience to the wallet', function () {
    $user = aDriver();

    ledger()->grantXp($user->id, 750);
    ledger()->grantXp($user->id, 25);
    ledger()->grantXp($user->id, 0);

    expect(Wallet::query()->findOrFail($user->id)->xp)->toBe(775);
});

it('refuses negative experience', function () {
    ledger()->grantXp(aDriver()->id, -1);
})->throws(InvalidArgumentException::class);
