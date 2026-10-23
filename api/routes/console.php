<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Guests nobody returns to expire after ride.guest_expiry_days days.
Schedule::command('accounts:prune-guests')->dailyAt('03:30')->withoutOverlapping();

// The one-time secrets of the account flows live for minutes; the spent ones are only kept for a day.
Schedule::command('accounts:prune-tokens')->hourly()->withoutOverlapping();

// A mail that could not be sent is kept in failed_jobs, with the address it was for, so it can be tried again. A week is
// enough to notice and retry it; after that the address does not need to stay in the database.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:45')->withoutOverlapping();
