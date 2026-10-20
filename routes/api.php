<?php

use App\Http\Controllers\Api\V1\AbonnementController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\AccesEmployeController;
use App\Http\Controllers\Api\V1\AffectationsEmployeController;
use App\Http\Controllers\Api\V1\AjustementStockController;
use App\Http\Controllers\Api\V1\AnalyseController;
use App\Http\Controllers\Api\V1\AnalysePatientController;
use App\Http\Controllers\Api\V1\AnnuaireCliniqueController;
use App\Http\Controllers\Api\V1\AnnuaireEmployeController;
use App\Http\Controllers\Api\V1\AppStoreNotificationsController;
use App\Http\Controllers\Api\V1\Auth\ChangementCliniqueController;
use App\Http\Controllers\Api\V1\Auth\CodeEmailController;
use App\Http\Controllers\Api\V1\Auth\EmailConnuController;
use App\Http\Controllers\Api\V1\Auth\InscriptionPasskeyController;
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
use App\Http\Controllers\Api\V1\CommandeFournisseurLigneController;
use App\Http\Controllers\Api\V1\CompteClientController;
use App\Http\Controllers\Api\V1\CompteEmployeController;
use App\Http\Controllers\Api\V1\CompteRenduController;
use App\Http\Controllers\Api\V1\CompteRenduImagerieController;
use App\Http\Controllers\Api\V1\ConsentementInterventionController;
use App\Http\Controllers\Api\V1\ConstanteSejourController;
use App\Http\Controllers\Api\V1\CycleAnalyseController;
use App\Http\Controllers\Api\V1\CycleDevisController;
use App\Http\Controllers\Api\V1\CycleInterventionController;
use App\Http\Controllers\Api\V1\CycleOrdonnanceController;
use App\Http\Controllers\Api\V1\DecompteAssuranceController;
use App\Http\Controllers\Api\V1\DemandeRattachementController;
use App\Http\Controllers\Api\V1\DemandeRendezVousController;
use App\Http\Controllers\Api\V1\DevisController;
use App\Http\Controllers\Api\V1\DisponibiliteController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DomainePublicController;
use App\Http\Controllers\Api\V1\EncaissementController;
use App\Http\Controllers\Api\V1\EnregistrementConsultationController;
use App\Http\Controllers\Api\V1\EquipeEmployeController;
use App\Http\Controllers\Api\V1\Espace\AbonnementProprietaireController;
use App\Http\Controllers\Api\V1\Espace\AccueilController;
use App\Http\Controllers\Api\V1\Espace\AnimalController;
use App\Http\Controllers\Api\V1\Espace\AppStoreController;
use App\Http\Controllers\Api\V1\Espace\BoutiqueProprietaireController;
use App\Http\Controllers\Api\V1\Espace\CliniqueProprietaireController;
use App\Http\Controllers\Api\V1\Espace\CoordonneesProprietaireController;
use App\Http\Controllers\Api\V1\Espace\DocumentProprietaireController;
use App\Http\Controllers\Api\V1\Espace\MessageProprietaireController;
use App\Http\Controllers\Api\V1\Espace\PlaneteController;
use App\Http\Controllers\Api\V1\Espace\ReglagesProprietaireController;
use App\Http\Controllers\Api\V1\Espace\RendezVousProprietaireController;
use App\Http\Controllers\Api\V1\Espace\VerificationEmailController;
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
use App\Http\Controllers\Api\V1\InscriptionCliniqueController;
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
use App\Http\Controllers\Api\V1\OrganisationHoraireController;
use App\Http\Controllers\Api\V1\OrganisationPrestationController;
use App\Http\Controllers\Api\V1\OrganisationTranscriptionController;
use App\Http\Controllers\Api\V1\PackQuotaController;
use App\Http\Controllers\Api\V1\PaiementCaisseController;
use App\Http\Controllers\Api\V1\PanelLaboController;
use App\Http\Controllers\Api\V1\PartieEnregistrementController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PeseeController;
use App\Http\Controllers\Api\V1\PhotoEmployeController;
use App\Http\Controllers\Api\V1\PhotoPatientController;
use App\Http\Controllers\Api\V1\PremiersPasController;
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
use App\Http\Controllers\Api\V1\SiteDomaineController;
use App\Http\Controllers\Api\V1\SiteLogoController;
use App\Http\Controllers\Api\V1\SitePublicController;
use App\Http\Controllers\Api\V1\SiteWebController;
use App\Http\Controllers\Api\V1\SocieteController;
use App\Http\Controllers\Api\V1\SoinSejourController;
use App\Http\Controllers\Api\V1\SortieSejourController;
use App\Http\Controllers\Api\V1\StatistiquesBoutiqueController;
use App\Http\Controllers\Api\V1\StatistiquesCaisseController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\TerminalPaiementController;
use App\Http\Controllers\Api\V1\TrameImagerieController;
use App\Http\Controllers\Api\V1\TranscriptionController;
use App\Http\Controllers\Api\V1\UrgenceAttenteController;
use App\Http\Controllers\Api\V1\VaccinationController;
use App\Http\Controllers\Api\V1\VaccinCatalogueController;
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

        // Inscription publique (e-mail inconnu) : compte sans clinique,
        // créé avec sa première passkey.
        Route::post('inscription/passkey/options', [InscriptionPasskeyController::class, 'options']);
        Route::post('inscription/passkey', [InscriptionPasskeyController::class, 'inscrire']);
    });

    // La session elle-même : ouverte aussi aux jetons sans clinique
    // (comptes inscrits, sans rattachement) — `clinique:facultatif`.
    Route::prefix('auth')->middleware(['auth:sanctum', 'clinique:facultatif'])->group(function (): void {
        Route::get('moi', [SessionController::class, 'moi']);
        Route::post('rafraichir', [SessionController::class, 'rafraichir']);
        Route::post('clinique', ChangementCliniqueController::class);
        Route::post('deconnexion', [SessionController::class, 'deconnexion']);

        Route::get('passkeys', [PasskeyController::class, 'index']);
        Route::post('passkeys/options', [PasskeyController::class, 'options']);
        Route::post('passkeys', [PasskeyController::class, 'enregistrer']);
        Route::delete('passkeys/{passkey}', [PasskeyController::class, 'supprimer']);
    });

    // Inscription d'une clinique : tout compte connecté, avec ou sans
    // clinique, hors contexte clinique (l'organisation naît à part).
    // Vérification du GLN dans MedReg pendant la saisie, puis création.
    Route::prefix('inscription-clinique')->middleware(['auth:sanctum', 'throttle:10,1'])->group(function (): void {
        Route::post('gln', [InscriptionCliniqueController::class, 'verifier']);
        Route::post('/', [InscriptionCliniqueController::class, 'inscrire']);
    });

    // Webhook Stripe (public, vérifié par signature) : issue des
    // paiements de caisse — terminal et TWINT.
    Route::post('stripe/webhook', StripeWebhookController::class);

    // Notifications de l'App Store (public, vérifiées par signature) :
    // renouvellements et remboursements des achats de l'app iPhone.
    Route::post('app-store/notifications', AppStoreNotificationsController::class);

    // Vitrine publique (visiteur non connecté, depuis l'accueil) :
    // boutiques des cliniques et annuaire avec créneaux. Leur contenu ne
    // dépend d'aucun compte — mêmes actions que dans l'espace, sans
    // jeton ; commander ou déposer une demande exige toujours une session.
    Route::prefix('public')->middleware('throttle:60,1')->group(function (): void {
        Route::get('boutique', [BoutiqueProprietaireController::class, 'vitrine']);
        Route::get('cliniques', [CliniqueProprietaireController::class, 'index']);
        // Site web publié d'une organisation et de toutes ses cliniques.
        Route::get('sites/{slug}', [SitePublicController::class, 'afficher'])->where('slug', '[a-z0-9-]+');
        // Domaines personnalisés : site d'un domaine actif ; autorisation de certificat (on-demand TLS)
        Route::get('domaines/{domaine}', [DomainePublicController::class, 'site'])->where('domaine', '[a-z0-9.-]+');
        Route::get('domaines-autorises', [DomainePublicController::class, 'autorise'])->withoutMiddleware('throttle:60,1');
        Route::get('photos/{fichier}', [PhotoEmployeController::class, 'afficher'])->where('fichier', '[A-Za-z0-9]+\\.[a-z]+')->name('employe.photo')->withoutMiddleware('throttle:60,1');
        Route::get('photos-animaux/{fichier}', [PhotoPatientController::class, 'afficher'])->where('fichier', '[A-Za-z0-9]+\\.[a-z]+')->name('patient.photo')->withoutMiddleware('throttle:60,1');
        Route::get('sites/{slug}/logo', [SitePublicController::class, 'logo'])->where('slug', '[a-z0-9-]+')->name('site.logo')->withoutMiddleware('throttle:60,1');
    });

    // Espace propriétaire (portail client du proto) : tout compte
    // connecté, SANS contexte clinique — le middleware `proprietaire`
    // charge ses fiches client, toutes organisations, et chaque
    // requête filtre par ces fiches.
    Route::prefix('espace')->middleware(['auth:sanctum', 'proprietaire'])->group(function (): void {
        Route::get('accueil', AccueilController::class);
        Route::get('animaux', [AnimalController::class, 'index']);
        Route::post('animaux', [AnimalController::class, 'store']);
        Route::post('animaux/reclamer', [AnimalController::class, 'reclamer']);
        Route::get('animaux/rattachements', [AnimalController::class, 'rattachements']);
        Route::get('animaux/{animal}', [AnimalController::class, 'show']);
        Route::put('animaux/{animal}', [AnimalController::class, 'update']);
        Route::put('animaux/{animal}/acces', [AnimalController::class, 'acces']);
        Route::get('rendez-vous', [RendezVousProprietaireController::class, 'index']);
        Route::post('rendez-vous/{rendezVous}/annuler', [RendezVousProprietaireController::class, 'annuler']);
        Route::post('rendez-vous/{rendezVous}/reporter', [RendezVousProprietaireController::class, 'reporter']);
        Route::get('cliniques', [CliniqueProprietaireController::class, 'index']);
        Route::get('documents', [DocumentProprietaireController::class, 'index']);
        Route::get('boutique', [BoutiqueProprietaireController::class, 'vitrine']);
        Route::get('messages', [MessageProprietaireController::class, 'index']);
        Route::post('messages', [MessageProprietaireController::class, 'envoyer']);
        Route::get('coordonnees', [CoordonneesProprietaireController::class, 'afficher']);
        Route::get('preferences', [ReglagesProprietaireController::class, 'preferences']);
        Route::put('preferences', [ReglagesProprietaireController::class, 'modifierPreferences']);
        Route::get('appareils', [ReglagesProprietaireController::class, 'appareils']);
        Route::post('appareils/deconnecter-autres', [ReglagesProprietaireController::class, 'deconnecterAutres']);
        Route::get('notifications', [ReglagesProprietaireController::class, 'notifications']);
        Route::post('notifications/lues', [ReglagesProprietaireController::class, 'marquerLues']);
        Route::get('export', [ReglagesProprietaireController::class, 'export']);
        // Offre du propriétaire : Gratuit, ou Plus (d'autres planètes)
        Route::get('abonnement', [AbonnementProprietaireController::class, 'afficher']);
        Route::post('abonnement/carte/intention', [AbonnementProprietaireController::class, 'intentionCarte']);
        Route::put('abonnement/carte', [AbonnementProprietaireController::class, 'enregistrerCarte']);
        Route::delete('abonnement/carte', [AbonnementProprietaireController::class, 'supprimerCarte']);
        Route::post('abonnement/plus', [AbonnementProprietaireController::class, 'souscrire']);
        Route::post('abonnement/resilier', [AbonnementProprietaireController::class, 'resilier']);
        Route::post('abonnement/reactiver', [AbonnementProprietaireController::class, 'reactiver']);
        Route::get('abonnement/factures/{facture}', [AbonnementProprietaireController::class, 'facture'])->whereNumber('facture');
        Route::post('abonnement/factures/{facture}/regler', [AbonnementProprietaireController::class, 'regler'])->whereNumber('facture');
        Route::post('abonnement/factures/{facture}/confirmer', [AbonnementProprietaireController::class, 'confirmer'])->whereNumber('facture');
        // Achats dans l'app iPhone (App Store, StoreKit 2)
        Route::get('app-store', [AppStoreController::class, 'afficher']);
        Route::post('app-store/transactions', [AppStoreController::class, 'transactions']);
        // Jeu de la planète : boutique d'objets, décès d'un animal (tombe)
        Route::get('planete', [PlaneteController::class, 'afficher']);
        Route::post('planete/achats', [PlaneteController::class, 'acheter']);
        Route::post('animaux/{animal}/deces', [PlaneteController::class, 'declarerDeces']);
        Route::delete('animaux/{animal}/deces', [PlaneteController::class, 'annulerDeces']);
        Route::put('coordonnees', [CoordonneesProprietaireController::class, 'modifier']);
        Route::get('commandes', [BoutiqueProprietaireController::class, 'commandes']);
        Route::post('commandes', [BoutiqueProprietaireController::class, 'passer']);
        Route::post('devis/{devis}/signer', [DocumentProprietaireController::class, 'signerDevis']);
        Route::post('ordonnances/{ordonnance}/renouvellement', [DocumentProprietaireController::class, 'demanderRenouvellement']);
        Route::post('demandes', [CliniqueProprietaireController::class, 'deposer']);
        Route::post('email/envoyer', [VerificationEmailController::class, 'envoyer'])->middleware('throttle:auth');
        Route::post('email/verifier', [VerificationEmailController::class, 'verifier'])->middleware('throttle:auth');
    });

    // Toute l'application clinique exige une organisation vérifiée et
    // payée (`organisation.ouverte`), sauf la facturation, pour payer.
    Route::middleware(['auth:sanctum', 'clinique', 'organisation.ouverte'])->group(function (): void {
        // Annuaire des cliniques de l'organisation (id, nom, boxes) :
        // tout employé — le domaine organisation garde la gestion.
        Route::get('cliniques', AnnuaireCliniqueController::class);

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

        // Site web public de l'organisation du jeton (éditeur) —
        // domaine organisation, contrôlé par la Policy.
        Route::get('site', [SiteWebController::class, 'afficher']);
        Route::patch('site', [SiteWebController::class, 'modifier']);
        Route::post('site/logo', [SiteLogoController::class, 'televerser']);
        Route::delete('site/logo', [SiteLogoController::class, 'supprimer']);
        Route::put('site/domaine', [SiteDomaineController::class, 'connecter']);
        Route::post('site/domaine/verifier', [SiteDomaineController::class, 'verifier']);
        Route::delete('site/domaine', [SiteDomaineController::class, 'retirer']);
        Route::post('site/equipe/{employe}/photo', [PhotoEmployeController::class, 'televerser'])->whereNumber('employe');
        Route::delete('site/equipe/{employe}/photo', [PhotoEmployeController::class, 'supprimer'])->whereNumber('employe');

        // Annuaire de la clinique pour la grille (identité, ligne,
        // poste) — domaine planning, sans les données RH d'/equipe.
        Route::get('planning/employes', AnnuaireEmployeController::class);

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

        // Demandes de rattachement (un client réclame un animal par sa
        // puce depuis l'espace) : avant la ressource, sinon
        // `patients/{patient}` capterait `patients/rattachements`.
        Route::get('patients/rattachements', [DemandeRattachementController::class, 'index']);
        Route::post('patients/rattachements/{demande}/accepter', [DemandeRattachementController::class, 'accepter']);
        Route::post('patients/rattachements/{demande}/refuser', [DemandeRattachementController::class, 'refuser']);

        Route::apiResource('patients', PatientController::class)->except('destroy');

        // Carnet de santé : puce (15 chiffres, format AMICUS) et
        // déclaration AMICUS (chiens pucés uniquement).
        Route::put('patients/{patient}/puce', [CarnetSanteController::class, 'modifierPuce']);
        Route::post('patients/{patient}/photo', [PhotoPatientController::class, 'televerser']);
        Route::delete('patients/{patient}/photo', [PhotoPatientController::class, 'supprimer']);
        Route::post('patients/{patient}/amicus', [CarnetSanteController::class, 'declarerAmicus']);

        Route::post('patients/{patient}/pesees', [PeseeController::class, 'store']);
        Route::patch('patients/{patient}/pesees/{pesee}', [PeseeController::class, 'update']);
        Route::delete('patients/{patient}/pesees/{pesee}', [PeseeController::class, 'destroy']);

        Route::get('patients/{patient}/vaccinations', [VaccinationController::class, 'index']);
        Route::post('patients/{patient}/vaccinations', [VaccinationController::class, 'store']);
        Route::put('patients/{patient}/vaccinations/{vaccination}', [VaccinationController::class, 'update']);
        Route::delete('patients/{patient}/vaccinations/{vaccination}', [VaccinationController::class, 'destroy']);

        // Catalogue des vaccins autorisés en Suisse (Swissmedic, GTIN Refdata)
        Route::get('vaccins', [VaccinCatalogueController::class, 'index']);
        Route::get('vaccins/gtin/{gtin}', [VaccinCatalogueController::class, 'gtin']);

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

        // Transcription des consultations (19.10.2026) — l'audio arrive
        // après la consultation, par morceaux ; il est transcrit, effacé,
        // puis un brouillon SOAP est rédigé pour le compte rendu ci-dessus,
        // que le vétérinaire valide. Niveau organisation, comme lui.
        Route::get('transcription', [TranscriptionController::class, 'etat']);
        Route::get('rendez-vous/{rendezVous}/enregistrement', [EnregistrementConsultationController::class, 'afficher']);
        Route::post('rendez-vous/{rendezVous}/enregistrement', [EnregistrementConsultationController::class, 'demarrer']);
        Route::post('enregistrements/{enregistrement}/parties/{numero}', [PartieEnregistrementController::class, 'morceau'])->whereNumber(['enregistrement', 'numero']);
        Route::post('enregistrements/{enregistrement}/terminer', [EnregistrementConsultationController::class, 'terminer'])->whereNumber('enregistrement');
        Route::post('enregistrements/{enregistrement}/relancer', [EnregistrementConsultationController::class, 'relancer'])->whereNumber('enregistrement');
        Route::delete('enregistrements/{enregistrement}', [EnregistrementConsultationController::class, 'supprimer'])->whereNumber('enregistrement');

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
            Route::patch('factures/{facture}/lignes/{ligne}', [FactureLigneController::class, 'update'])->whereNumber('ligne');
            Route::delete('factures/{facture}/lignes/{ligne}', [FactureLigneController::class, 'destroy'])->whereNumber('ligne');
            Route::post('factures/{facture}/prescriptions', [FacturePrescriptionController::class, 'store']);
            Route::post('factures/{facture}/encaisser', [EncaissementController::class, 'encaisser']);
            Route::post('factures/{facture}/payer-plus-tard', [EncaissementController::class, 'payerPlusTard']);

            // Paiement par Stripe : carte sur un terminal de la clinique, ou TWINT
            Route::post('factures/{facture}/paiements', [PaiementCaisseController::class, 'store']);
            Route::get('paiements/{paiement}', [PaiementCaisseController::class, 'show']);
            Route::post('paiements/{paiement}/annuler', [PaiementCaisseController::class, 'annuler']);
            Route::post('paiements/{paiement}/simuler-carte', [PaiementCaisseController::class, 'simulerCarte']);
            Route::get('terminaux', [TerminalPaiementController::class, 'index']);
            Route::post('terminaux', [TerminalPaiementController::class, 'store']);
            Route::delete('terminaux/{terminal}', [TerminalPaiementController::class, 'destroy']);
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
            // Ouvertes à une organisation fermée : l'état de l'abonnement,
            // la formule, la carte et les factures, de quoi payer.
            Route::withoutMiddleware('organisation.ouverte')->group(function (): void {
                Route::get('/', [OrganisationController::class, 'afficher']);
                Route::get('abonnement/apercu', [AbonnementController::class, 'apercu']);
                Route::post('abonnement', [AbonnementController::class, 'changer']);
                Route::post('moyen-paiement/intention', [MoyenPaiementController::class, 'intention']);
                Route::put('moyen-paiement', [MoyenPaiementController::class, 'enregistrer']);
                Route::delete('moyen-paiement', [MoyenPaiementController::class, 'supprimer']);
                Route::get('factures', [OrganisationFactureController::class, 'index']);
                Route::get('factures/{facture}', [OrganisationFactureController::class, 'show'])->whereNumber('facture');
                Route::post('factures/{facture}/regler', [OrganisationFactureController::class, 'regler'])->whereNumber('facture');
                Route::post('factures/{facture}/confirmer', [OrganisationFactureController::class, 'confirmer'])->whereNumber('facture');
            });

            Route::get('societes', [SocieteController::class, 'index']);
            Route::post('societes', [SocieteController::class, 'store']);

            Route::get('cliniques', [OrganisationCliniqueController::class, 'index']);
            Route::post('cliniques', [OrganisationCliniqueController::class, 'store']);
            Route::patch('cliniques/{clinique}', [OrganisationCliniqueController::class, 'update'])->whereNumber('clinique');
            Route::delete('cliniques/{clinique}', [OrganisationCliniqueController::class, 'destroy'])->whereNumber('clinique');
            // Horaires d'une clinique de l'organisation (créée ou
            // sœur) — /horaires ne vise que la clinique du jeton.
            Route::get('cliniques/{clinique}/horaires', [OrganisationHoraireController::class, 'afficher'])->whereNumber('clinique');
            Route::put('cliniques/{clinique}/horaires', [OrganisationHoraireController::class, 'modifier'])->whereNumber('clinique');

            Route::post('abonnement/resilier', [AbonnementController::class, 'resilier']);
            Route::post('abonnement/reactiver', [AbonnementController::class, 'reactiver']);
            Route::post('packs', [PackQuotaController::class, 'acheter']);
            Route::get('transcription', [OrganisationTranscriptionController::class, 'afficher']);
            Route::put('transcription', [OrganisationTranscriptionController::class, 'modifier']);
            // Catalogue d'actes : un acte, un prix (lecture : facturation/prestations)
            Route::post('prestations', [OrganisationPrestationController::class, 'store']);
            Route::patch('prestations/{prestation}', [OrganisationPrestationController::class, 'update'])->whereNumber('prestation');
            Route::delete('prestations/{prestation}', [OrganisationPrestationController::class, 'destroy'])->whereNumber('prestation');
            // Premiers pas d'une clinique neuve : étapes et confirmations
            Route::get('premiers-pas', [PremiersPasController::class, 'afficher']);
            Route::put('premiers-pas', [PremiersPasController::class, 'modifier']);
        });

        // Domaine équipe — fiches RH, une par personne. La liste est celle
        // de la clinique du jeton (écran Équipe) ; les gestes (fiche,
        // embauche, cliniques, suspension, licenciement) sont bornés à
        // l'ORGANISATION :
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
            Route::put('employes/{employe}/cliniques', AffectationsEmployeController::class)->whereNumber('employe');
            // Embauche : à qui appartient le code employé donné par la personne
            Route::get('comptes/{code}', CompteEmployeController::class)->middleware('throttle:20,1');

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
            // Commandes résolues sans binding : toutes cliniques de
            // l'organisation (livraison à une clinique sœur possible).
            Route::post('commandes/{commande}/envoyer', [CommandeFournisseurController::class, 'envoyer'])->whereNumber('commande');
            Route::post('commandes/{commande}/receptionner', [CommandeFournisseurController::class, 'receptionner'])->whereNumber('commande');
            Route::delete('commandes/{commande}', [CommandeFournisseurController::class, 'destroy'])->whereNumber('commande');
            // Lignes d'un brouillon, avant envoi.
            Route::post('commandes/{commande}/lignes', [CommandeFournisseurLigneController::class, 'store'])->whereNumber('commande');
            Route::patch('commandes/{commande}/lignes/{ligne}', [CommandeFournisseurLigneController::class, 'update'])->whereNumber('commande')->whereNumber('ligne');
            Route::delete('commandes/{commande}/lignes/{ligne}', [CommandeFournisseurLigneController::class, 'destroy'])->whereNumber('commande')->whereNumber('ligne');

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
