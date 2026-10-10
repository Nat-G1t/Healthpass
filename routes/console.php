<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// D-110: the 2nd-chance job — since D-132 it first removes the students still
// waiting in a clinic queue at the cutoff, so they are rescheduled too. Laravel's scheduler only runs if cron calls
// `php artisan schedule:run` every minute — on the hosted server AND on the
// Pi (docs/deployment-hosted.md, docs/deployment-pi.md); without that line
// nothing here ever fires.
//
// Every five minutes rather than once at 8 PM, so a server or Pi that was off
// at 8 PM still catches up (within the 24-hour window,
// `healthpass.second_chance.catch_up_hours`). Repeating it is safe: the unique
// `rescheduled_from_id` column and the "no 2nd chance yet" filter make a
// second run a no-op. withoutOverlapping() keeps a slow run from starting
// twice.
Schedule::command('healthpass:second-chances')->everyFiveMinutes()->withoutOverlapping();
