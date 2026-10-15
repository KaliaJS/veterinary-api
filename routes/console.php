<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Domaines personnalisés des sites : le DNS met parfois des heures à se propager
Schedule::command('sites:verifier-domaines')->everyTenMinutes()->withoutOverlapping();

// Catalogue des médicaments vétérinaires : Swissmedic publie le premier
// jour ouvrable du mois (import le 3 à 4 h, avec marge), Refdata chaque
// nuit (import hebdomadaire, le lundi). Production seulement : en local,
// `catalogue:swissmedic` et `catalogue:refdata` à la main.
Schedule::command('catalogue:swissmedic')->monthlyOn(3, '04:00')->environments(['production'])->withoutOverlapping();
Schedule::command('catalogue:refdata')->weeklyOn(1, '04:30')->environments(['production'])->withoutOverlapping();
