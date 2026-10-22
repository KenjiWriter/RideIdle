<?php

declare(strict_types=1);

use App\Domain\Settlement\TournamentPayout;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('W6a: splits a pool exactly when the shares divide evenly (32 players, $800)', function () {
    $result = TournamentPayout::split(32, 80_000);

    expect($result->totalCents)->toBe(2_560_000)
        ->and($result->poolCents)->toBe(2_304_000)
        ->and($result->rakeCents)->toBe(256_000)
        ->and($result->groups)->toBe([
            ['stage' => 'winner', 'count' => 1, 'each_cents' => 806_400],
            ['stage' => 'finalist', 'count' => 1, 'each_cents' => 391_680],
            ['stage' => 'semifinalist', 'count' => 2, 'each_cents' => 207_360],
            ['stage' => 'quarterfinalist', 'count' => 4, 'each_cents' => 103_680],
            ['stage' => 'round_of_16', 'count' => 8, 'each_cents' => 34_560],
        ])
        ->and($result->remainderCents)->toBe(0)
        ->and($result->winnerCents())->toBe(806_400);
});

it('W6b: gives the few cents left by rounding to the winner (32 players, $333)', function () {
    $result = TournamentPayout::split(32, 33_300);

    expect($result->totalCents)->toBe(1_065_600)
        ->and($result->poolCents)->toBe(959_040)
        ->and($result->rakeCents)->toBe(106_560)
        ->and(array_column($result->groups, 'each_cents'))->toBe([335_664, 163_036, 86_313, 43_156, 14_385])
        ->and($result->remainderCents)->toBe(10)
        ->and($result->winnerCents())->toBe(335_674)
        ->and($result->distributedCents())->toBe(959_040);
});

it('pays the example from the specification: 16 players at $800', function () {
    $result = TournamentPayout::split(16, 80_000);

    expect($result->poolCents)->toBe(1_152_000)
        ->and($result->rakeCents)->toBe(128_000)
        ->and(array_column($result->groups, 'each_cents'))->toBe([460_800, 230_400, 115_200, 57_600])
        ->and($result->remainderCents)->toBe(0);
});

it('pays 50, 25 and 12.5 percent twice in a bracket of eight', function () {
    $result = TournamentPayout::split(8, 100_000);

    expect($result->poolCents)->toBe(720_000)
        ->and($result->perPlace())->toBe([360_000, 180_000, 90_000, 90_000]);
});

it('lists the payout of every paying place, best first', function () {
    $places = TournamentPayout::split(16, 80_000)->perPlace();

    expect($places)->toHaveCount(8)
        ->and($places[0])->toBe(460_800)
        ->and($places[1])->toBe(230_400)
        ->and(array_slice($places, 2, 2))->toBe([115_200, 115_200])
        ->and(array_slice($places, 4))->toBe([57_600, 57_600, 57_600, 57_600]);
});

it('always shares exactly the whole pool', function (int $players) {
    $random = new Randomizer(new Mt19937(6));

    for ($i = 0; $i < 2_000; $i++) {
        $fee = $random->getInt(1, 200_000_000);
        $result = TournamentPayout::split($players, $fee);

        expect($result->distributedCents())->toBe($result->poolCents)
            ->and($result->poolCents + $result->rakeCents)->toBe($result->totalCents)
            ->and($result->totalCents)->toBe($players * $fee)
            ->and($result->remainderCents)->toBeGreaterThanOrEqual(0)
            ->and($result->remainderCents)->toBeLessThan($players)
            ->and($result->rakeCents)->toBeGreaterThanOrEqual(intdiv($result->totalCents, 10))
            ->and(array_sum($result->perPlace()))->toBe($result->poolCents);
    }
})->with(TournamentPayout::sizes());

it('never pays a worse place more than a better one', function (int $players) {
    $places = TournamentPayout::split($players, 33_300)->perPlace();
    $sorted = $places;
    rsort($sorted);

    expect($places)->toBe($sorted);
})->with(TournamentPayout::sizes());

it('has shares that add up to the whole pool for every bracket size', function (int $players) {
    expect(TournamentPayout::basisPointsPaid($players))->toBe(10_000);
})->with(TournamentPayout::sizes());

it('supports the three bracket sizes of the first release', function () {
    expect(TournamentPayout::sizes())->toBe([8, 16, 32]);
});

it('refuses unsupported sizes and fees', function (int $players, int $fee) {
    TournamentPayout::split($players, $fee);
})->throws(InvalidArgumentException::class)->with([
    'six players' => [6, 80_000],
    'sixty four players' => [64, 80_000],
    'zero fee' => [16, 0],
    'negative fee' => [16, -100],
]);
