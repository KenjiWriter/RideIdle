<?php

declare(strict_types=1);

/*
| A worker process of ConcurrencyTest: boots the application, waits for the common start time so that
| all workers collide, runs its operations one after another and prints one JSON result per operation.
| A failed operation is reported, not thrown, so the test can tell a refused payment from a deadlock.
*/

use App\Domain\Run\CarStats;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Services\Ledger;
use App\Services\RunService;
use App\Services\TowTerms;

require __DIR__.'/../../vendor/autoload.php';

/** The same car, track and dry weather as dryInputs() in tests/Pest.php, which this process does not load. */
function workerInputs(int $breakChancePpm): RunInputs
{
    return new RunInputs(
        car: new CarStats(['grip' => 100.0, 'power' => 100.0]),
        track: new TrackProfile(['grip' => 0.5, 'power' => 0.5], ['grip' => 100.0, 'power' => 100.0], 75_000, 1_500, 2_000, 30),
        weatherChancesPpm: ['dry' => 1_000_000],
        weatherEffects: [],
        climateSeed: 'region-zlomowo',
        breakChancePerLapPpm: $breakChancePpm,
    );
}

/** @var array{start_at: float, ops: list<array<string, mixed>>} $job */
$job = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

while (microtime(true) < $job['start_at']) {
    usleep(200);
}

$ledger = new Ledger();
$runs = new RunService($ledger);
$results = [];

foreach ($job['ops'] as $op) {
    try {
        $value = match ($op['type']) {
            'post' => ['entry' => $ledger->post($op['user'], $op['delta'], $op['reason'], $op['key'])->id],
            'settle' => (function () use ($runs, $op): array {
                $settled = $runs->settle($op['run'], $op['now']);

                return ['changed' => $settled->changedAnything(), 'cash' => $settled->result->cashCents, 'laps' => $settled->result->laps];
            })(),
            'refuel' => (function () use ($runs, $op): array {
                $refueled = $runs->refuel($op['run'], $op['request'], $op['tank'], $op['price'], $op['add'], $op['now']);

                return ['added' => $refueled->addedMl, 'cost' => $refueled->costCents, 'repeated' => $refueled->repeated];
            })(),
            'start' => ['run' => $runs->startFirstRun($op['user'], 'rdzak-gt-4', 'zlomowo-street', workerInputs(0), 45_000, $op['now'])->id],
            'tow' => (function () use ($runs, $op): array {
                $towed = $runs->tow($op['run'], workerInputs(50_000), new TowTerms(3_000, 200, 300_000, 2), $op['now']);

                return ['towed' => $towed->towed];
            })(),
            'repair' => (function () use ($runs, $op): array {
                $repaired = $runs->repair($op['run'], workerInputs($op['chance']), $op['now'], $op['cost'], $op['request']);

                return ['repaired' => $repaired->repaired, 'cost' => $repaired->costCents, 'repeated' => $repaired->repeated];
            })(),
        };

        $results[] = ['ok' => true, 'type' => $op['type'], 'value' => $value];
    } catch (Throwable $e) {
        $results[] = ['ok' => false, 'type' => $op['type'], 'error' => $e::class, 'message' => $e->getMessage()];
    }
}

echo json_encode($results, JSON_THROW_ON_ERROR);
