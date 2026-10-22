<?php

declare(strict_types=1);

/*
| Settlement, money, ratings and payouts use integers only. Floating point numbers round
| differently on different platforms and would make a result depend on the machine, so the
| files that decide results are scanned for anything that produces a float.
|
| EloTable is not scanned: it computes a table with floats, but only to create the data file.
*/

const INTEGER_ONLY_FILES = [
    'Ppm.php',
    'Settler.php',
    'Rating.php',
    'TournamentPayout.php',
    'PayoutResult.php',
    'RunState.php',
    'RunConfig.php',
    'SettlementEvent.php',
    'SettlementResult.php',
];

const FLOAT_FUNCTIONS = ['floor', 'ceil', 'round', 'sqrt', 'pow', 'fdiv', 'floatval', 'doubleval', 'log', 'exp', 'number_format'];

/** @return list<string> descriptions of every float-producing token outside comments */
function floatTokensIn(string $path): array
{
    $found = [];

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (! is_array($token)) {
            if ($token === '/') {
                $found[] = 'the / operator (use intdiv)';
            }

            continue;
        }

        [$id, $text, $line] = $token;

        if ($id === T_DNUMBER) {
            $found[] = "float literal {$text} on line {$line}";
        } elseif ($id === T_DOUBLE_CAST) {
            $found[] = "(float) cast on line {$line}";
        } elseif ($id === T_POW) {
            $found[] = "the ** operator on line {$line}";
        } elseif ($id === T_STRING && in_array(strtolower($text), FLOAT_FUNCTIONS, true)) {
            $found[] = "call of {$text} on line {$line}";
        }
    }

    return $found;
}

it('uses no floating point arithmetic in the files that decide results', function (string $file) {
    $path = dirname(__DIR__, 3).'/app/Domain/Settlement/'.$file;

    expect($path)->toBeFile()
        ->and(floatTokensIn($path))->toBe([]);
})->with(INTEGER_ONLY_FILES);

it('detects floating point arithmetic when it is there (the scan itself works)', function () {
    $probe = tempnam(sys_get_temp_dir(), 'probe');
    file_put_contents($probe, '<?php $a = 1 / 2; $b = 0.5; $c = (float) 3; $d = 2 ** 3; $e = round(1.5); // 1 / 2 in a comment is fine');

    // The division, 0.5, the cast, the power, the round call and its 1.5 literal; the comment is ignored.
    expect(floatTokensIn($probe))->toHaveCount(6);

    unlink($probe);
});
