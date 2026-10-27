<?php

declare(strict_types=1);

use App\Domain\Economy\UpkeepReport;

/**
 * The upkeep as the game charges it: a service every 5 000 km that costs 4% of what the car earns in them (at least $20), and the repair of a part that
 * costs half of its price for the whole of its life.
 */
function anUpkeepReport(): UpkeepReport
{
    // The laps of the interval are the 5 000 km over the length of a lap, rounded up.
    return new UpkeepReport(5_000, static fn (int $lapCashCents, int $lapLengthM): int => max(2_000, intdiv($lapCashCents * intdiv(5_000_000 + $lapLengthM - 1, $lapLengthM) * 40_000, 1_000_000)), 500_000);
}

/**
 * A level of the ladder: a car whose lap of 2.5 km, driven in 72 s (50 laps an hour, 125 km an hour), pays $18, with the tires of tier 2
 * ($570, a part of the life of 25 000 billionths a kilometre) and brakes ($760, half of that).
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function anUpkeepLevel(array $changes = []): array
{
    return [
        'level' => 3,
        'car' => 'rdzak-gt-4',
        'track' => 'zlomowo-street',
        'net' => 800.0,
        'lap_ms' => 72_000,
        'lap_length_m' => 2_500,
        'lap_cash_cents' => 1_800,
        'parts' => [
            ['slot' => 'tires', 'model' => 'street-tires-t2', 'price_cents' => 57_000, 'ppb_per_km' => 25_000],
            ['slot' => 'brakes', 'model' => 'disc-brakes-t2', 'price_cents' => 76_000, 'ppb_per_km' => 12_500],
        ],
        ...$changes,
    ];
}

it('counts the kilometres of an hour from the laps of an hour and the length of a lap', function () {
    $row = anUpkeepReport()->rows([anUpkeepLevel()])[0];

    expect($row['km_per_hour'])->toBe(125.0);
});

it('charges the service by the kilometre: the cost of a service over the kilometres between two, times the kilometres of an hour', function () {
    $row = anUpkeepReport()->rows([anUpkeepLevel()])[0];

    // 2 000 laps of $18 are $36 000 in 5 000 km, and 4% of that is $1 440, which is $0.288 a kilometre and $36 for the 125 km of an hour.
    expect($row['service'])->toEqualWithDelta(36.0, 1e-9);
});

it('charges the repair of a part by the share of its life that a kilometre uses: half of the price, times the billionths of a kilometre, times the kilometres of an hour', function () {
    $row = anUpkeepReport()->rows([anUpkeepLevel()])[0];

    // The tires: $570 x 0.5 x 25 000 / 1e9 = $0.007125 a kilometre, and $0.890625 an hour; the brakes use half of the life, so cost half of that to the dollar.
    expect($row['set'][0]['per_hour'])->toEqualWithDelta(0.890625, 1e-9)
        ->and($row['set'][1]['per_hour'])->toEqualWithDelta(760 * 0.5 * 12_500 / 1e9 * 125, 1e-9)
        ->and($row['parts'])->toEqualWithDelta(0.890625 + 0.59375, 1e-9)
        ->and($row['set_price'])->toBe(1_330.0);
});

it('sets what an hour of upkeep costs against the income of the hour', function () {
    $row = anUpkeepReport()->rows([anUpkeepLevel()])[0];

    expect($row['upkeep'])->toEqualWithDelta(36.0 + 0.890625 + 0.59375, 1e-9)
        ->and($row['share'])->toEqualWithDelta((36.0 + 0.890625 + 0.59375) / 800, 1e-9);
});

it('charges the least a service costs for a car that earns next to nothing, and no repairs for a level that has no part yet', function () {
    $row = anUpkeepReport()->rows([anUpkeepLevel(['lap_cash_cents' => 1, 'parts' => []])])[0];

    // $20 for 5 000 km is $0.004 a kilometre, $0.50 an hour.
    expect($row['service'])->toEqualWithDelta(0.5, 1e-9)
        ->and($row['parts'])->toBe(0.0)
        ->and($row['set'])->toBe([]);
});

it('wears faster with more kilometres: twice the laps an hour are twice the kilometres, and twice the upkeep', function () {
    $slow = anUpkeepReport()->rows([anUpkeepLevel()])[0];
    $fast = anUpkeepReport()->rows([anUpkeepLevel(['lap_ms' => 36_000])])[0];

    expect($fast['km_per_hour'])->toBe(250.0)
        ->and($fast['upkeep'])->toEqualWithDelta(2 * $slow['upkeep'], 1e-9);
});

it('says the lowest, the middle and the highest share, and the levels where the game charges less than the model and where it charges more', function () {
    $rows = array_map(static fn (int $level, float $share): array => ['level' => $level, 'share' => $share], [1, 3, 6, 9, 12], [0.02, 0.1, 0.05, 0.25, 0.6]);
    $summary = anUpkeepReport()->summary($rows, 0.1);

    // A level that charges exactly as much as the model takes off is in neither list.
    expect($summary)->toBe(['min' => 0.02, 'max' => 0.6, 'median' => 0.1, 'model' => 0.1, 'below' => [1, 6], 'above' => [9, 12]])
        ->and(anUpkeepReport()->summary([], 0.1))->toBe(['min' => 0.0, 'max' => 0.0, 'median' => 0.0, 'model' => 0.1, 'below' => [], 'above' => []]);
});

it('takes the middle of the two in the middle for an even number of levels', function () {
    $rows = array_map(static fn (int $level, float $share): array => ['level' => $level, 'share' => $share], [1, 2, 3, 4], [0.04, 0.08, 0.2, 0.5]);

    expect(anUpkeepReport()->summary($rows, 0.1)['median'])->toEqualWithDelta(0.14, 1e-12);
});
