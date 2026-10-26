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
});
