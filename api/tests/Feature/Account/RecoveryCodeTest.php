<?php

declare(strict_types=1);

use App\Support\RecoveryCode;

it('generates codes in the XXXX-XXXX-XXXX form without ambiguous characters', function () {
    foreach (range(1, 300) as $i) {
        expect(RecoveryCode::generate())->toMatch('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/');
    }
});

it('generates different codes', function () {
    $codes = array_map(static fn () => RecoveryCode::generate(), range(1, 200));

    expect(array_unique($codes))->toHaveCount(200);
});

it('normalizes codes typed in any style', function (string $input) {
    expect(RecoveryCode::normalize($input))->toBe('ABCDEFGHJKLM');
})->with([
    'display form' => ['ABCD-EFGH-JKLM'],
    'lower case' => ['abcd-efgh-jklm'],
    'spaces' => [' abcd efgh jklm '],
    'no separators' => ['abcdefghjklm'],
]);

it('rejects input that cannot be a code', function (string $input) {
    expect(RecoveryCode::normalize($input))->toBeNull();
})->with([
    'too short' => ['ABCD-EFGH'],
    'too long' => ['ABCD-EFGH-JKLM-NPQR'],
    'has the letter O' => ['ABCD-EFGH-JKLO'],
    'has the digit zero' => ['ABCD-EFGH-JKL0'],
    'has the digit one' => ['ABCD-EFGH-JKL1'],
    'has the letter I' => ['ABCD-EFGH-JKLI'],
    'empty' => [''],
]);

it('hashes deterministically with a keyed hash', function () {
    $hash = RecoveryCode::hash('ABCDEFGHJKLM');

    expect($hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and(RecoveryCode::hash('ABCDEFGHJKLM'))->toBe($hash)
        ->and(RecoveryCode::hash('ABCDEFGHJKLN'))->not->toBe($hash)
        ->and($hash)->not->toBe(hash('sha256', 'ABCDEFGHJKLM'));
});
