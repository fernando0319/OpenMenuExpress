<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:prune-courier-locations')->daily();
Schedule::command('app:auto-settle-installments')->hourly()->withoutOverlapping();
Schedule::command('app:close-invoices')->dailyAt('03:30');
Schedule::command('app:close-network-periods')->hourlyAt(5)->withoutOverlapping();
Schedule::command('app:expire-courier-presence')->everyTenMinutes()->withoutOverlapping();

Schedule::command('app:sync-online-payments')->everyMinute()->withoutOverlapping();
