<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Domaines personnalisés des sites : le DNS met parfois des heures à se propager
Schedule::command('sites:verifier-domaines')->everyTenMinutes()->withoutOverlapping();
