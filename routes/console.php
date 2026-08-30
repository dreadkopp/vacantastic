<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for the per-round time limit: reveal any round whose clock ran out.
// (The client also triggers reveal at countdown 0 for snappier UX.)
Schedule::command('vacantastic:reveal-expired')->everyThirtySeconds();
