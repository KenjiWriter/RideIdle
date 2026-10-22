<?php

declare(strict_types=1);

it('confirms that the committed Elo table is up to date', function () {
    $this->artisan('content:elo-table', ['--check' => true])
        ->expectsOutputToContain('The Elo table is up to date.')
        ->assertSuccessful();
});

it('fails the check when the table is missing', function () {
    config(['ride.content_path' => sys_get_temp_dir().'/ride-no-content-'.bin2hex(random_bytes(4))]);

    $this->artisan('content:elo-table', ['--check' => true])->assertFailed();
});
