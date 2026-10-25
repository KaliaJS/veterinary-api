<?php

/*
 * Essai réel des verrous sur MySQL (26.10.2026). La base des tests (SQLite)
 * ignore `lockForUpdate` : ce script prouve, sur une vraie base MySQL jetable,
 * qu'une seconde requête arrivée pendant qu'une première tient le verrou
 * attend, puis voit ce que la première a écrit et est refusée.
 *
 * Trois cas : deux admissions du même patient, un transfert vers un box pris,
 * deux morceaux d'audio qui dépasseraient ensemble 300 Mo.
 *
 * Pour chaque cas, un processus « tenir » prend le verrou que prend l'app,
 * écrit la ligne concurrente, attend quelques secondes, puis valide. Un
 * processus « tenter », lancé une seconde après, joue le vrai geste par toute
 * la pile HTTP de l'app (jeton, droits, contrôleur). On mesure sa durée et sa
 * réponse.
 *
 *     php scripts/essai-verrous-mysql.php
 *
 * La base `veterinary_essai_verrous` est créée puis supprimée ; la base de
 * travail n'est pas touchée.
 */

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Enums\StatutEnregistrement;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\EnregistrementConsultation;
use App\Models\LignePlanning;
use App\Models\PartieEnregistrement;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Sejour;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as KernelConsole;
use Illuminate\Contracts\Http\Kernel as KernelHttp;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const BASE = 'veterinary_essai_verrous';
const ATTENTE = 4;

$racine = dirname(__DIR__);
require $racine.'/vendor/autoload.php';

$role = $argv[1] ?? 'chef';
$cas = $argv[2] ?? null;
$donnees = isset($argv[3]) ? json_decode(base64_decode($argv[3]), true) : [];

// La base jetable pour tous les processus : prise avant la lecture du .env
putenv('DB_DATABASE='.BASE);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = BASE;

$app = require $racine.'/bootstrap/app.php';

/** Horodatage relatif au départ commun, en secondes. */
function t(float $depart): string
{
    return number_format(microtime(true) - $depart, 2);
}

if ($role === 'tenir') {
    $app->make(KernelConsole::class)->bootstrap();
    $depart = (float) $donnees['depart'];
    $verrou = true;
    DB::transaction(function () use ($cas, $donnees, $depart, $verrou): void {
        match ($cas) {
            // Comme l'admission : verrou sur le patient, puis le séjour
            'admission' => (function () use ($donnees, $verrou): void {
                $verrou && Patient::withoutGlobalScopes()->whereKey($donnees['patient'])->lockForUpdate()->first();
                Sejour::withoutGlobalScopes()->create(['clinique_id' => $donnees['clinique'], 'patient_id' => $donnees['patient'], 'box' => 2, 'motif' => 'Tenu par l’essai', 'admis_le' => now(), 'sortie_prevue' => 'À définir']);
            })(),
            // Comme une admission dans le box 6 : verrou sur la clinique
            'box' => (function () use ($donnees, $verrou): void {
                $verrou && Clinique::withoutGlobalScopes()->whereKey($donnees['clinique'])->lockForUpdate()->first();
                Sejour::withoutGlobalScopes()->create(['clinique_id' => $donnees['clinique'], 'patient_id' => $donnees['autrePatient'], 'box' => 6, 'motif' => 'Tenu par l’essai', 'admis_le' => now(), 'sortie_prevue' => 'À définir']);
            })(),
            // Comme la réception d'un morceau : verrou sur l'enregistrement, 250 morceaux annoncés
            'audio' => (function () use ($donnees, $verrou): void {
                $verrou && EnregistrementConsultation::withoutGlobalScopes()->whereKey($donnees['enregistrement'])->lockForUpdate()->first();
                PartieEnregistrement::query()->create(['enregistrement_consultation_id' => $donnees['enregistrement'], 'numero' => 1, 'format' => 'audio/mp4', 'morceaux' => 250]);
            })(),
        };
        fwrite(STDOUT, 'verrou pris et ligne écrite à '.t($depart)." s\n");
        sleep(ATTENTE);
        fwrite(STDOUT, 'validation à '.t($depart)." s\n");
    });
    exit(0);
}

