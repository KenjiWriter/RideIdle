<?php

declare(strict_types=1);

use App\Domain\Settlement\EloTable;
use App\Domain\Settlement\Rating;
use Random\Engine\Mt19937;
use Random\Randomizer;

function eloTable(): EloTable
{
    static $table = null;

    // api/tests/Unit/Settlement -> repository root is four levels up.
    return $table ??= EloTable::fromFile(dirname(__DIR__, 4).'/content/tables/elo-expected-ppm.json');
}

it('W7: a win against a rival 200 points higher gains +30 and a loss costs -10 (K = 40)', function () {
    $table = eloTable();

    expect($table->expectedPpm(200))->toBe(240_253)
        ->and(Rating::delta($table, 40, true, 1_000, 1_200))->toBe(30)
        ->and(Rating::delta($table, 40, false, 1_000, 1_200))->toBe(-10);
});

it('W7: the same match with K = 24 gives +18 and -6', function () {
    expect(Rating::delta(eloTable(), 24, true, 1_000, 1_200))->toBe(18)
        ->and(Rating::delta(eloTable(), 24, false, 1_000, 1_200))->toBe(-6);
});

it('W7: beating a rival 400 points lower gives only +2 and losing to them costs -22 (K = 24)', function () {
    expect(Rating::delta(eloTable(), 24, true, 1_400, 1_000))->toBe(2)
        ->and(Rating::delta(eloTable(), 24, false, 1_400, 1_000))->toBe(-22);
});

it('treats equal ratings as a coin flip: +12 and -12 at K = 24', function () {
    expect(eloTable()->expectedPpm(0))->toBe(500_000)
        ->and(Rating::delta(eloTable(), 24, true, 1_300, 1_300))->toBe(12)
        ->and(Rating::delta(eloTable(), 24, false, 1_300, 1_300))->toBe(-12);
});

it('matches the table of the specification for the shown differences', function (int $difference, int $percent) {
    expect(intdiv(eloTable()->expectedPpm($difference) + 5_000, 10_000))->toBe($percent);
})->with([
    'rival 100 higher' => [100, 36],
    'rival 200 higher' => [200, 24],
    'rival 400 lower' => [-400, 91],
]);

it('has 1601 entries from -800 to +800 and is symmetric', function () {
    $table = eloTable();

    for ($difference = 0; $difference <= 800; $difference++) {
        // Expected scores of the two players of a match add up to a whole point (rounding allows 1 ppm).
        expect(abs($table->expectedPpm($difference) + $table->expectedPpm(-$difference) - 1_000_000))->toBeLessThanOrEqual(1);
    }
});

it('falls as the rival gets stronger', function () {
    $table = eloTable();

    for ($difference = -800; $difference < 800; $difference++) {
        expect($table->expectedPpm($difference))->toBeGreaterThanOrEqual($table->expectedPpm($difference + 1));
    }
});

it('clamps differences beyond the table', function () {
    $table = eloTable();

    expect($table->expectedPpm(5_000))->toBe($table->expectedPpm(800))
        ->and($table->expectedPpm(-5_000))->toBe($table->expectedPpm(-800));
});

it('is the table that the generator produces (not stale)', function () {
    expect(eloTable()->expectedPpm(0))->toBe(500_000);

    $stored = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/content/tables/elo-expected-ppm.json'), true)['expected_ppm'];

    expect($stored)->toBe(EloTable::compute());
});

it('moves both ratings by about the same amount in opposite directions', function () {
    $table = eloTable();
    $random = new Randomizer(new Mt19937(11));

    for ($i = 0; $i < 5_000; $i++) {
        $a = $random->getInt(600, 2_200);
        $b = $random->getInt(600, 2_200);
        $k = [16, 24, 40][$random->getInt(0, 2)];

        $winner = Rating::delta($table, $k, true, $a, $b);
        $loser = Rating::delta($table, $k, false, $b, $a);

        // Rounding half away from zero can differ by one point between the two sides.
        expect(abs($winner + $loser))->toBeLessThanOrEqual(1)
            ->and($winner)->toBeGreaterThanOrEqual(0)
            ->and($loser)->toBeLessThanOrEqual(0)
            ->and($winner)->toBeLessThanOrEqual($k);
    }
});

it('gives bigger rewards for beating stronger rivals', function () {
    $table = eloTable();

    expect(Rating::delta($table, 24, true, 1_000, 1_300))->toBeGreaterThan(Rating::delta($table, 24, true, 1_000, 1_000))
        ->and(Rating::delta($table, 24, true, 1_000, 1_000))->toBeGreaterThan(Rating::delta($table, 24, true, 1_300, 1_000));
});

it('chooses the K factor from experience and rating', function (int $matches, int $rating, int $k) {
    expect(Rating::kFactor($matches, $rating))->toBe($k);
})->with([
    'new driver' => [0, 1_000, 40],
    'last calibration match' => [19, 2_000, 40],
    'established below 1700' => [20, 1_699, 24],
    'established at 1700' => [20, 1_700, 16],
    'veteran at the top' => [500, 2_400, 16],
]);

it('puts drivers into divisions', function (int $rating, string $division) {
    expect(Rating::division($rating))->toBe($division);
})->with([
    [0, 'street'],
    [1_099, 'street'],
    [1_100, 'club'],
    [1_399, 'club'],
    [1_400, 'regional'],
    [1_699, 'regional'],
    [1_700, 'elite'],
    [2_500, 'elite'],
]);

it('starts a season with 75 percent of the rating and 25 percent of the start value', function (int $before, int $after) {
    expect(Rating::seasonReset($before))->toBe($after);
})->with([
    [1_000, 1_000],
    [1_800, 1_600],
    [1_400, 1_300],
    [1_001, 1_001],
    [1_003, 1_002],
    [600, 700],
]);

it('refuses a table of the wrong size', function () {
    EloTable::fromJson('{"expected_ppm": [1, 2, 3]}');
})->throws(InvalidArgumentException::class);

it('refuses a table with values that are not integers', function () {
    EloTable::fromJson(json_encode(['expected_ppm' => array_fill(0, 1_601, 0.5)]));
})->throws(InvalidArgumentException::class);

it('refuses a file that does not exist', function () {
    EloTable::fromFile(sys_get_temp_dir().'/missing-elo-table.json');
})->throws(InvalidArgumentException::class);
