<?php

use App\Http\Controllers\Api\V1\AbonnementController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\AccesEmployeController;
use App\Http\Controllers\Api\V1\AjustementStockController;
use App\Http\Controllers\Api\V1\AnalyseController;
use App\Http\Controllers\Api\V1\AnalysePatientController;
use App\Http\Controllers\Api\V1\Auth\ChangementCliniqueController;
use App\Http\Controllers\Api\V1\Auth\CodeEmailController;
use App\Http\Controllers\Api\V1\Auth\EmailConnuController;
use App\Http\Controllers\Api\V1\Auth\PasskeyConnexionController;
use App\Http\Controllers\Api\V1\Auth\PasskeyController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CarnetSanteController;
use App\Http\Controllers\Api\V1\ChecklistInterventionController;
use App\Http\Controllers\Api\V1\ClicheDicomController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClotureCaisseController;
use App\Http\Controllers\Api\V1\CommandeBoutiqueController;
use App\Http\Controllers\Api\V1\CommandeFournisseurController;
use App\Http\Controllers\Api\V1\CompteClientController;
use App\Http\Controllers\Api\V1\CompteRenduController;
use App\Http\Controllers\Api\V1\CompteRenduImagerieController;
use App\Http\Controllers\Api\V1\ConsentementInterventionController;
use App\Http\Controllers\Api\V1\ConstanteSejourController;
use App\Http\Controllers\Api\V1\CycleAnalyseController;
use App\Http\Controllers\Api\V1\CycleDevisController;
use App\Http\Controllers\Api\V1\CycleInterventionController;
use App\Http\Controllers\Api\V1\CycleOrdonnanceController;
use App\Http\Controllers\Api\V1\DecompteAssuranceController;
use App\Http\Controllers\Api\V1\DemandeRendezVousController;
use App\Http\Controllers\Api\V1\DevisController;
use App\Http\Controllers\Api\V1\DisponibiliteController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EncaissementController;
use App\Http\Controllers\Api\V1\EquipeEmployeController;
use App\Http\Controllers\Api\V1\ExamenImagerieController;
use App\Http\Controllers\Api\V1\FactureController;
use App\Http\Controllers\Api\V1\FactureDevisController;
use App\Http\Controllers\Api\V1\FactureLigneController;
use App\Http\Controllers\Api\V1\FacturePrescriptionController;
use App\Http\Controllers\Api\V1\FinConsultationController;
use App\Http\Controllers\Api\V1\FinTeleconsultationController;
use App\Http\Controllers\Api\V1\GardeWeekendController;
use App\Http\Controllers\Api\V1\HoraireOuvertureController;
use App\Http\Controllers\Api\V1\ImageriePatientController;
use App\Http\Controllers\Api\V1\InterventionController;
use App\Http\Controllers\Api\V1\LicenciementEmployeController;
use App\Http\Controllers\Api\V1\LignePlanningController;
use App\Http\Controllers\Api\V1\ListeAttenteController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\MouvementStockController;
use App\Http\Controllers\Api\V1\MouvementStupefiantController;
use App\Http\Controllers\Api\V1\MoyenPaiementController;
use App\Http\Controllers\Api\V1\OrdonnanceController;
use App\Http\Controllers\Api\V1\OrganisationCliniqueController;
use App\Http\Controllers\Api\V1\OrganisationController;
use App\Http\Controllers\Api\V1\OrganisationFactureController;
use App\Http\Controllers\Api\V1\PackQuotaController;
use App\Http\Controllers\Api\V1\PanelLaboController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PeseeController;
use App\Http\Controllers\Api\V1\PrestationController;
use App\Http\Controllers\Api\V1\ProchainCreneauController;
use App\Http\Controllers\Api\V1\ProduitController;
use App\Http\Controllers\Api\V1\RegistreStupefiantController;
use App\Http\Controllers\Api\V1\ReleveDentaireController;
use App\Http\Controllers\Api\V1\ReleveOphtalmologiqueController;
use App\Http\Controllers\Api\V1\RendezVousAuditController;
use App\Http\Controllers\Api\V1\RendezVousController;
use App\Http\Controllers\Api\V1\RendezVousPlacementController;
use App\Http\Controllers\Api\V1\RendezVousStatutController;
use App\Http\Controllers\Api\V1\ResultatAnalyseController;
use App\Http\Controllers\Api\V1\SalleController;
use App\Http\Controllers\Api\V1\SejourController;
use App\Http\Controllers\Api\V1\SessionTeleconsultationController;
use App\Http\Controllers\Api\V1\SocieteController;
use App\Http\Controllers\Api\V1\SoinSejourController;
use App\Http\Controllers\Api\V1\SortieSejourController;
use App\Http\Controllers\Api\V1\StatistiquesBoutiqueController;
use App\Http\Controllers\Api\V1\StatistiquesCaisseController;
use App\Http\Controllers\Api\V1\TrameImagerieController;
use App\Http\Controllers\Api\V1\UrgenceAttenteController;
use App\Http\Controllers\Api\V1\VaccinationController;
use App\Http\Controllers\Api\V1\VenteComptoirController;
use App\Http\Controllers\Api\V1\VisiteController;
use App\Http\Controllers\Api\V1\VitrineController;
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
            Route::post('clinique', ChangementCliniqueController::class);
            Route::post('deconnexion', [SessionController::class, 'deconnexion']);

            Route::get('passkeys', [PasskeyController::class, 'index']);
            Route::post('passkeys/options', [PasskeyController::class, 'options']);
            Route::post('passkeys', [PasskeyController::class, 'enregistrer']);
            Route::delete('passkeys/{passkey}', [PasskeyController::class, 'supprimer']);
        });

        // Le destroy n'existe que pour les salles de bloc (l'écran du
        // bloc gère sa liste) — gardes métier dans le contrôleur.
        Route::apiResource('salles', SalleController::class);

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

        // Analyses et imagerie du dossier patient — toutes cliniques
        // de l'organisation (le dossier médical est partagé), borné
        // par le patient, scope clinique levé.
        Route::get('patients/{patient}/analyses', AnalysePatientController::class);
        Route::get('patients/{patient}/imagerie', ImageriePatientController::class);

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

        // Domaine organisation — structure et abonnement SaaS, réservé
        // au rôle qui porte le domaine `organisation` (l'Administrateur
        // par défaut). Les modèles de STRUCTURE (organisation, sociétés,
        // cliniques) sont l'arbre au-dessus des global scopes : ils se
        // résolvent explicitement depuis l'organisation du jeton, jamais
        // par binding implicite.
        Route::middleware('can:organisation')->prefix('organisation')->group(function (): void {
            Route::get('/', [OrganisationController::class, 'afficher']);

            Route::get('societes', [SocieteController::class, 'index']);
            Route::post('societes', [SocieteController::class, 'store']);

            Route::get('cliniques', [OrganisationCliniqueController::class, 'index']);
            Route::post('cliniques', [OrganisationCliniqueController::class, 'store']);
            Route::patch('cliniques/{clinique}', [OrganisationCliniqueController::class, 'update'])->whereNumber('clinique');
            Route::delete('cliniques/{clinique}', [OrganisationCliniqueController::class, 'destroy'])->whereNumber('clinique');

            Route::post('abonnement', [AbonnementController::class, 'changer']);
            Route::post('abonnement/resilier', [AbonnementController::class, 'resilier']);
            Route::post('abonnement/reactiver', [AbonnementController::class, 'reactiver']);
            Route::post('packs', [PackQuotaController::class, 'acheter']);
            Route::put('moyen-paiement', [MoyenPaiementController::class, 'enregistrer']);
            Route::delete('moyen-paiement', [MoyenPaiementController::class, 'supprimer']);
            Route::get('factures', [OrganisationFactureController::class, 'index']);
        });

        // Domaine équipe — fiches RH. La liste est celle de la clinique
        // du jeton (écran Équipe) ; les gestes (fiche, embauche,
        // suspension, licenciement) sont bornés à l'ORGANISATION :
        // l'écran Organisation pilote aussi les cliniques sœurs, la
        // résolution se fait sans binding implicite. Les absences,
        // elles, restent aux routes planning/équipe existantes.
        Route::middleware('can:equipe')->prefix('equipe')->group(function (): void {
            Route::get('employes', [EquipeEmployeController::class, 'index']);
            Route::post('employes', [EquipeEmployeController::class, 'store']);
            Route::get('employes/{employe}', [EquipeEmployeController::class, 'show'])->whereNumber('employe');
            Route::patch('employes/{employe}', [EquipeEmployeController::class, 'update'])->whereNumber('employe');
            Route::post('employes/{employe}/suspendre', [AccesEmployeController::class, 'suspendre'])->whereNumber('employe');
            Route::post('employes/{employe}/retablir', [AccesEmployeController::class, 'retablir'])->whereNumber('employe');
            Route::post('employes/{employe}/licencier', [LicenciementEmployeController::class, 'licencier'])->whereNumber('employe');
            Route::post('employes/{employe}/annuler-licenciement', [LicenciementEmployeController::class, 'annuler'])->whereNumber('employe');

            Route::get('gardes', [GardeWeekendController::class, 'index']);
            Route::put('gardes', [GardeWeekendController::class, 'modifier']);
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

        // Registre des stupéfiants — domaine stocks, tenu PAR CLINIQUE
        // (art. 62 OCStup) : journal immuable en création seule (une
        // erreur se corrige par un mouvement inverse), soldes et export
        // pour les contrôles cantonaux.
        Route::middleware('can:stocks')->prefix('stupefiants')->group(function (): void {
            Route::get('mouvements', [MouvementStupefiantController::class, 'index']);
            Route::post('mouvements', [MouvementStupefiantController::class, 'store']);
            Route::get('soldes', [RegistreStupefiantController::class, 'soldes']);
            Route::get('registre', [RegistreStupefiantController::class, 'exporter']);
        });

        // Domaine hospitalisation — rattaché au domaine patients (soins
        // au chevet : l'ASV le tient autant que le vétérinaire). Séjours
        // PAR CLINIQUE (le box est un lit physique), enfants (soins,
        // constantes) résolus via le séjour. Jamais de suppression : la
        // sortie clôt le séjour et facture (effet système).
        Route::middleware('can:patients')->prefix('hospitalisation')->group(function (): void {
            Route::get('sejours', [SejourController::class, 'index']);
            Route::post('sejours', [SejourController::class, 'store']);
            Route::get('sejours/{sejour}', [SejourController::class, 'show']);
            Route::patch('sejours/{sejour}', [SejourController::class, 'update']);

            Route::post('sejours/{sejour}/soins', [SoinSejourController::class, 'store']);
            Route::post('sejours/{sejour}/soins/{soin}/faire', [SoinSejourController::class, 'faire']);
            Route::post('sejours/{sejour}/constantes', [ConstanteSejourController::class, 'store']);

            Route::get('sejours/{sejour}/facture', [SortieSejourController::class, 'apercu']);
            Route::post('sejours/{sejour}/sortie', [SortieSejourController::class, 'sortir']);
        });

        // Domaine laboratoire — rattaché au domaine patients (dossier
        // médical : l'ASV tient la paillasse). Demandes PAR CLINIQUE
        // (worklist du labo physique), numérotées A-YY-NNNN, cycle
        // attente → cours → valid → ok un cran à la fois ; résultats
        // aux bornes instantanées, figés par la validation signée.
        // Jamais de suppression.
        Route::middleware('can:patients')->prefix('laboratoire')->group(function (): void {
            Route::get('panels', [PanelLaboController::class, 'index']);

            Route::get('analyses', [AnalyseController::class, 'index']);
            Route::post('analyses', [AnalyseController::class, 'store']);
            Route::get('analyses/{analyse}', [AnalyseController::class, 'show']);
            Route::patch('analyses/{analyse}', [AnalyseController::class, 'update']);

            Route::post('analyses/{analyse}/avancer', CycleAnalyseController::class);
            Route::put('analyses/{analyse}/resultats', ResultatAnalyseController::class);
        });

        // Domaine imagerie — rattaché au domaine patients (dossier
        // médical). Examens PAR CLINIQUE (worklist de la modalité
        // physique), même patron que le bloc : l'examen est le dossier
        // maître, le RDV `imagerie` lié n'est que sa projection
        // planning — la signature du compte rendu fige l'examen, clôt
        // le RDV et facture l'acte. Flux DICOM idempotent par SOP
        // Instance UID. Jamais de suppression.
        Route::middleware('can:patients')->prefix('imagerie')->group(function (): void {
            Route::get('trames', TrameImagerieController::class);

            Route::get('examens', [ExamenImagerieController::class, 'index']);
            Route::post('examens', [ExamenImagerieController::class, 'store']);
            Route::get('examens/{examen}', [ExamenImagerieController::class, 'show']);
            Route::patch('examens/{examen}', [ExamenImagerieController::class, 'update']);

            Route::post('examens/{examen}/compte-rendu', CompteRenduImagerieController::class);
            Route::post('examens/{examen}/cliches', ClicheDicomController::class);
        });

        // Domaine bloc opératoire — son propre domaine de permission
        // (l'Assistant par défaut ne l'a pas). L'intervention est le
        // dossier maître ; le RDV `chir` lié n'est que sa projection
        // planning. Cycle un cran à la fois, checklist bloquante avant
        // l'incision, consentement coché par sa seule signature.
        Route::middleware('can:bloc')->prefix('bloc')->group(function (): void {
            Route::get('interventions', [InterventionController::class, 'index']);
            Route::post('interventions', [InterventionController::class, 'store']);
            Route::get('interventions/{intervention}', [InterventionController::class, 'show']);
            Route::patch('interventions/{intervention}', [InterventionController::class, 'update']);

            Route::post('interventions/{intervention}/avancer', CycleInterventionController::class);
            Route::post('interventions/{intervention}/checklist', [ChecklistInterventionController::class, 'ajouter']);
            Route::put('interventions/{intervention}/checklist/{code}', [ChecklistInterventionController::class, 'cocher']);
            Route::delete('interventions/{intervention}/checklist/{code}', [ChecklistInterventionController::class, 'retirer']);
            Route::post('interventions/{intervention}/consentement', ConsentementInterventionController::class);
        });

        // Domaine téléconsultation — rattaché au domaine planning (la
        // session pilote les statuts du RDV). La session est le
        // dossier maître de l'appel, le RDV `tele` lié n'est que sa
        // projection : démarrage → cours, « Terminer » → fini +
        // facture (effet système), « Quitter » ne touche à rien. La
        // durée est servie par le serveur. Jamais de suppression.
        Route::middleware('can:planning')->prefix('tele')->group(function (): void {
            Route::get('sessions', [SessionTeleconsultationController::class, 'index']);
            Route::post('sessions', [SessionTeleconsultationController::class, 'store']);
            Route::get('sessions/{session}', [SessionTeleconsultationController::class, 'show']);
            Route::post('sessions/{session}/terminer', FinTeleconsultationController::class);
        });

        // Boutique — domaine stocks (arbitrage : l'ASV la tient, c'est
        // le stock de la clinique qui bouge). La vente comptoir crée sa
        // facture payée comme effet système ; la remise d'une commande
        // en ligne sort le stock de la clinique de retrait.
        Route::middleware('can:stocks')->prefix('boutique')->group(function (): void {
            Route::get('commandes', [CommandeBoutiqueController::class, 'index']);
            Route::post('commandes', [CommandeBoutiqueController::class, 'store']);
            Route::post('commandes/{commande}/preparer', [CommandeBoutiqueController::class, 'preparer']);
            Route::post('commandes/{commande}/retirer', [CommandeBoutiqueController::class, 'retirer']);

            Route::post('vente', VenteComptoirController::class);
            Route::patch('produits/{produit}/vitrine', [VitrineController::class, 'modifier']);
            Route::get('statistiques', StatistiquesBoutiqueController::class);
        });
    });
});
