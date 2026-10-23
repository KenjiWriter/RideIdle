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
