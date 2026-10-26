<?php

declare(strict_types=1);

use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\ShiftGate;

/** A kind of player that bought the cars of the ladder on these days (the first is the car of rung 1). */
function runBoughtOn(array $days): PlayerRun
{
    $player = economyRules()->players()['A'];
    $purchases = [];

    foreach ($days as $index => $day) {
        $purchases[] = new Purchase($index + 1, $day, $day, $day);
    }

    return new PlayerRun($player, $purchases, (int) end($days), 0.0, 0.0);
}

describe('the shift gate', function () {
    it('passes when the days stay where they were', function () {
        $days = [3, 8, 9, 10, 13, 16, 22, 36];

        $report = (new ShiftGate)->evaluate(economyRules(), ['A' => runBoughtOn($days)], ['A' => runBoughtOn($days)]);

        expect($report->passed())->toBeTrue()->and($report->rows)->toHaveCount(8)
            ->and($report->rows[0]->target)->toBe(3)->and($report->rows[0]->day)->toBe(3);
    });

    it('allows a day either way, and a share of the day of the scenario without the extra', function () {
        // One day late on day 3 is the resolution of the model; 4 days of 16 is 25%, which is allowed, and 5 of 16 is not.
        $baseline = ['A' => runBoughtOn([3, 8, 9, 10, 13, 16, 22, 36])];

        expect((new ShiftGate)->evaluate(economyRules(), $baseline, ['A' => runBoughtOn([4, 8, 9, 10, 13, 20, 22, 36])])->passed())->toBeTrue()
            ->and((new ShiftGate)->evaluate(economyRules(), $baseline, ['A' => runBoughtOn([3, 8, 9, 10, 13, 21, 22, 36])])->passed())->toBeFalse();
    });

    it('says which days are out', function () {
        $baseline = ['A' => runBoughtOn([3, 8, 9, 10, 13, 16, 22, 36])];
        $report = (new ShiftGate)->evaluate(economyRules(), $baseline, ['A' => runBoughtOn([3, 8, 9, 10, 13, 16, 22, 60])]);

        expect($report->failures())->toHaveCount(1)
            ->and($report->failures()[0]->rung)->toBe('L8')
            ->and($report->failures()[0]->deviation())->toEqualWithDelta((60 - 36) / 36, 1e-9);
    });

    it('fails a car that the player never bought with the extra, and has nothing to say about one that was not bought without it', function () {
        $baseline = ['A' => runBoughtOn([3, 8, 9])];

        expect((new ShiftGate)->evaluate(economyRules(), $baseline, ['A' => runBoughtOn([3, 8])])->failures())->toHaveCount(1)
            ->and((new ShiftGate)->evaluate(economyRules(), $baseline, ['A' => runBoughtOn([3, 8, 9, 12])])->rows)->toHaveCount(3);
    });
});
