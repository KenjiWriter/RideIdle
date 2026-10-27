<?php

declare(strict_types=1);

use App\Content\GameCrates;
use App\Content\RarityPace;

/** @return array<string, mixed> the committed table of the pace of the rarities */
function committedRarityPace(): array
{
    return json_decode((string) file_get_contents(config('ride.content_path').'/tables/rarity-pace.json'), true, 512, JSON_THROW_ON_ERROR);
}

describe('the table of the pace of the rarities', function () {
    it('is what the generator computes now, from the real pipeline and the draw of the game', function () {
        $committed = committedRarityPace();

        // Every draw is a hash of a string, so the table is the same on every machine, and a change of the rules or of the pipeline has to be written down again.
        expect(app(RarityPace::class)->compute($committed['trials']))->toEqual($committed);
    });

    it('says what it was made of: the car, the track, the parts, the cycle of the slots, the seeds and the number of players drawn', function () {
        $table = committedRarityPace();

        expect($table)->toMatchArray(['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street', 'weather' => 'dry', 'group' => 'standard', 'seed_prefix' => 'rarity-pace', 'trials' => 1000])
            ->and($table['cycle'])->toBe(['tires', 'tires', 'suspension', 'brakes'])
            ->and($table['parts'])->toBe(['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2'])
            // The lap of the stock car with the common parts of the shop, which the gain is against, and the place it holds among the bots with it.
            ->and($table['base_lap_ms'])->toBe(68_294)
            ->and($table['base_position'])->toBe(2);
    });

    it('has the gain after every number of crates from none to three hundred, starts at none, never falls, and levels off', function () {
        $gain = committedRarityPace()['gain_by_crates'];

        expect($gain)->toHaveCount(301)
            ->and($gain[0])->toEqual(0)
            ->and($gain[300] - $gain[250])->toBeLessThan(0.003);

        foreach (range(1, 300) as $crates) {
            expect($gain[$crates])->toBeGreaterThanOrEqual($gain[$crates - 1]);
        }
    });

    it('agrees with the first experiment: a tenth of a lap more after ten crates and a third after a hundred and fifty', function (int $crates, float $percent) {
        // 14.6, 19.2, 23.3 and 32.7% of laps an hour from 400 drawn players with other seeds: within a point.
        expect(committedRarityPace()['lap_gain_by_crates'][$crates] * 100)->toEqualWithDelta($percent, 1.0);
    })->with([
        'ten crates' => [10, 14.6],
        'twenty crates' => [20, 19.2],
        'forty crates' => [40, 23.3],
        'a hundred and fifty crates' => [150, 32.7],
    ]);

    it('is worth more in income than in laps, because a shorter lap lifts the car in the field of the bots, and levels off at a bit more than the laps do', function (int $crates, float $percent) {
        $table = committedRarityPace();

        // The same players and draws as the laps: +10.5% of income for one crate (the car passes the quickest bot at once), and +59% after a hundred and fifty
        // where the laps alone are +33%.
        expect($table['gain_by_crates'][$crates] * 100)->toEqualWithDelta($percent, 0.2)
            ->and($table['gain_by_crates'][$crates])->toBeGreaterThan($table['lap_gain_by_crates'][$crates]);
    })->with([
        'one crate' => [1, 10.5],
        'ten crates' => [10, 37.7],
        'forty crates' => [40, 48.3],
        'a hundred and fifty crates' => [150, 59.3],
    ]);

    it('says what a rarity of one slot is worth: the tires nearly all of it, and the exhaust nothing', function () {
        $gain = committedRarityPace()['rarity_gain'];

        // Uncommon, rare, epic and legendary parts. The tires give grip, handling and braking at once, the exhaust only a gain of 2% of the power.
        expect($gain['tires'][2])->toEqualWithDelta(0.204, 0.001)
            ->and($gain['all_three'][3])->toEqualWithDelta(0.335, 0.001)
            ->and($gain['tires'][3])->toBeLessThan($gain['all_three'][3])
            ->and($gain['suspension'][3])->toBeLessThan($gain['tires'][3])
            ->and($gain['exhaust'][3])->toBeLessThan(0.004);
    });
});

describe('the crates of the game for the economy simulator', function () {
    it('are made of the rules of the shop and the table: the tiers, the price, the odds, the guarantees and the mix of the slots', function () {
        $economy = app(GameCrates::class)->economy();

        expect($economy->firstTierLevel)->toBe(1)
            ->and($economy->levelsPerTier)->toBe(3)
            ->and($economy->priceFactorPpm)->toBe(1_000_000)
            ->and($economy->slotMix)->toBe(['tires' => 2, 'suspension' => 1, 'brakes' => 1])
            ->and($economy->odds)->toBe(['common' => 620, 'uncommon' => 250, 'rare' => 100, 'epic' => 27, 'legendary' => 3])
            ->and([$economy->floorEvery, $economy->floorRarity, $economy->legendaryEvery])->toBe([10, 'rare', 150])
            ->and($economy->gain)->toHaveCount(301)
            ->and($economy->gainAfter(10))->toBe(committedRarityPace()['gain_by_crates'][10]);
    });

    it('say which command makes the table when it is not there', function () {
        // The rules are read before the folder of the content is changed: it is the table that is missing there.
        $crates = app(GameCrates::class);
        config(['ride.content_path' => sys_get_temp_dir().'/ride-no-content-'.bin2hex(random_bytes(4))]);

        expect(fn () => $crates->table())->toThrow(RuntimeException::class, 'economy:rarity-pace');
    });
});