// Témoin : le même contrôle que l'app, sans son verrou. Il ne voit pas la
// ligne pas encore validée du premier, et accepte : c'est ce que le verrou
// empêche
if ($role === 'temoin') {
    $app->make(KernelConsole::class)->bootstrap();
    $depart = (float) $donnees['depart'];
    fwrite(STDOUT, 'contrôle sans verrou à '.t($depart)." s\n");
    $accepte = DB::transaction(fn (): bool => match ($cas) {
        'admission' => ! Sejour::withoutGlobalScopes()->enCours()->where('patient_id', $donnees['patient'])->exists()
            && (bool) Sejour::withoutGlobalScopes()->create(['clinique_id' => $donnees['clinique'], 'patient_id' => $donnees['patient'], 'box' => 3, 'motif' => 'Seconde admission', 'admis_le' => now(), 'sortie_prevue' => 'À définir']),
        'box' => ! Sejour::withoutGlobalScopes()->enCours()->where('clinique_id', $donnees['clinique'])->where('box', 6)->whereKeyNot($donnees['sejour'])->exists()
            && Sejour::withoutGlobalScopes()->whereKey($donnees['sejour'])->update(['box' => 6]) === 1,
        'audio' => (int) PartieEnregistrement::query()->where('enregistrement_consultation_id', $donnees['enregistrement'])->where('numero', '!=', 2)->sum('morceaux') + 60 <= 300
            && (bool) PartieEnregistrement::query()->create(['enregistrement_consultation_id' => $donnees['enregistrement'], 'numero' => 2, 'format' => 'audio/mp4', 'morceaux' => 60]),
    });
    fwrite(STDOUT, ($accepte ? 'accepté (rien vu)' : 'refusé').' à '.t($depart)." s\n");
    exit(0);
}

if ($role === 'tenter') {
    $depart = (float) $donnees['depart'];
    $debut = microtime(true);
    $entetes = ['HTTP_AUTHORIZATION' => 'Bearer '.$donnees['jeton'], 'HTTP_ACCEPT' => 'application/json'];
    $requete = match ($cas) {
        'admission' => Request::create('/api/v1/hospitalisation/sejours', 'POST', ['patient_id' => $donnees['patient'], 'box' => 3, 'motif' => 'Seconde admission'], [], [], $entetes),
        'box' => Request::create('/api/v1/hospitalisation/sejours/'.$donnees['sejour'], 'PATCH', ['box' => 6], [], [], $entetes),
        'audio' => Request::create('/api/v1/enregistrements/'.$donnees['enregistrement'].'/parties/2', 'POST', ['morceau' => 0, 'morceaux' => 60, 'format' => 'audio/mp4'], [], [
            'fichier' => UploadedFile::fake()->createWithContent('morceau', "\x00\x00\x00\x18ftypM4A AUDIO"),
        ], $entetes),
    };
    fwrite(STDOUT, 'requête envoyée à '.t($depart)." s\n");
    $reponse = $app->make(KernelHttp::class)->handle($requete);
    $corps = json_decode((string) $reponse->getContent(), true);
    fwrite(STDOUT, 'réponse '.$reponse->getStatusCode().' à '.t($depart).' s, après '.number_format(microtime(true) - $debut, 2).' s : '.json_encode($corps['errors'] ?? $corps['message'] ?? $corps, JSON_UNESCAPED_UNICODE)."\n");
    exit(0);
}

