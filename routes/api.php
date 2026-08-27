<?php

use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\AjustementStockController;
use App\Http\Controllers\Api\V1\Auth\CodeEmailController;
use App\Http\Controllers\Api\V1\Auth\EmailConnuController;
use App\Http\Controllers\Api\V1\Auth\PasskeyConnexionController;
use App\Http\Controllers\Api\V1\Auth\PasskeyController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CarnetSanteController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClotureCaisseController;
use App\Http\Controllers\Api\V1\CommandeFournisseurController;
use App\Http\Controllers\Api\V1\CompteClientController;
use App\Http\Controllers\Api\V1\CompteRenduController;
use App\Http\Controllers\Api\V1\CycleDevisController;
use App\Http\Controllers\Api\V1\CycleOrdonnanceController;
use App\Http\Controllers\Api\V1\DecompteAssuranceController;
use App\Http\Controllers\Api\V1\DemandeRendezVousController;
use App\Http\Controllers\Api\V1\DevisController;
use App\Http\Controllers\Api\V1\DisponibiliteController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EncaissementController;
use App\Http\Controllers\Api\V1\FactureController;
use App\Http\Controllers\Api\V1\FactureDevisController;
use App\Http\Controllers\Api\V1\FactureLigneController;
use App\Http\Controllers\Api\V1\FacturePrescriptionController;
use App\Http\Controllers\Api\V1\FinConsultationController;
use App\Http\Controllers\Api\V1\HoraireOuvertureController;
use App\Http\Controllers\Api\V1\LignePlanningController;
use App\Http\Controllers\Api\V1\ListeAttenteController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\MouvementStockController;
use App\Http\Controllers\Api\V1\OrdonnanceController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PeseeController;
use App\Http\Controllers\Api\V1\PrestationController;
use App\Http\Controllers\Api\V1\ProchainCreneauController;
use App\Http\Controllers\Api\V1\ProduitController;
use App\Http\Controllers\Api\V1\ReleveDentaireController;
use App\Http\Controllers\Api\V1\ReleveOphtalmologiqueController;
use App\Http\Controllers\Api\V1\RendezVousAuditController;
use App\Http\Controllers\Api\V1\RendezVousController;
use App\Http\Controllers\Api\V1\RendezVousPlacementController;
use App\Http\Controllers\Api\V1\RendezVousStatutController;
use App\Http\Controllers\Api\V1\SalleController;
use App\Http\Controllers\Api\V1\StatistiquesCaisseController;
use App\Http\Controllers\Api\V1\UrgenceAttenteController;
use App\Http\Controllers\Api\V1\VaccinationController;
use App\Http\Controllers\Api\V1\VisiteController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 — jetons Sanctum uniquement (jamais de session SPA).
 * Les routes authentifiées passent toutes par le middleware `clinique`
 * qui applique le scope multi-clinique du jeton, globalement.
 */
Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('email', EmailConnuController::class);
        Route::post('code-email/envoyer', [CodeEmailController::class, 'envoyer']);
        Route::post('code-email/verifier', [CodeEmailController::class, 'verifier']);
        Route::post('passkey/options', [PasskeyConnexionController::class, 'options']);
        Route::post('passkey/verifier', [PasskeyConnexionController::class, 'verifier']);
    });

    Route::middleware(['auth:sanctum', 'clinique'])->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::get('moi', [SessionController::class, 'moi']);
            Route::post('rafraichir', [SessionController::class, 'rafraichir']);
            Route::post('deconnexion', [SessionController::class, 'deconnexion']);

            Route::get('passkeys', [PasskeyController::class, 'index']);
            Route::post('passkeys/options', [PasskeyController::class, 'options']);
            Route::post('passkeys', [PasskeyController::class, 'enregistrer']);
            Route::delete('passkeys/{passkey}', [PasskeyController::class, 'supprimer']);
        });

        Route::apiResource('salles', SalleController::class)->except('destroy');

        // Domaine planning. Les permissions se vérifient dans les
        // contrôleurs (Policies) ; l'isolation clinique par le scope
        // global. Pas de destroy sur les rendez-vous hors fenêtre
        // d'undo d'une création — l'annulation est un statut.
        Route::apiResource('lignes-planning', LignePlanningController::class)
            ->except('destroy')
            ->parameters(['lignes-planning' => 'lignePlanning']);

        Route::get('horaires', [HoraireOuvertureController::class, 'afficher']);
        Route::put('horaires', [HoraireOuvertureController::class, 'modifier']);

        Route::get('planning/disponibilites', DisponibiliteController::class);

        Route::post('rendez-vous/prochain-creneau', ProchainCreneauController::class);
        Route::post('rendez-vous/{rendezVous}/statut', [RendezVousStatutController::class, 'changer']);
        Route::post('rendez-vous/{rendezVous}/non-venu', [RendezVousStatutController::class, 'nonVenu']);
        Route::post('rendez-vous/{rendezVous}/annuler', [RendezVousStatutController::class, 'annuler']);
        // Fin de consultation (« Ma journée ») — le geste composé :
        // facture + carnet + ordonnance + stock, RDV passé en fini.
        Route::post('rendez-vous/{rendezVous}/fin-de-consultation', FinConsultationController::class);
        Route::post('rendez-vous/{rendezVous}/deplacer', [RendezVousPlacementController::class, 'deplacer']);
        Route::post('rendez-vous/{rendezVous}/redimensionner', [RendezVousPlacementController::class, 'redimensionner']);
        Route::get('rendez-vous/{rendezVous}/audits', RendezVousAuditController::class);
        Route::apiResource('rendez-vous', RendezVousController::class)
            ->parameters(['rendez-vous' => 'rendezVous']);

        Route::post('absences/{absence}/valider', [AbsenceController::class, 'valider']);
        Route::post('absences/{absence}/refuser', [AbsenceController::class, 'refuser']);
        Route::apiResource('absences', AbsenceController::class);

        // Files alimentant le planning : « placer » crée le rendez-vous
        // et retire l'entrée — le RDV créé fait foi.
        Route::get('urgences', [UrgenceAttenteController::class, 'index']);
        Route::post('urgences', [UrgenceAttenteController::class, 'store']);
        Route::post('urgences/{urgence}/placer', [UrgenceAttenteController::class, 'placer']);
        Route::delete('urgences/{urgence}', [UrgenceAttenteController::class, 'destroy']);

        Route::get('demandes', [DemandeRendezVousController::class, 'index']);
        Route::post('demandes/{demande}/placer', [DemandeRendezVousController::class, 'placer']);
        Route::delete('demandes/{demande}', [DemandeRendezVousController::class, 'destroy']);

        Route::get('liste-attente', [ListeAttenteController::class, 'index']);
        Route::post('liste-attente', [ListeAttenteController::class, 'store']);
        Route::post('liste-attente/{inscription}/placer', [ListeAttenteController::class, 'placer']);
        Route::delete('liste-attente/{inscription}', [ListeAttenteController::class, 'destroy']);

        // Domaine patients & dossier médical. Clients et patients sont
        // de NIVEAU ORGANISATION (partagés entre les cliniques du
        // jeton) ; jamais de suppression de fiche. Les enfants du
        // dossier se résolvent toujours via le patient.
        Route::apiResource('clients', ClientController::class)->except('destroy');
        Route::post('clients/{client}/compte', [CompteClientController::class, 'creer']);
        Route::delete('clients/{client}/compte', [CompteClientController::class, 'supprimer']);

        Route::apiResource('patients', PatientController::class)->except('destroy');

        // Carnet de santé : puce (15 chiffres, format AMICUS) et
        // déclaration AMICUS (chiens pucés uniquement).
        Route::put('patients/{patient}/puce', [CarnetSanteController::class, 'modifierPuce']);
        Route::post('patients/{patient}/amicus', [CarnetSanteController::class, 'declarerAmicus']);

        Route::post('patients/{patient}/pesees', [PeseeController::class, 'store']);
        Route::patch('patients/{patient}/pesees/{pesee}', [PeseeController::class, 'update']);
        Route::delete('patients/{patient}/pesees/{pesee}', [PeseeController::class, 'destroy']);

        Route::get('patients/{patient}/vaccinations', [VaccinationController::class, 'index']);
        Route::post('patients/{patient}/vaccinations', [VaccinationController::class, 'store']);

        Route::get('patients/{patient}/visites', [VisiteController::class, 'index']);
        Route::post('patients/{patient}/visites', [VisiteController::class, 'store']);

        Route::get('patients/{patient}/documents', [DocumentController::class, 'index']);
        Route::post('patients/{patient}/documents', [DocumentController::class, 'store']);

        Route::get('patients/{patient}/releves-ophtalmologiques', [ReleveOphtalmologiqueController::class, 'index']);
        Route::post('patients/{patient}/releves-ophtalmologiques', [ReleveOphtalmologiqueController::class, 'store']);
        Route::patch('patients/{patient}/releves-ophtalmologiques/{releve}', [ReleveOphtalmologiqueController::class, 'update']);
        Route::delete('patients/{patient}/releves-ophtalmologiques/{releve}', [ReleveOphtalmologiqueController::class, 'destroy']);

        // Odontogramme Triadan — map complète, remplacée d'un bloc.
        Route::get('patients/{patient}/dents', [ReleveDentaireController::class, 'afficher']);
        Route::put('patients/{patient}/dents', [ReleveDentaireController::class, 'remplacer']);

        // Compte rendu SOAP — un par rendez-vous, upsert au PUT. Comme
        // tout le dossier médical, il est de niveau organisation : le
        // rendez-vous est résolu parmi les cliniques de l'organisation
        // (pas de binding implicite, qui le bornerait à la clinique).
        Route::get('rendez-vous/{rendezVous}/compte-rendu', [CompteRenduController::class, 'afficher']);
        Route::put('rendez-vous/{rendezVous}/compte-rendu', [CompteRenduController::class, 'enregistrer']);

        // Registre des ordonnances — du dossier médical : domaine
        // patients (Policies), niveau organisation. La signature sort
        // le stock de la clinique du jeton ; jamais de modification
        // après signature — renouvellement, clôture, ou annulation
        // immédiate d'une signature (fenêtre d'undo, stock restauré).
        Route::get('ordonnances', [OrdonnanceController::class, 'index']);
        Route::post('ordonnances', [OrdonnanceController::class, 'store']);
        Route::get('ordonnances/{ordonnance}', [OrdonnanceController::class, 'show']);
        Route::delete('ordonnances/{ordonnance}', [OrdonnanceController::class, 'destroy']);
        Route::post('ordonnances/{ordonnance}/renouveler', [CycleOrdonnanceController::class, 'renouveler']);
        Route::post('ordonnances/{ordonnance}/cloturer', [CycleOrdonnanceController::class, 'cloturer']);

        // Domaine facturation — toutes les routes derrière le Gate
        // dédié, vérifié en base à chaque requête (une ASV n'y a pas
        // accès). Les factures et devis suivent le patient (niveau
        // organisation) ; les gestes de caisse (lignes, encaissement,
        // clôture) se font depuis la clinique d'émission.
        Route::middleware('can:facturation')->prefix('facturation')->group(function (): void {
            Route::get('prestations', [PrestationController::class, 'index']);
            Route::get('statistiques', StatistiquesCaisseController::class);

            Route::get('factures', [FactureController::class, 'index']);
            Route::post('factures', [FactureController::class, 'store']);
            Route::get('factures/{facture}', [FactureController::class, 'show']);
            Route::delete('factures/{facture}', [FactureController::class, 'destroy']);
            Route::post('factures/{facture}/lignes', [FactureLigneController::class, 'store']);
            Route::post('factures/{facture}/prescriptions', [FacturePrescriptionController::class, 'store']);
            Route::post('factures/{facture}/encaisser', [EncaissementController::class, 'encaisser']);
            Route::post('factures/{facture}/payer-plus-tard', [EncaissementController::class, 'payerPlusTard']);
            Route::put('factures/{facture}/decompte', [DecompteAssuranceController::class, 'enregistrer']);
            Route::post('factures/{facture}/decompte/avancer', [DecompteAssuranceController::class, 'avancer']);

            Route::get('clotures-caisse', [ClotureCaisseController::class, 'index']);
            Route::post('clotures-caisse', [ClotureCaisseController::class, 'store']);
            Route::get('clotures-caisse/{cloture}', [ClotureCaisseController::class, 'show']);

            Route::post('devis/{devis}/envoyer', [CycleDevisController::class, 'envoyer']);
            Route::post('devis/{devis}/accepter', [CycleDevisController::class, 'accepter']);
            Route::post('devis/{devis}/refuser', [CycleDevisController::class, 'refuser']);
            Route::post('devis/{devis}/dupliquer', [CycleDevisController::class, 'dupliquer']);
            Route::post('devis/{devis}/facturer-acompte', [FactureDevisController::class, 'acompte']);
            Route::post('devis/{devis}/facturer-finale', [FactureDevisController::class, 'finale']);
            Route::apiResource('devis', DevisController::class)->parameters(['devis' => 'devis']);
        });

        // Domaine stocks. Catalogue d'organisation, quantités par
        // clinique : la lecture montre la ventilation de toutes les
        // cliniques, les écritures (ajustements, réceptions, lots) ne
        // touchent que la clinique du jeton.
        Route::middleware('can:stocks')->prefix('stocks')->group(function (): void {
            Route::get('produits', [ProduitController::class, 'index']);
            Route::post('produits', [ProduitController::class, 'store']);
            Route::get('produits/{produit}', [ProduitController::class, 'show']);
            Route::post('produits/{produit}/ajuster', AjustementStockController::class);
            Route::get('produits/{produit}/mouvements', [MouvementStockController::class, 'index']);

            Route::get('commandes', [CommandeFournisseurController::class, 'index']);
            Route::post('commandes', [CommandeFournisseurController::class, 'store']);
            Route::post('commandes/generer', [CommandeFournisseurController::class, 'generer']);
            Route::post('commandes/{commande}/envoyer', [CommandeFournisseurController::class, 'envoyer']);
            Route::post('commandes/{commande}/receptionner', [CommandeFournisseurController::class, 'receptionner']);
            Route::delete('commandes/{commande}', [CommandeFournisseurController::class, 'destroy']);

            Route::get('lots', [LotController::class, 'index']);
            Route::post('lots/{lot}/ecarter', [LotController::class, 'ecarter']);
        });
    });
});
