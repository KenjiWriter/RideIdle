<?php

declare(strict_types=1);

use App\Domain\Economy\UnitEconomy;

/**
 * A table of two kinds of unit, made of round numbers: a small one that costs $1,000 and pays back 90% on average, and a large one that costs
 * $5,000 and pays back 80%. A sale of a small one is $100 at the least, $500 in the middle and $5,000 at the most.
 */
function unitEconomyOf(): UnitEconomy
{
    return new UnitEconomy([
        // The large one first: the kinds are put in the order of their price, whatever the order of the table.
        'large' => ['unlock_level' => 6, 'price' => 5_000.0, 'mean_return' => 0.8, 'quantiles' => [['p' => 0.0, 'dollars' => 500.0], ['p' => 50.0, 'dollars' => 2_000.0], ['p' => 100.0, 'dollars' => 20_000.0]]],
        'small' => ['unlock_level' => 3, 'price' => 1_000.0, 'mean_return' => 0.9, 'quantiles' => [['p' => 0.0, 'dollars' => 100.0], ['p' => 50.0, 'dollars' => 500.0], ['p' => 100.0, 'dollars' => 5_000.0]]],
    ]);
}

describe('the returns of the storage units', function () {
    it('has the kinds in the order of their price, with their level, price and mean return', function () {
        $units = unitEconomyOf();

        expect($units->kinds())->toBe(['small', 'large'])
            ->and($units->unlockLevel('large'))->toBe(6)
            ->and($units->price('small'))->toBe(1_000.0)
            ->and($units->meanReturn('small'))->toBe(0.9);
    });

    it('is sure of a sale that is at most the lowest one, and cannot get one above the highest', function () {
        $units = unitEconomyOf();

        expect($units->chanceOfAtLeast('small', 50.0))->toBe(1.0)
            ->and($units->chanceOfAtLeast('small', 100.0))->toBe(1.0)
            ->and($units->chanceOfAtLeast('small', 5_000.01))->toBe(0.0);
    });

    it('reads the chance of a sale of at least a sum from the percentiles, on a straight line between two of them', function () {
        $units = unitEconomyOf();

        // The middle sale is $500: half of the sales are at least that much.
        expect($units->chanceOfAtLeast('small', 500.0))->toEqualWithDelta(0.5, 1e-12)
            // $300 is halfway from $100 to $500, so the 25th percentile: three of four sales are at least that much.
            ->and($units->chanceOfAtLeast('small', 300.0))->toEqualWithDelta(0.75, 1e-12)
            // $2,750 is halfway from $500 to $5,000, so the 75th percentile.
            ->and($units->chanceOfAtLeast('small', 2_750.0))->toEqualWithDelta(0.25, 1e-12)
            ->and($units->chanceOfAtLeast('large', 11_000.0))->toEqualWithDelta(0.25, 1e-12);
    });

    it('gives a chance that never rises with the sum', function () {
        $units = unitEconomyOf();
        $chances = array_map(fn (int $dollars): float => $units->chanceOfAtLeast('large', (float) $dollars), range(0, 25_000, 250));

        expect($chances)->toBe(collect($chances)->sortDesc()->values()->all());
    });

    it('copes with sales that are the same for a stretch of the percentiles, as the scrap floor makes them', function () {
        $units = new UnitEconomy(['small' => [
            'unlock_level' => 3, 'price' => 1_000.0, 'mean_return' => 0.9,
            'quantiles' => [['p' => 0.0, 'dollars' => 250.0], ['p' => 10.0, 'dollars' => 250.0], ['p' => 20.0, 'dollars' => 250.0], ['p' => 100.0, 'dollars' => 1_000.0]],
        ]]);

        // A sale of $250 is the lowest, so it is certain; a little above it only the sales above the plateau are left (four in five).
        expect($units->chanceOfAtLeast('small', 250.0))->toBe(1.0)
            ->and($units->chanceOfAtLeast('small', 250.01))->toBeGreaterThan(0.79)->toBeLessThan(0.81);
    });

    it('opens the most expensive kind that the level opens and the budget pays for', function () {
        $units = unitEconomyOf();

        expect($units->kindFor(2, 100_000.0))->toBeNull()
            ->and($units->kindFor(3, 100_000.0))->toBe('small')
            ->and($units->kindFor(5, 100_000.0))->toBe('small')
            ->and($units->kindFor(6, 100_000.0))->toBe('large')
            ->and($units->kindFor(6, 4_999.0))->toBe('small')
            ->and($units->kindFor(6, 5_000.0))->toBe('large')
            ->and($units->kindFor(6, 999.0))->toBeNull();
    });

    it('refuses a kind that has no percentiles', function () {
        expect(fn () => new UnitEconomy(['small' => ['unlock_level' => 3, 'price' => 1_000.0, 'mean_return' => 0.9, 'quantiles' => []]]))->toThrow(InvalidArgumentException::class);
    });
});