// Chef d'orchestre : base jetable, données, trois essais, nettoyage
$env = [];
foreach (file($racine.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
    if (preg_match('/^(DB_HOST|DB_PORT|DB_USERNAME|DB_PASSWORD)=(.*)$/', $ligne, $m)) {
        $env[$m[1]] = trim($m[2], '"\'');
    }
}
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306'), $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '');
$pdo->exec('DROP DATABASE IF EXISTS `'.BASE.'`');
$pdo->exec('CREATE DATABASE `'.BASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $app->make(KernelConsole::class)->bootstrap();
    // Jamais la base de travail : une configuration en cache ignorerait DB_DATABASE
    if (DB::connection()->getDatabaseName() !== BASE) {
        throw new RuntimeException('La base visée n’est pas '.BASE.' (configuration en cache ?) : essai arrêté.');
    }
    Artisan::call('migrate', ['--force' => true]);

    // Une clinique neuve par essai : aucun essai ne voit les lignes d'un autre
    $preparer = function (): array {
        app(ContexteClinique::class)->oublier();
        $clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $proprietaires = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        $patient = Patient::factory()->for($proprietaires)->create();
        $autrePatient = Patient::factory()->for($proprietaires)->create();
        $troisieme = Patient::factory()->for($proprietaires)->create();
        app(ContexteClinique::class)->oublier();
        $user = User::factory()->create();
        Employe::factory()->dans($clinique)->administrateur()->create(['user_id' => $user->id]);
        $jeton = app(EmetteurJetons::class)->emettre($user)['jeton']->plainTextToken;
        app(ContexteClinique::class)->oublier();
        $aTransferer = Sejour::withoutGlobalScopes()->create(['clinique_id' => $clinique->id, 'patient_id' => $troisieme->id, 'box' => 1, 'motif' => 'À transférer', 'admis_le' => now(), 'sortie_prevue' => 'À définir']);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $clinique->id, 'ligne_planning_id' => $ligne->id]);
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $clinique->societe->organisation_id, 'clinique_id' => $clinique->id,
            'rendez_vous_id' => $rendezVous->id, 'statut' => StatutEnregistrement::Enregistrement,
        ]);

        return ['clinique' => $clinique->id, 'patient' => $patient->id, 'autrePatient' => $autrePatient->id, 'sejour' => $aTransferer->id, 'enregistrement' => $enregistrement->id, 'jeton' => $jeton];
    };
    $php = PHP_BINARY;
    $lancer = function (string $role, string $cas, array $donnees) use ($php, $racine) {
        $tuyaux = [];
        $processus = proc_open([$php, $racine.'/scripts/essai-verrous-mysql.php', $role, $cas, base64_encode(json_encode($donnees))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, $racine);

        return [$processus, $tuyaux];
    };
    $lire = function (array $lance): string {
        [$processus, $tuyaux] = $lance;
        $sortie = stream_get_contents($tuyaux[1]).stream_get_contents($tuyaux[2]);
        proc_close($processus);

        return trim($sortie);
    };

    foreach (['admission' => 'Deux admissions du même patient', 'box' => 'Transfert vers un box pris au même instant', 'audio' => 'Deux morceaux qui dépasseraient 300 Mo ensemble'] as $cas => $titre) {
        foreach (['tenter' => 'le vrai geste de l’app', 'temoin' => 'témoin : le même contrôle sans le verrou'] as $second => $variante) {
            $donnees = [...$preparer(), 'depart' => microtime(true)];
            $tient = $lancer('tenir', $cas, $donnees);
            sleep(1);
            $tente = $lancer($second, $cas, $donnees);
            echo "\n== {$titre} ({$variante})\n";
            echo '  premier (tient le verrou '.ATTENTE." s) :\n    ".str_replace("\n", "\n    ", $lire($tient))."\n";
            echo "  second (lancé 1 s après) :\n    ".str_replace("\n", "\n    ", $lire($tente))."\n";
        }
    }
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS `'.BASE.'`');
    echo "\nBase ".BASE." supprimée.\n";
}
