<?php

declare(strict_types=1);

use App\Domain\Run\FieldProfile;

/** Seven bots at 68, 70, 72, 75, 77, 79 and 81 seconds, and what the places of the design pay. */
function aField(): FieldProfile
{
    return new FieldProfile(
        [68_000, 70_000, 72_000, 75_000, 77_000, 79_000, 81_000],
        ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven'],
        [1_500_000, 1_250_000, 1_100_000, 1_000_000, 1_000_000, 800_000, 800_000, 800_000],
    );
}

describe('the place of a car among the bots', function () {
    it('is one more than the bots that are as quick as the car or quicker, so that a tie goes to the bots', function (int $lapMs, int $position) {
        expect(aField()->place($lapMs)['position'])->toBe($position);
    })->with([
        'quicker than all' => [60_000, 1],
        'just quicker than the quickest' => [67_999, 1],
        'as quick as the quickest' => [68_000, 2],
        'between the second and the third' => [71_000, 3],
        'as quick as the middle one' => [75_000, 5],
        'slower than all' => [90_000, 8],
    ]);

    it('pays what the place pays, and says how many cars are in the field', function () {
        expect(aField()->place(60_000))->toMatchArray(['size' => 8, 'reward_ppm' => 1_500_000])
            ->and(aField()->place(71_000)['reward_ppm'])->toBe(1_100_000)
            ->and(aField()->place(90_000)['reward_ppm'])->toBe(800_000);
    });

    it('lists the cars of the field from the quickest, the car among them with no name, and a bot ahead of a car that drives the same time', function () {
        $rows = aField()->place(75_000)['rows'];

        expect($rows)->toHaveCount(8)
            ->and(array_column($rows, 'lap_ms'))->toBe([68_000, 70_000, 72_000, 75_000, 75_000, 77_000, 79_000, 81_000])
            ->and(array_column($rows, 'type'))->toBe(['bot', 'bot', 'bot', 'bot', 'you', 'bot', 'bot', 'bot'])
            ->and($rows[4]['name'])->toBeNull()
            ->and($rows[3]['name'])->toBe('Four');
    });

    it('is refused when the names or the places do not fit the bots, or a time is not positive', function (array $rivals, array $names, array $pays) {
        new FieldProfile($rivals, $names, $pays);
    })->throws(InvalidArgumentException::class)->with([
        'no bots' => [[], [], [1_000_000]],
        'a name too few' => [[70_000, 72_000], ['One'], [1_000_000, 900_000, 800_000]],
        'a place too few' => [[70_000, 72_000], ['One', 'Two'], [1_000_000, 900_000]],
        'a time that is nothing' => [[0], ['One'], [1_000_000, 900_000]],
        'a place that pays nothing' => [[70_000], ['One'], [1_000_000, 0]],
    ]);
});
