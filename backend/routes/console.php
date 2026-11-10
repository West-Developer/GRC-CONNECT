<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('enrollments:auto-void')->hourly();
Schedule::command('students:deactivate-overdue')->hourly();
Schedule::command('academic:promote-year-levels')->daily();

