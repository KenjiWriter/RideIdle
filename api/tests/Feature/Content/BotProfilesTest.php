<?php

declare(strict_types=1);

use App\Content\BotProfiles;
use App\Content\BotProfileTable;
use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;

/** @return array<string, mixed> the committed table of the bot profiles */
function committedBotProfiles(): array
{
    return json_decode((string) file_get_contents(config('ride.content_path').'/tables/bot-profiles.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> what the run of a car on a track is frozen into, with the bots of the game */
function frozenWithBots(string $car, string $track, array $mounted = []): array
{
    return (new RunFreezer)->freeze(app(RunInputsFactory::class)->make($car, $track, $mounted), 'seed', 0)->config;
}

describe('the table of the bot profiles', function () {
    it('is what the generator computes now, from the real pipeline', function () {
        // A change of the rules, the pack or the pipeline moves a lap time, and the table has to be written down again: php artisan economy:bot-profiles.
        expect(app(BotProfileTable::class)->compute())->toEqual(committedBotProfiles());
    });

    it('has a reference for every tier of the combustion cars of the pack on every track, so that no car of the pack races alone', function () {
        $table = committedBotProfiles()['tracks'];
        $tiers = collect(app(Catalog::class)->vehicles())->where('powertrain', 'ice')->pluck('tier')->unique()->sort()->values()->all();

        expect(array_keys($table))->toBe(array_keys(app(Rules::class)->tracks()));

        foreach ($table as $track => $byTier) {
            expect(array_keys($byTier))->toBe($tiers, $track);
        }
    });

    it('takes the quickest stock car of a tier as its reference: nothing of the tier is quicker, and the base lap of the first track is the Rdzak GT-4 on the stock tires', function () {
        $table = committedBotProfiles()['tracks'];

        expect($table['zlomowo-street'][2])->toBe(75_000)
            // Gravel is made around the tires it asks for: the reference car has them, so the stock cars with the street tires are far behind it.
            ->and($table['kruszyn-quarry'][2])->toBeLessThan(100_000);

        foreach (app(Catalog::class)->vehicles() as $slug => $vehicle) {
            if ($vehicle['powertrain'] !== 'ice') {
                continue;
            }

            $lap = frozenWithBots($slug, 'autodrom-circuit')['lap_ms']['dry'];

            expect($lap)->toBeGreaterThanOrEqual($table['autodrom-circuit'][$vehicle['tier']], $slug);
        }
    });

    it('is read by tier and track, and knows nothing of a tier or a track that is not in it', function () {
        $profiles = app(BotProfiles::class);

        expect($profiles->referenceMs('zlomowo-street', 2))->toBe(75_000)
            ->and($profiles->referenceMs('zlomowo-street', 9))->toBeNull()
            ->and($profiles->referenceMs('nowhere', 2))->toBeNull()
            ->and((new BotProfiles('/no/such/table.json'))->referenceMs('zlomowo-street', 2))->toBeNull();
    });

    it('is checked by the command, which fails when the table is not the one that is computed', function () {
        $this->artisan('economy:bot-profiles', ['--check' => true])->assertSuccessful();
    });
});

describe('the field of the bots of a car', function () {
    it('puts the quickest stock car of every tier in the middle of the field, on every track that asks nothing special of the tires', function () {
        $table = committedBotProfiles()['tracks'];
        $catalog = app(Catalog::class);
        $rules = app(Rules::class);

        foreach ($table as $track => $byTier) {
            if ($rules->trackReferenceTires($track) !== null) {
                continue;
            }

            foreach ($byTier as $tier => $reference) {
                $fastest = collect($catalog->vehicles())->where('powertrain', 'ice')->where('tier', $tier)->keys()->sortBy(fn (string $slug): int => frozenWithBots($slug, $track)['lap_ms']['dry'])->first();
                $field = frozenWithBots($fastest, $track)['field'];

                // As quick as the bot that drives the reference, so behind it: the fifth place, which pays the reward of the track as it is.
                expect($field['position'])->toBe(5, "{$fastest} on {$track}")
                    ->and($field['reward_ppm'])->toBe(1_000_000);
            }
        }
    });

    it('has seven bots around the reference of the tier, from nine per cent quicker to nine per cent slower, named after teams', function () {
        $field = frozenWithBots('rdzak-gt-4', 'zlomowo-street')['field'];
        $bots = array_values(array_filter($field['rows'], fn (array $row): bool => $row['type'] === 'bot'));

        // The reference of the tier 2 on the first track is the lap of the stock Rdzak GT-4, 75 s.
        expect(array_column($bots, 'lap_ms'))->toBe([68_250, 70_500, 72_750, 75_000, 77_250, 79_500, 81_750])
            ->and(count(array_unique(array_column($bots, 'name'))))->toBe(7)
            ->and($field['size'])->toBe(8);

        foreach ($bots as $bot) {
            expect(app(Rules::class)->botNames())->toContain($bot['name']);
        }
    });

    it('has the same names on a track for every driver, and not the same on every track', function () {
        $names = fn (string $track): array => array_column(array_filter(frozenWithBots('rdzak-gt-4', $track)['field']['rows'], fn (array $row): bool => $row['type'] === 'bot'), 'name');

        expect($names('zlomowo-street'))->toBe($names('zlomowo-street'))
            ->and($names('zlomowo-street'))->not->toBe($names('autodrom-circuit'));
    });

    it('pays a car that is quicker than the field more, and a car that is slower less, by the place', function () {
        $stock = frozenWithBots('rdzak-gt-4', 'zlomowo-street');
        $tuned = frozenWithBots('rdzak-gt-4', 'zlomowo-street', ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2']);
        $slow = frozenWithBots('kuna-4x4', 'zlomowo-street');

        // The stock car drives the reference lap and is in the middle; the shop parts take it to 68.3 s, past the quickest bot but one that is as quick as them
        // (68.25 s), so the second place; the Kuna drives 91.8 s and is the last.
        expect($stock['field']['position'])->toBe(5)
            ->and($stock['lap_cash_cents']['dry'])->toBe(1_550)
            ->and($tuned['field']['position'])->toBe(2)
            ->and($tuned['lap_cash_cents']['dry'])->toBe(1_938)
            ->and($slow['field']['position'])->toBe(8)
            ->and($slow['lap_cash_cents']['dry'])->toBe(1_240)
            // The weather takes its share of what the place pays.
            ->and($tuned['lap_cash_cents']['rain'])->toBe(1_802);
    });

    it('has no field for a car that the table has no reference for, which then races alone as before', function () {
        $alone = new RunInputsFactory(app(Catalog::class), app(Rules::class));

        expect((new RunFreezer)->freeze($alone->make('rdzak-gt-4', 'zlomowo-street'), 'seed', 0)->config)->not->toHaveKey('field')
            ->and((new RunFreezer)->freeze($alone->make('rdzak-gt-4', 'zlomowo-street'), 'seed', 0)->config['lap_cash_cents']['dry'])->toBe(1_550);
    });
});
