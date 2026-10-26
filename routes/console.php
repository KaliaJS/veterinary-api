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

// Abonnements maison : renouvellements à la date anniversaire (avec les
// minutes de transcription de la période) et nouvelles tentatives après un
// refus ; l'audio de transcription resté en erreur effacé chaque nuit
Schedule::command('abonnements:renouveler')->hourly()->withoutOverlapping();
// Paiements restés sans réponse de Stripe : renvoyés tels quels (26.10.2026)
Schedule::command('abonnements:verifier-paiements')->everyMinute()->withoutOverlapping(10);
Schedule::command('inscriptions:reessayer')->hourly()->withoutOverlapping();
Schedule::command('transcriptions:purger')->dailyAt('03:30')->timezone('Europe/Zurich')->withoutOverlapping();
// Point du matin sur le stock (seuils, péremptions), avant l'ouverture des cliniques
Schedule::command('alertes:stock')->dailyAt('07:00')->timezone('Europe/Zurich')->withoutOverlapping();

// Contrats terminés : accès suspendu et jetons révoqués ; jetons expirés supprimés
Schedule::command('equipe:fermer-contrats')->dailyAt('00:15')->timezone('Europe/Zurich')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=48')->dailyAt('04:45')->withoutOverlapping();
