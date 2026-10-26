<?php

declare(strict_types=1);

use App\Content\GameUnits;

describe('economy:unit-returns', function () {
    it('finds the committed table up to date', function () {
        $this->artisan('economy:unit-returns', ['--check' => true])
            ->expectsOutputToContain('is up to date')
            ->assertExitCode(0);
    });

    it('fails the check for a table that is missing or stale, and writes the table that makes it pass', function () {
        // A few units of each kind are enough to say whether a file is the table that the generator makes.
        $few = ['--trials' => 200];
        // The rules and the pack are read from the real folder before the path of the tables is moved to a folder that has none of them.
        $units = app(GameUnits::class);
        $directory = sys_get_temp_dir().'/unit-returns-'.bin2hex(random_bytes(4));
        mkdir($directory);
        config(['ride.content_path' => $directory]);
        $path = $units->path();

        try {
            $this->artisan('economy:unit-returns', ['--check' => true, ...$few])->expectsOutputToContain('missing or stale')->assertExitCode(1);

            mkdir($directory.'/tables');
            file_put_contents($path, '{}');
            $this->artisan('economy:unit-returns', ['--check' => true, ...$few])->expectsOutputToContain('missing or stale')->assertExitCode(1);

            $this->artisan('economy:unit-returns', $few)->expectsOutputToContain('Wrote')->assertExitCode(0);
            $this->artisan('economy:unit-returns', ['--check' => true, ...$few])->expectsOutputToContain('is up to date')->assertExitCode(0);
            // A table of another number of trials is not the one that the committed number says.
            $this->artisan('economy:unit-returns', ['--check' => true, '--trials' => 300])->expectsOutputToContain('missing or stale')->assertExitCode(1);

            $table = json_decode((string) file_get_contents($path), true);

            expect($table['units'])->toHaveKeys(['small', 'medium', 'large'])->and($table['trials'])->toBe(200);
        } finally {
            @unlink($path);
            @rmdir($directory.'/tables');
            @rmdir($directory);
        }
    });
});
