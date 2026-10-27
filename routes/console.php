<?php

use App\Services\Comptes\SuppressionCompte;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel Cloud (27.10.2026) : chaque tâche sur un seul serveur, et le verrou
// d'une tâche tuée (déploiement) tombe avant son passage suivant, pas 24 h après

// Domaines personnalisés des sites : le DNS met parfois des heures à se propager
Schedule::command('sites:verifier-domaines')->everyTenMinutes()->withoutOverlapping(9)->onOneServer();

// Catalogue des médicaments vétérinaires : Swissmedic publie le premier
// jour ouvrable du mois (import le 3 à 4 h, avec marge), Refdata chaque
// nuit (import hebdomadaire, le lundi). Production seulement : en local,
// `catalogue:swissmedic` et `catalogue:refdata` à la main.
Schedule::command('catalogue:swissmedic')->monthlyOn(3, '04:00')->environments(['production'])->withoutOverlapping(180)->onOneServer();
Schedule::command('catalogue:refdata')->weeklyOn(1, '04:30')->environments(['production'])->withoutOverlapping(180)->onOneServer();

// Abonnements maison : renouvellements à la date anniversaire (avec les
// minutes de transcription de la période) et nouvelles tentatives après un
// refus ; l'audio de transcription resté en erreur effacé chaque nuit
// Tuée en pleine course (déploiement) : son verrou tombe au bout de 50 minutes,
// la suivante repart à l'heure pleine, pas 24 h plus tard ; deux passages qui
// se croisent sont sans danger, chaque abonné est relu sous son verrou (26.10.2026)
Schedule::command('abonnements:renouveler')->hourly()->withoutOverlapping(50)->onOneServer();
// Paiements restés sans réponse de Stripe : renvoyés tels quels (26.10.2026) ;
// tuée en pleine course, au plus 2 minutes sans vérification (27.10.2026)
Schedule::command('abonnements:verifier-paiements')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('inscriptions:reessayer')->hourly()->withoutOverlapping(50)->onOneServer();
Schedule::command('transcriptions:purger')->dailyAt('03:30')->timezone('Europe/Zurich')->withoutOverlapping(120)->onOneServer();
// Comptes dont la suppression a été demandée il y a 30 jours (27.10.2026)
Schedule::command('comptes:effacer')->dailyAt(SuppressionCompte::HEURE_EFFACEMENT)->timezone('Europe/Zurich')->withoutOverlapping(60)->onOneServer();
// Point du matin sur le stock (seuils, péremptions), avant l'ouverture des cliniques
Schedule::command('alertes:stock')->dailyAt('07:00')->timezone('Europe/Zurich')->withoutOverlapping(60)->onOneServer();

// Contrats terminés : accès suspendu et jetons révoqués ; jetons expirés supprimés
Schedule::command('equipe:fermer-contrats')->dailyAt('00:15')->timezone('Europe/Zurich')->withoutOverlapping(60)->onOneServer();
Schedule::command('sanctum:prune-expired --hours=48')->dailyAt('04:45')->withoutOverlapping(60)->onOneServer();
