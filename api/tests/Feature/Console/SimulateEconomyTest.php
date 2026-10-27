<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * The files a test of this command wrote, which are removed when it is over.
 *
 * @return list<string>
 */
function economyTemporaryFiles(?string $add = null, bool $forget = false): array
{
    static $files = [];

    if ($add !== null) {
        $files[] = $add;
    }

    $remembered = $files;

    if ($forget) {
        $files = [];
    }

    return $remembered;
}

/** A file in the temporary folder, to be removed after the test. */
function economyTemporaryFile(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'economy');
    file_put_contents($file, $contents);
    economyTemporaryFiles($file);

    return $file;
}

/**
 * A ruleset on disk with some values changed, for the command to be given with --ruleset.
 *
 * @param  list<string|int>  $path
 */
function economyFile(array $path = [], mixed $value = null): string
{
    $data = economyData();

    if ($path !== []) {
        $node = &$data;

        foreach ($path as $key) {
            $node = &$node[$key];
        }

        $node = $value;
    }

    return economyTemporaryFile(json_encode($data, JSON_THROW_ON_ERROR));
}

afterEach(function () {
    foreach (economyTemporaryFiles(forget: true) as $file) {
        @unlink($file);
    }
});

describe('economy:simulate', function () {
    it('plays the ruleset of the repository and passes the gate', function () {
        $this->artisan('economy:simulate')
            ->expectsOutputToContain('Economy ruleset 2026.10.1')
            ->expectsOutputToContain('Pace gate: PASSED')
            ->assertExitCode(0);
    });

    it('tells the ladder, and the day each kind of player buys each car', function () {
        Artisan::call('economy:simulate');
        $output = Artisan::output();

        expect($output)->toContain('Player A, Regular')
            ->and($output)->toContain('Player B, Occasional')
            ->and($output)->toContain('Player C, Intensive')
            ->and($output)->toContain('$2 000')
            ->and($output)->toContain('15.50')
            // The regular player buys the last car on the 69th day, which is what the ladder says now (the design said 76: the first hours are quick).
            ->and($output)->toMatch('/\| L8\s+\| 76\s+\| 69\s+\|/');
    });

    it('plays only the kinds of players it is asked for', function () {
        Artisan::call('economy:simulate', ['--player' => ['B']]);
        $output = Artisan::output();

        expect($output)->toContain('Player B, Occasional')
            ->and($output)->not->toContain('Player A,')
            ->and($output)->not->toContain('Player C,');
    });

    it('refuses a kind of player that the ruleset does not have', function () {
        $this->artisan('economy:simulate', ['--player' => ['Z']])
            ->expectsOutputToContain('There is no kind of player Z')
            ->assertExitCode(2);
    });

    it('prints the report as JSON, for another program to read', function () {
        Artisan::call('economy:simulate', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect($report['ruleset'])->toBe('2026.10.1')
            ->and($report['gate']['passed'])->toBeTrue()
            ->and($report['gate']['rows'])->toHaveCount(24)
            ->and($report['gate']['rows'][0])->toMatchArray(['player' => 'A', 'car' => 'L1', 'target_day' => 2, 'day' => 2, 'waited_for' => 'cash', 'passed' => true])
            ->and(array_keys($report['players']))->toBe(['A', 'B', 'C'])
            ->and(collect($report['consistency'])->pluck('number'))->toContain('experience per lap', 'reward per lap', 'price of street-tires-t2');
    });

    it('fails the gate for a ruleset that spoils the pace, and says which days are out', function () {
        // Three times the experience: the levels, and the cars with them, come three times as fast.
        $this->artisan('economy:simulate', ['--ruleset' => economyFile(['model', 'xp', 'per_hour'], 270)])
            ->expectsOutputToContain('Pace gate: FAILED')
            ->expectsOutputToContain('OUT')
            ->assertExitCode(1);
    });

    it('fails the gate for a ruleset that gets a car to nobody in the days it plays', function () {
        $this->artisan('economy:simulate', ['--ruleset' => economyFile(['gate', 'max_days'], 20)])
            ->expectsOutputToContain('never')
            ->assertExitCode(1);
    });

    it('sets the rules of the game against the anchors, and says so when a number is off, without failing', function () {
        // A tolerance of one percent makes the experience of a lap (20, where the anchors give 18.75) a number that is off. The pace
        // does not change: it is the tolerance of the comparison that is changed, not a number of the model.
        $this->artisan('economy:simulate', ['--ruleset' => economyFile(['consistency', 'tolerance'], 0.01)])
            ->expectsOutputToContain('Pace gate: PASSED')
            ->expectsOutputToContain('numbers are off the anchors')
            ->assertExitCode(0);
    });

    it('fails on a number of the game that is off the anchors, when it is asked to be strict', function () {
        $this->artisan('economy:simulate', ['--ruleset' => economyFile(['consistency', 'tolerance'], 0.01), '--strict' => true])
            ->assertExitCode(1);
    });

    it('passes with --strict for the rules of the game as they are: every number agrees with the anchors', function () {
        $this->artisan('economy:simulate', ['--strict' => true])
            ->expectsOutputToContain('Pace gate: PASSED')
            ->expectsOutputToContain('every number is within the anchors')
            ->assertExitCode(0);
    });

    it('sets the numbers of the first track, the fuel and the parts of the pack against the anchors', function () {
        Artisan::call('economy:simulate', ['--json' => true]);
        $consistency = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['consistency'])->keyBy('number');

        // What the game has today and agrees with, which a change of the rules must not break without a word.
        expect($consistency['lap time of the first track'])->toMatchArray(['anchors' => 75_000.0, 'game' => 75_000.0, 'ok' => true])
            ->and($consistency['fuel per lap'])->toMatchArray(['anchors' => 1.5, 'game' => 1.5, 'ok' => true])
            ->and($consistency['reward per lap'])->toMatchArray(['anchors' => 15.5, 'game' => 15.5, 'ok' => true])
            ->and($consistency['price of a liter of fuel']['ok'])->toBeTrue()
            ->and($consistency['price of street-tires-t2'])->toMatchArray(['anchors' => 570.0, 'game' => 570.0, 'ok' => true])
            // The anchors give 900 XP an hour, which is 18.75 a lap, and the game gives 20, a whole number: a little more, and in order.
            ->and($consistency['experience per lap'])->toMatchArray(['anchors' => 18.75, 'game' => 20.0, 'ok' => true])
            ->and(collect($consistency)->every(static fn (array $number): bool => $number['ok']))->toBeTrue();
    });

    it('says plainly when the ruleset is not there', function () {
        $this->artisan('economy:simulate', ['--ruleset' => sys_get_temp_dir().'/there-is-no-such-ruleset.json'])
            ->expectsOutputToContain('There is no ruleset at')
            ->assertExitCode(2);
    });

    it('says plainly when the ruleset is not JSON', function () {
        $this->artisan('economy:simulate', ['--ruleset' => economyTemporaryFile('{not json')])
            ->expectsOutputToContain('is not JSON')
            ->assertExitCode(2);
    });

    it('refuses a ruleset that does not match its schema, and names what is wrong', function () {
        $this->artisan('economy:simulate', ['--ruleset' => economyFile(['gate', 'tolerance'], 7)])
            ->expectsOutputToContain('does not match its schema')
            ->assertExitCode(2);
    });

    it('refuses a ruleset whose targets do not fit its players', function () {
        $this->artisan('economy:simulate', ['--ruleset' => economyFile(['targets', 'A'], [4, 11])])
            ->expectsOutputToContain('needs a target day for each')
            ->assertExitCode(2);
    });

    it('plays the game scenario too: the fleet that the rules of the game allow, and says that it is out of the pace of the design', function () {
        Artisan::call('economy:simulate');
        $output = Artisan::output();

        expect($output)->toContain('The game scenario')
            ->and($output)->toContain('ends with 14 wrecks and 4 garages of other cities')
            ->and($output)->toContain('Fleet gate (game): PASSED')
            // Only the car that is played levels the driver, so the regular player buys the last car on the 69th day with the fleet, as with one car.
            ->and($output)->toMatch('/\| L8\s+\| 76\s+\| 69\s+\|/')
            ->and($output)->toContain('the cars the driver does not play give 0% of their experience');
    });

    it('sets the income that the game reaches at each level against the ladder, and finds every rung within the anchors', function () {
        Artisan::call('economy:simulate');
        $output = Artisan::output();

        expect($output)->toContain('The ladder of the game')
            // A lap pays by the tier of the car, so the best car of a level pays about what its rung asks: the Bizon V8 at the fifth, the Orzeł V12 at the last.
            ->and($output)->toMatch('/\| 12\s+\| L4\s+\| \$1 049\s+\| \$1 078\s+\|/')
            ->and($output)->toContain('bizon-v8 on autodrom-circuit')
            ->and($output)->toMatch('/\| 27\s+\| L8\s+\| \$1 835\s+\| \$1 834\s+\|/')
            ->and($output)->toContain('orzel-v12 on autodrom-circuit')
            ->and($output)->toContain('Ladder of the game: every level is within the anchors.')
            ->and($output)->not->toContain('reach L')
            // The experience an hour of the best pair against the 900 of the model: a lap of a fast car pays the share of the base lap it takes, so the
            // Bizon V8 gets 918 and the Orzeł V12 895 instead of the 1 000 and more that a flat 20 XP a lap would give them.
            ->and($output)->toMatch('/\| 12\s+\| L4\s+\| \$1 049\s+\| \$1 078\s+\| \+3%\s+\| 918\s+\| \+2%\s+\|/')
            ->and($output)->toMatch('/\| 27\s+\| L8\s+\| \$1 835\s+\| \$1 834\s+\| -0%\s+\| 895\s+\| -1%\s+\|/')
            ->and($output)->toContain('Experience an hour of the game: every level is within the model.');
    });

    it('names the levels where the game pays more or less experience an hour than the model counts, and fails with --strict-game for them', function () {
        $data = economyData();
        // The model counts 700 an hour while the game pays 805 to 960 at the first levels: the first ones are off by a third.
        $data['model']['xp']['per_hour'] = 700;
        $file = economyTemporaryFile(json_encode($data, JSON_THROW_ON_ERROR));

        Artisan::call('economy:simulate', ['--ruleset' => $file]);
        $output = Artisan::output();

        expect($output)->toMatch('/Experience an hour of the game: \d+ of 12 levels are off the model/')
            ->and($output)->toMatch('/\| 1\s+\| L0\s+\| \$600\s+\| \$600\s+\| \+0%\s+\| 960\s+\| \+37% OFF\s+\|/');

        $this->artisan('economy:simulate', ['--ruleset' => $file, '--strict-game' => true])->assertExitCode(1);
    });

    it('does not fail for the game scenario when it is not asked to, and fails when it is: with --strict-game', function () {
        // The shipped rules pass the gates of the game, the ladder among them.
        $this->artisan('economy:simulate', ['--strict' => true])->assertExitCode(0);
        $this->artisan('economy:simulate', ['--strict-game' => true])->assertExitCode(0);

        // The cars that the driver does not play give all of their experience: the fleet runs away from the pace of the design.
        $data = economyData();
        $data['model']['fleet']['other_cars_xp_share'] = 1.0;
        $file = economyTemporaryFile(json_encode($data, JSON_THROW_ON_ERROR));

        $this->artisan('economy:simulate', ['--ruleset' => $file])->assertExitCode(0);
        $this->artisan('economy:simulate', ['--ruleset' => $file, '--strict-game' => true])->assertExitCode(1);
    });

    it('passes with --strict-game for a ruleset whose fleet is no bigger than the design thinks and whose ladder the game reaches', function () {
        $data = economyData();
        // No place for a wreck and no garage to buy.
        $data['model']['fleet']['home_free_places'] = 0;
        $data['model']['fleet']['garage_city_levels'] = [];
        // And crates and units at a tenth of the income only: without the fleet a third of it moves the days of the occasional player by more than a quarter.
        $data['model']['crates']['spend_sweep'] = [0.1];
        $data['model']['units']['spend_sweep'] = [0.1];

        $this->artisan('economy:simulate', ['--ruleset' => economyTemporaryFile(json_encode($data, JSON_THROW_ON_ERROR)), '--strict-game' => true])
            ->expectsOutputToContain('Fleet gate (game): PASSED')
            ->expectsOutputToContain('Ladder of the game: every level is within the anchors')
            ->expectsOutputToContain('Crate value gate: PASSED')
            ->assertExitCode(0);
    });

    it('brings the experience of the other cars down to nothing with the knob of the fleet, and the days fall back to the ones of one car', function () {
        $data = economyData();
        $data['model']['fleet']['other_cars_xp_share'] = 0;

        Artisan::call('economy:simulate', ['--ruleset' => economyTemporaryFile(json_encode($data, JSON_THROW_ON_ERROR)), '--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $days = collect($report['game_fleet']['gate']['rows'])->where('player', 'A')->pluck('day')->all();

        expect($days)->toBe([3, 8, 9, 18, 28, 36, 48, 69]);
    });

    it('prints the game scenario in the JSON report, for the kinds of players that were asked for', function () {
        Artisan::call('economy:simulate', ['--json' => true, '--player' => ['B']]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect(array_keys($report['game_fleet']['players']))->toBe(['B'])
            ->and($report['game_fleet']['players']['B'])->toMatchArray(['wrecks' => 14, 'extra_garages' => 4])
            ->and($report['game_fleet']['gate']['passed'])->toBeTrue()
            ->and(collect($report['game_fleet']['gate']['rows'])->pluck('player')->unique()->all())->toBe(['B'])
            ->and($report['game_ladder']['unreachable_rungs'])->toBe([])
            ->and($report['game_ladder']['rows'])->toHaveCount(12)
            ->and($report['game_ladder']['rows'][0])->toMatchArray(['level' => 1, 'rung' => 'L0', 'car' => 'rdzak-gt-4', 'track' => 'zlomowo-street', 'ok' => true, 'xp_ok' => true])
            ->and($report['game_ladder']['rows'][0]['xp_game'])->toEqualWithDelta(960.0, 0.5)
            ->and($report['game_ladder']['rows'][0]['xp_model'])->toEqualWithDelta(900.0, 0.5)
            ->and($report['game_ladder']['rows'][11]['xp_game'])->toEqualWithDelta(895.0, 3.0);
    });

    it('plays the crates of parts at the shares of the ruleset, and says that the shift of the days is within the tolerance', function () {
        Artisan::call('economy:simulate', ['--player' => ['A']]);
        $output = Artisan::output();

        expect($output)->toContain('Crates of parts, on top of the game scenario')
            ->and($output)->toMatch('/\| none\s+\| 3\s+\| 8\s+\| 9\s+\| 18\s+\| 28\s+\| 36\s+\| 48\s+\| 69\s+\|/')
            ->and($output)->toMatch('/\| 20%\s+\| 3\s+\| 8\s+\| 9\s+\| 18\s+\| 28\s+\| 36\s+\| 48\s+\| 69\s+\|\s+48\s+\|/')
            ->and($output)->toContain('Crate shift gate: PASSED');
    });

    it('says what crates pay back against the next car, the first ones and the whole programme, and that the gate of the value is the programme', function () {
        Artisan::call('economy:simulate');
        $output = Artisan::output();

        expect($output)->toContain('What crates pay back')
            ->and($output)->toMatch('/\| L0\s+\| 1\s+\| \$563\s+\| 5\.3\s+\|/')
            ->and($output)->toMatch('/\| L7\s+\| 22\s+\| \$14 925\s+\| 5\.7\s+\| 90\.1\s+\| 224\.9\s+\| 150\.2\s+\| 145\.6\s+\| ok\s+\|/')
            ->and($output)->toContain('Crate value gate: PASSED. The programme of 150 crates never pays back sooner than a car (the first crates do, at L1, L2, L3, L4, L5, L6, L7).');
    });

    it('plays the storage units, and says what each kind returns on average and that none returns more than it costs', function () {
        Artisan::call('economy:simulate');
        $output = Artisan::output();

        expect($output)->toContain('Storage units, on top of the game scenario')
            ->and($output)->toMatch('/\| small\s+\| \$1 200\s+\| 3\s+\| 91%/')
            ->and($output)->toMatch('/\| medium\s+\| \$3 500\s+\| 6\s+\| 76%/')
            ->and($output)->toContain('Unit return gate: PASSED')
            ->and($output)->toContain('Unit shift gate: PASSED');
    });

    it('puts the sale on the paid price next to the days of the design, as a report', function () {
        Artisan::call('economy:simulate', ['--player' => ['A']]);
        $output = Artisan::output();

        expect($output)->toContain('The sale of a car for a share of what was paid')
            ->and($output)->toContain('8 8 9 19 29 37 49 70');
    });

    it('prints the extras in the JSON report, for the kinds of players that were asked for', function () {
        Artisan::call('economy:simulate', ['--json' => true, '--player' => ['B']]);
        $extras = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['extras'];

        expect($extras['passed'])->toBeTrue()
            ->and($extras['crates']['shift_passed'])->toBeTrue()
            ->and($extras['crates']['value_passed'])->toBeTrue()
            ->and(array_column($extras['crates']['shares'], 'share'))->toBe([0.1, 0.2, 0.3])
            ->and(array_keys($extras['crates']['shares'][1]['players']))->toBe(['B'])
            ->and($extras['crates']['shares'][1]['players']['B'])->toHaveKeys(['crates', 'spent', 'uplift', 'first_rare_day'])
            ->and($extras['crates']['value']['player'])->toBe('B')
            ->and($extras['units']['returns_passed'])->toBeTrue()
            ->and(array_column($extras['units']['returns'], 'kind'))->toBe(['small', 'medium', 'large'])
            ->and($extras['sales']['B']['days'])->toHaveCount(8);
    });
});
