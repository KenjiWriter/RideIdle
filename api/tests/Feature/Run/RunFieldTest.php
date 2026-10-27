<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\RaceRunFactory;

describe('the field of the bots in the answer of the run', function () {
    it('tells the place of the car among the bots, what it pays, and the cars of the field from the quickest', function () {
        $driver = User::factory()->guest()->create();

        $response = test()->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], bearerFor($driver))->assertCreated();

        // The stock Rdzak GT-4 drives the reference lap of its tier on the first track, which is the middle of the field.
        $response->assertJsonPath('data.field.position', 5)
            ->assertJsonPath('data.field.size', 8)
            ->assertJsonPath('data.field.reward_ppm', 1_000_000)
            ->assertJsonCount(8, 'data.field.rows')
            ->assertJsonPath('data.field.rows.0.type', 'bot')
            ->assertJsonPath('data.field.rows.0.lap_ms', 68_250);

        $rows = $response->json('data.field.rows');
        $you = array_values(array_filter($rows, fn (array $row): bool => $row['type'] === 'you'));

        expect($you)->toHaveCount(1)
            ->and($you[0]['name'])->toBeNull()
            ->and($you[0]['lap_ms'])->toBe(75_000)
            ->and(array_column($rows, 'lap_ms'))->toBe(collect(array_column($rows, 'lap_ms'))->sort()->values()->all())
            // The lap of the answer pays what the place pays.
            ->and($response->json('data.lap.cash_cents.dry'))->toBe(1_550);
    });

    it('has no field for a run that was frozen before the bots, which goes on as it was', function () {
        [$driver] = driverWithRun();
        clockAt(10_000);

        getRun($driver)->assertOk()->assertJsonPath('data.field', null);
    });

    it('gives back what the run was frozen with, whatever the rules say now', function () {
        $field = ['position' => 3, 'size' => 8, 'reward_ppm' => 1_100_000, 'rows' => [['name' => 'Bruk Racing', 'type' => 'bot', 'lap_ms' => 70_000], ['name' => null, 'type' => 'you', 'lap_ms' => 71_000]]];
        [$driver] = driverWithRun(['config' => [...RaceRunFactory::constantConfig(), 'field' => $field]]);
        clockAt(10_000);

        getRun($driver)->assertOk()->assertJsonPath('data.field', $field);
    });
});
