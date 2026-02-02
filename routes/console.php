<?php

use App\Jobs\CheckTerminalCheckoutTimeout;
use App\Jobs\ProcessLayawayReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check for timed out terminal checkouts every minute
Schedule::job(new CheckTerminalCheckoutTimeout)->everyMinute();

// Process layaway payment reminders and overdue notices daily at 9am
Schedule::job(new ProcessLayawayReminders)->dailyAt('09:00');
