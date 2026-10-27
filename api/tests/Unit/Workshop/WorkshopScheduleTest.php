<?php

declare(strict_types=1);

use App\Domain\Workshop\WorkshopGear;
use App\Domain\Workshop\WorkshopSchedule;

describe('where a job starts', function () {
    it('starts now when nobody is at work', function () {
        expect(WorkshopSchedule::startFor([], 1, 5_000, 60_000))->toBe(5_000);
    });

    it('waits for the end of the last job when there is one mechanic, as the queue of a workshop always did', function () {
        $placed = [['start_ms' => 0, 'finish_ms' => 100_000], ['start_ms' => 100_000, 'finish_ms' => 160_000]];

        expect(WorkshopSchedule::startFor($placed, 1, 10_000, 30_000))->toBe(160_000);
    });

    it('starts beside a job that is being done when there is a second mechanic', function () {
        $placed = [['start_ms' => 0, 'finish_ms' => 100_000]];

        expect(WorkshopSchedule::startFor($placed, 2, 20_000, 30_000))->toBe(20_000)
            ->and(WorkshopSchedule::startFor($placed, 1, 20_000, 30_000))->toBe(100_000);
    });

    it('goes to the first moment a mechanic is free for the whole job, when all of them are busy', function () {
        $placed = [['start_ms' => 0, 'finish_ms' => 100_000], ['start_ms' => 0, 'finish_ms' => 50_000]];

        expect(WorkshopSchedule::startFor($placed, 2, 0, 30_000))->toBe(50_000);
    });

    it('fills a gap that is long enough, and does not start a job in a gap that is too short', function () {
        // A job on a car that has to wait for its other job left the mechanic idle from 10 s to 40 s.
        $placed = [['start_ms' => 0, 'finish_ms' => 10_000], ['start_ms' => 40_000, 'finish_ms' => 100_000]];

        expect(WorkshopSchedule::startFor($placed, 1, 0, 30_000))->toBe(10_000)
            ->and(WorkshopSchedule::startFor($placed, 1, 0, 31_000))->toBe(100_000);
    });

    it('does not start before the moment it is asked for, which is where the job before it on the same car ends', function () {
        $placed = [['start_ms' => 0, 'finish_ms' => 50_000]];

        expect(WorkshopSchedule::startFor($placed, 3, 50_000, 10_000))->toBe(50_000)
            ->and(WorkshopSchedule::startFor($placed, 3, 80_000, 10_000))->toBe(80_000);
    });

    it('never has more jobs at work than mechanics, whatever order they come in', function () {
        $placed = [];
        $workers = 3;
        $durations = [90_000, 30_000, 60_000, 45_000, 120_000, 15_000, 75_000, 30_000, 30_000, 60_000];

        foreach ($durations as $index => $duration) {
            $start = WorkshopSchedule::startFor($placed, $workers, ($index % 4) * 20_000, $duration);
            $placed[] = ['start_ms' => $start, 'finish_ms' => $start + $duration];
        }

        foreach ($placed as $job) {
            $atWork = count(array_filter($placed, fn (array $other): bool => $other['start_ms'] <= $job['start_ms'] && $other['finish_ms'] > $job['start_ms']));

            expect($atWork)->toBeLessThanOrEqual($workers);
        }
    });
});

describe('the workshop that has bought nothing', function () {
    it('has one bay, one mechanic and no equipment', function () {
        $gear = WorkshopGear::start();

        expect($gear->workers())->toBe(1)
            ->and([$gear->tireMachine, $gear->lift, $gear->hoist, $gear->toolkit])->toBe([0, 0, 0, 0]);
    });

    it('lets as many mechanics work as there are bays for them', function () {
        expect((new WorkshopGear(bays: 3, mechanics: 2))->workers())->toBe(2)
            ->and((new WorkshopGear(bays: 2, mechanics: 4))->workers())->toBe(2)
            ->and(WorkshopGear::start()->with(WorkshopGear::BAYS, 2)->with(WorkshopGear::MECHANICS, 2)->workers())->toBe(2);
    });

    it('refuses an item it does not have', function () {
        expect(fn () => WorkshopGear::start()->level('compressor'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => WorkshopGear::start()->with('compressor', 1))->toThrow(InvalidArgumentException::class);
    });
});
