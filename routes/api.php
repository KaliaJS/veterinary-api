<?php

use App\Http\Controllers\Api\V1\AbonnementController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\AccesEmployeController;
use App\Http\Controllers\Api\V1\AccordSiteController;
use App\Http\Controllers\Api\V1\AffectationsEmployeController;
use App\Http\Controllers\Api\V1\AjustementStockController;
use App\Http\Controllers\Api\V1\AlertesController;
use App\Http\Controllers\Api\V1\AnalyseController;
use App\Http\Controllers\Api\V1\AnalysePatientController;
use App\Http\Controllers\Api\V1\AnnotationRadioController;
use App\Http\Controllers\Api\V1\AnnuaireCliniqueController;
use App\Http\Controllers\Api\V1\AnnuaireEmployeController;
use App\Http\Controllers\Api\V1\AppareilNotificationController;
use App\Http\Controllers\Api\V1\AppStoreNotificationsController;
use App\Http\Controllers\Api\V1\Auth\ChangementCliniqueController;
use App\Http\Controllers\Api\V1\Auth\ChoixCliniqueController;
use App\Http\Controllers\Api\V1\Auth\CodeEmailController;
use App\Http\Controllers\Api\V1\Auth\PasskeyConnexionController;
use App\Http\Controllers\Api\V1\Auth\PasskeyController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\BlocOccupancyController;
use App\Http\Controllers\Api\V1\BoutiquePaiementController;
use App\Http\Controllers\Api\V1\CarnetSanteController;
use App\Http\Controllers\Api\V1\CashStatisticsController;
use App\Http\Controllers\Api\V1\CatalogueSearchController;
use App\Http\Controllers\Api\V1\ChecklistInterventionController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientHistoryController;
use App\Http\Controllers\Api\V1\ClientSpaceInvitationController;
use App\Http\Controllers\Api\V1\ClotureCaisseController;
use App\Http\Controllers\Api\V1\CommandeBoutiqueController;
use App\Http\Controllers\Api\V1\CommandeFournisseurController;
use App\Http\Controllers\Api\V1\CommandeFournisseurLigneController;
use App\Http\Controllers\Api\V1\CompteClientController;
use App\Http\Controllers\Api\V1\CompteEmployeController;
use App\Http\Controllers\Api\V1\ComptePaiementController;
use App\Http\Controllers\Api\V1\CompteRenduController;
use App\Http\Controllers\Api\V1\CompteRenduImagerieController;
use App\Http\Controllers\Api\V1\ConfirmationRadioController;
use App\Http\Controllers\Api\V1\CongesEquipeController;
use App\Http\Controllers\Api\V1\ConsentementInterventionController;
use App\Http\Controllers\Api\V1\ConsentRequestController;
use App\Http\Controllers\Api\V1\ConsentWithoutSignatureController;
use App\Http\Controllers\Api\V1\ConstanteSejourController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CycleAnalyseController;
use App\Http\Controllers\Api\V1\CycleDevisController;
use App\Http\Controllers\Api\V1\CycleInterventionController;
use App\Http\Controllers\Api\V1\CycleOrdonnanceController;
use App\Http\Controllers\Api\V1\DailyReportController;
use App\Http\Controllers\Api\V1\DecompteAssuranceController;
use App\Http\Controllers\Api\V1\DemandeCongeController;
use App\Http\Controllers\Api\V1\DemandeRattachementController;
use App\Http\Controllers\Api\V1\DemandeRendezVousController;
use App\Http\Controllers\Api\V1\DevisController;
use App\Http\Controllers\Api\V1\DischargeLetterController;
use App\Http\Controllers\Api\V1\DisponibiliteController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DomainePublicController;
use App\Http\Controllers\Api\V1\EcrituresComptablesController;
use App\Http\Controllers\Api\V1\EncaissementController;
use App\Http\Controllers\Api\V1\EnregistrementConsultationController;
use App\Http\Controllers\Api\V1\EntrainementImagerieController;
use App\Http\Controllers\Api\V1\EquipeEmployeController;
use App\Http\Controllers\Api\V1\Espace\AbonnementProprietaireController;
use App\Http\Controllers\Api\V1\Espace\AccueilController;
use App\Http\Controllers\Api\V1\Espace\AnimalController;
use App\Http\Controllers\Api\V1\Espace\AppStoreController;
use App\Http\Controllers\Api\V1\Espace\BoutiqueProprietaireController;
use App\Http\Controllers\Api\V1\Espace\CliniqueProprietaireController;
use App\Http\Controllers\Api\V1\Espace\ConfigurationProprietaireController;
use App\Http\Controllers\Api\V1\Espace\CoordonneesProprietaireController;
use App\Http\Controllers\Api\V1\Espace\DemandeProprietaireController;
use App\Http\Controllers\Api\V1\Espace\DemandePubliqueController;
use App\Http\Controllers\Api\V1\Espace\DocumentProprietaireController;
use App\Http\Controllers\Api\V1\Espace\MessageProprietaireController;
use App\Http\Controllers\Api\V1\Espace\OwnerConsentController;
use App\Http\Controllers\Api\V1\Espace\OwnerInvitationController;
use App\Http\Controllers\Api\V1\Espace\PlaneteController;
use App\Http\Controllers\Api\V1\Espace\ReglagesProprietaireController;
use App\Http\Controllers\Api\V1\Espace\RendezVousProprietaireController;
use App\Http\Controllers\Api\V1\Espace\SuppressionCompteController;
use App\Http\Controllers\Api\V1\Espace\VerificationEmailController;
use App\Http\Controllers\Api\V1\Espace\VeterinairePublicController;
use App\Http\Controllers\Api\V1\ExamenImagerieController;
use App\Http\Controllers\Api\V1\ExploitationController;
use App\Http\Controllers\Api\V1\FactureController;
use App\Http\Controllers\Api\V1\FactureDevisController;
use App\Http\Controllers\Api\V1\FactureLigneController;
use App\Http\Controllers\Api\V1\FacturePrescriptionController;
use App\Http\Controllers\Api\V1\FacturesOuvertesController;
use App\Http\Controllers\Api\V1\FinConsultationController;
use App\Http\Controllers\Api\V1\FinTeleconsultationController;
use App\Http\Controllers\Api\V1\GardeController;
use App\Http\Controllers\Api\V1\HoraireOuvertureController;
use App\Http\Controllers\Api\V1\IdentiteController;
use App\Http\Controllers\Api\V1\ImageriePatientController;
use App\Http\Controllers\Api\V1\InscriptionCliniqueController;
use App\Http\Controllers\Api\V1\InterventionController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\InvitationEquipeController;
use App\Http\Controllers\Api\V1\Isabv\AntibioticDeclarationController;
use App\Http\Controllers\Api\V1\Isabv\IsabvConnectionController;
use App\Http\Controllers\Api\V1\Isabv\IsabvReferenceController;
use App\Http\Controllers\Api\V1\Isabv\ProductPreparationController;
use App\Http\Controllers\Api\V1\JournalFacturesController;
use App\Http\Controllers\Api\V1\JoursFeriesController;
use App\Http\Controllers\Api\V1\LabSummaryController;
use App\Http\Controllers\Api\V1\LicenciementEmployeController;
use App\Http\Controllers\Api\V1\LignePlanningController;
use App\Http\Controllers\Api\V1\ListeAttenteController;
use App\Http\Controllers\Api\V1\LocalityController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\MessageAttachmentController;
use App\Http\Controllers\Api\V1\MessageMediaController;
use App\Http\Controllers\Api\V1\MessagerieCliniqueController;
use App\Http\Controllers\Api\V1\MouvementStockController;
use App\Http\Controllers\Api\V1\MouvementStupefiantController;
use App\Http\Controllers\Api\V1\MoyenPaiementController;
use App\Http\Controllers\Api\V1\NeuroExamController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\OpenedUnitController;
use App\Http\Controllers\Api\V1\OrdonnanceController;
use App\Http\Controllers\Api\V1\OrganisationCliniqueController;
use App\Http\Controllers\Api\V1\OrganisationController;
use App\Http\Controllers\Api\V1\OrganisationDiagnosticController;
use App\Http\Controllers\Api\V1\OrganisationFactureController;
use App\Http\Controllers\Api\V1\OrganisationHoraireController;
use App\Http\Controllers\Api\V1\OrganisationPrestationController;
use App\Http\Controllers\Api\V1\OrganisationRoleController;
use App\Http\Controllers\Api\V1\OrganisationServicesCliniqueController;
use App\Http\Controllers\Api\V1\OrganisationTranscriptionController;
use App\Http\Controllers\Api\V1\PackQuotaController;
use App\Http\Controllers\Api\V1\PaiementCaisseController;
use App\Http\Controllers\Api\V1\PanelLaboController;
use App\Http\Controllers\Api\V1\PartieEnregistrementController;
use App\Http\Controllers\Api\V1\PatientCareController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PatientTimelineController;
use App\Http\Controllers\Api\V1\PeseeController;
use App\Http\Controllers\Api\V1\PhotoCliniqueController;
use App\Http\Controllers\Api\V1\PhotoEmployeController;
use App\Http\Controllers\Api\V1\PhotoPatientController;
use App\Http\Controllers\Api\V1\PlanningEquipeController;
use App\Http\Controllers\Api\V1\PlanningOccupancyController;
use App\Http\Controllers\Api\V1\Plateforme\FonctionEssaiPlateformeController;
use App\Http\Controllers\Api\V1\Plateforme\InvitationAccesPlateformeController;
use App\Http\Controllers\Api\V1\Plateforme\OrganisationPlateformeController;
use App\Http\Controllers\Api\V1\Plateforme\VeterinairePlateformeController;
use App\Http\Controllers\Api\V1\PremiersPasController;
use App\Http\Controllers\Api\V1\PrestationController;
use App\Http\Controllers\Api\V1\ProchainCreneauController;
use App\Http\Controllers\Api\V1\ProductContentController;
use App\Http\Controllers\Api\V1\ProductPhotoController;
use App\Http\Controllers\Api\V1\ProductVatRateController;
use App\Http\Controllers\Api\V1\ProduitController;
use App\Http\Controllers\Api\V1\ProfilVeterinaireController;
use App\Http\Controllers\Api\V1\PublicConsentController;
use App\Http\Controllers\Api\V1\PublicRecordShareController;
use App\Http\Controllers\Api\V1\Purchasing\AutoBasketController;
use App\Http\Controllers\Api\V1\Purchasing\ProductSourcesController;
use App\Http\Controllers\Api\V1\Purchasing\SupplierController;
use App\Http\Controllers\Api\V1\RadioController;
use App\Http\Controllers\Api\V1\RadioDossierController;
use App\Http\Controllers\Api\V1\RadioPreviewController;
use App\Http\Controllers\Api\V1\RappelsController;
use App\Http\Controllers\Api\V1\RecordImportController;
use App\Http\Controllers\Api\V1\RecordShareController;
use App\Http\Controllers\Api\V1\ReferentielImagerieController;
use App\Http\Controllers\Api\V1\ReferralInvitationController;
use App\Http\Controllers\Api\V1\ReferralLetterController;
use App\Http\Controllers\Api\V1\ReferralReturnController;
use App\Http\Controllers\Api\V1\ReferringVetController;
use App\Http\Controllers\Api\V1\RegistreStupefiantController;
use App\Http\Controllers\Api\V1\ReleveDentaireController;
use App\Http\Controllers\Api\V1\ReleveOphtalmologiqueController;
use App\Http\Controllers\Api\V1\RendezVousAuditController;
use App\Http\Controllers\Api\V1\RendezVousController;
use App\Http\Controllers\Api\V1\RendezVousPlacementController;
use App\Http\Controllers\Api\V1\RendezVousStatutController;
use App\Http\Controllers\Api\V1\RessourcesBlocController;
use App\Http\Controllers\Api\V1\ResultatAnalyseController;
use App\Http\Controllers\Api\V1\SalleController;
use App\Http\Controllers\Api\V1\SejourController;
use App\Http\Controllers\Api\V1\SemainePlanningController;
use App\Http\Controllers\Api\V1\SessionTeleconsultationController;
use App\Http\Controllers\Api\V1\SiteDomaineController;
use App\Http\Controllers\Api\V1\SiteLogoController;
use App\Http\Controllers\Api\V1\SitePublicController;
use App\Http\Controllers\Api\V1\SiteShopController;
use App\Http\Controllers\Api\V1\SiteVideosController;
use App\Http\Controllers\Api\V1\SiteWebController;
use App\Http\Controllers\Api\V1\SocieteController;
use App\Http\Controllers\Api\V1\SoinSejourController;
use App\Http\Controllers\Api\V1\SortieSejourController;
use App\Http\Controllers\Api\V1\StaffPreferenceController;
use App\Http\Controllers\Api\V1\StatistiquesBoutiqueController;
use App\Http\Controllers\Api\V1\StatistiquesCaisseController;
use App\Http\Controllers\Api\V1\StayNoteController;
use App\Http\Controllers\Api\V1\StayRoundController;
use App\Http\Controllers\Api\V1\StayTreatmentController;
use App\Http\Controllers\Api\V1\Stocks\StockWatchController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\SubstanceStupefiantController;
use App\Http\Controllers\Api\V1\SuggestionDiagnostiqueController;
use App\Http\Controllers\Api\V1\SurgeryReportController;
use App\Http\Controllers\Api\V1\TerminalPaiementController;
use App\Http\Controllers\Api\V1\TrameImagerieController;
use App\Http\Controllers\Api\V1\TranscriptionController;
use App\Http\Controllers\Api\V1\TransfertAppelController;
use App\Http\Controllers\Api\V1\UnfinishedWorkController;
use App\Http\Controllers\Api\V1\UrgenceAttenteController;
use App\Http\Controllers\Api\V1\VaccinationController;
use App\Http\Controllers\Api\V1\VaccinCatalogueController;
use App\Http\Controllers\Api\V1\VenteComptoirController;
use App\Http\Controllers\Api\V1\VerificationOrdonnanceController;
use App\Http\Controllers\Api\V1\Vetpoint\CommandeVetpointController;
use App\Http\Controllers\Api\V1\Vetpoint\ConnexionVetpointController;
use App\Http\Controllers\Api\V1\Vetpoint\ProduitVetpointController;
use App\Http\Controllers\Api\V1\Vetpoint\RechercheVetpointController;
use App\Http\Controllers\Api\V1\Videos\ClinicVideoController;
use App\Http\Controllers\Api\V1\Videos\ClinicVideoUploadController;
use App\Http\Controllers\Api\V1\Videos\PersonalVideoController;
use App\Http\Controllers\Api\V1\Videos\PersonalVideoUploadController;
use App\Http\Controllers\Api\V1\Videos\SellerAccountController;
use App\Http\Controllers\Api\V1\Videos\VideoCatalogueController;
use App\Http\Controllers\Api\V1\Videos\VideoLocalFileController;
use App\Http\Controllers\Api\V1\Videos\VideoPosterController;
use App\Http\Controllers\Api\V1\Videos\VideoPurchaseController;
use App\Http\Controllers\Api\V1\Videos\VideoViewingController;
use App\Http\Controllers\Api\V1\VisaStupefiantController;
use App\Http\Controllers\Api\V1\VisioController;
use App\Http\Controllers\Api\V1\VisioPubliqueController;
use App\Http\Controllers\Api\V1\VisiteController;
use App\Http\Controllers\Api\V1\VitrineController;
use App\Services\Videos\VideoPosters;
use Illuminate\Support\Facades\Route;

/*
 * API v1 — jetons Sanctum uniquement (jamais de session SPA).
 * Les routes authentifiées passent toutes par le middleware `clinique`
 * qui applique le scope multi-clinique du jeton, globalement.
 */
Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        // Plus de « adresse connue ? » (24.10.2026) : la connexion ne dit
        // jamais si une adresse a un compte
        Route::post('code-email/envoyer', [CodeEmailController::class, 'envoyer'])->middleware('throttle:code-email');
        Route::post('code-email/verifier', [CodeEmailController::class, 'verifier']);
        Route::post('passkey/options', [PasskeyConnexionController::class, 'options']);
        Route::post('passkey/verifier', [PasskeyConnexionController::class, 'verifier']);
        // Compte multi-clinique : le jeton de choix et la clinique choisie
        Route::post('choix-clinique', ChoixCliniqueController::class);
        // Pas d'inscription par passkey (24.10.2026) : un compte naît du
        // code e-mail, qui prouve l'adresse ; la passkey s'ajoute ensuite,
        // connecté (`passkeys/options`, `passkeys`).
    });

    // La session elle-même : ouverte aussi aux jetons sans clinique
    // (comptes inscrits, sans rattachement) — `clinique:facultatif`.
    Route::prefix('auth')->middleware(['auth:sanctum', 'clinique:facultatif'])->group(function (): void {
        Route::get('moi', [SessionController::class, 'moi']);
        Route::post('rafraichir', [SessionController::class, 'rafraichir']);
        // Jeton de l'Apple Watch, demandé par l'app iPhone
        Route::post('montre', [SessionController::class, 'montre'])->middleware('throttle:10,1');
        // La montre (ou l'iPhone) donne son jeton d'Apple pour être réveillée (28.10.2026)
        Route::post('appareil', [AppareilNotificationController::class, 'enregistrer'])->middleware('throttle:20,1');
        // Notifications du téléphone : oubli d'un appareil à la déconnexion, réglages de chacun (02.11.2026)
        Route::delete('appareil', [NotificationSettingsController::class, 'forgetDevice'])->middleware('throttle:20,1');
        Route::get('notifications', [NotificationSettingsController::class, 'show']);
        Route::put('notifications', [NotificationSettingsController::class, 'update'])->middleware('throttle:30,1');
        Route::post('clinique', ChangementCliniqueController::class);
        Route::post('deconnexion', [SessionController::class, 'deconnexion']);
        // Profil vétérinaire du compte : GLN (cherché dans MedReg) et spécialités
        Route::get('profil-veterinaire', [ProfilVeterinaireController::class, 'afficher']);
        Route::put('profil-veterinaire', [ProfilVeterinaireController::class, 'modifier'])->middleware('throttle:20,1');
        // Vérification d'identité (Stripe Identity) : badge et inscription d'une clinique
        Route::get('identite', [IdentiteController::class, 'afficher']);
        Route::post('identite/paiement', [IdentiteController::class, 'payer'])->middleware('throttle:5,1');
        Route::post('identite/session', [IdentiteController::class, 'commencer'])->middleware('throttle:10,1');
        // Paraître sur le site public de son organisation : son accord (nLPD)
        Route::put('accord-site', AccordSiteController::class)->middleware('throttle:20,1');
        Route::put('profil-veterinaire/fiche', [ProfilVeterinaireController::class, 'modifierFiche']);
        Route::put('profil-veterinaire/annuaire', [ProfilVeterinaireController::class, 'annuaire']);

        Route::get('passkeys', [PasskeyController::class, 'index']);
        Route::post('passkeys/options', [PasskeyController::class, 'options']);
        Route::post('passkeys', [PasskeyController::class, 'enregistrer']);
        Route::delete('passkeys/{passkey}', [PasskeyController::class, 'supprimer']);
        // Code employé : un nouveau, si l'ancien a trop circulé
        Route::post('code-employe', [SessionController::class, 'nouveauCodeEmploye'])->middleware('throttle:5,1');
    });

    // Espace Plateforme : réservé aux modérateurs de Veterinary (rôle donné
    // par la commande plateforme:moderateur), hors contexte clinique.
    Route::prefix('plateforme')->middleware(['auth:sanctum', 'moderateur'])->group(function (): void {
        Route::get('veterinaires', [VeterinairePlateformeController::class, 'index']);
        Route::get('veterinaires/{user}', [VeterinairePlateformeController::class, 'show'])->whereNumber('user');
        Route::post('veterinaires/{user}/decision', [VeterinairePlateformeController::class, 'decider'])->whereNumber('user');
        Route::get('organisations', [OrganisationPlateformeController::class, 'index']);
        Route::post('organisations/{organisation}/valider', [OrganisationPlateformeController::class, 'valider'])->whereNumber('organisation');
        Route::post('organisations/{organisation}/refuser', [OrganisationPlateformeController::class, 'refuser'])->whereNumber('organisation');
        // Accès sur invitation (27.10.2026)
        Route::get('invitations', [InvitationAccesPlateformeController::class, 'index']);
        Route::post('invitations', [InvitationAccesPlateformeController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('invitations/{invitation}', [InvitationAccesPlateformeController::class, 'destroy'])->whereNumber('invitation');
        // Fonctions en essai (Laravel Pennant, 27.10.2026)
        Route::get('fonctions', [FonctionEssaiPlateformeController::class, 'index']);
        Route::post('fonctions/{nom}/activer', [FonctionEssaiPlateformeController::class, 'activer']);
        Route::post('fonctions/{nom}/retirer', [FonctionEssaiPlateformeController::class, 'retirer']);
    });

    // Invitation à rejoindre une équipe : tout compte connecté, avec ou
    // sans clinique ; seule l'adresse invitée peut accepter.
    Route::prefix('invitations')->middleware(['auth:sanctum', 'throttle:30,1'])->group(function (): void {
        Route::get('{jeton}', [InvitationController::class, 'afficher']);
        Route::post('{jeton}/accepter', [InvitationController::class, 'accepter']);
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
    // jeton ; commander exige une session, déposer une demande aussi sauf
    // chez une clinique qui accepte les rendez-vous sans compte.
    Route::prefix('public')->middleware('throttle:60,1')->group(function (): void {
        // Vidéos de formation sur le disque local (développement, tests) : ce
        // que font les adresses signées du bucket en production (31.10.2026)
        // Un seul appel à middleware() : un second remplacerait le premier, et la signature sauterait
        Route::prefix('videos/{video}/files/{name}')->whereUuid('video')
            ->withoutMiddleware('throttle:60,1')->middleware(['signed', 'throttle:600,1'])->group(function (): void {
                Route::get('/', [VideoLocalFileController::class, 'show'])->name('videos.local-file');
                Route::put('/', [VideoLocalFileController::class, 'store'])->name('videos.local-upload');
            });
        // Lecture des vidéos : listes HLS signées (Safari les lit sans jeton), et
        // vérification publique d'un certificat de visionnage (31.10.2026)
        Route::prefix('videos/{video}/hls')->whereUuid('video')
            ->withoutMiddleware('throttle:60,1')->middleware(['signed', 'throttle:300,1'])->group(function (): void {
                Route::get('master.m3u8', [VideoViewingController::class, 'master'])->name('videos.hls.master');
                Route::get('{name}', [VideoViewingController::class, 'media'])->name('videos.hls.media');
            });
        Route::get('certificates/{code}', [VideoViewingController::class, 'verify'])->middleware('throttle:30,1');
        // Le lien de partage du dossier (09.11.2026), sans compte : la page, chaque pièce et ses fichiers, « Tout télécharger » compté
        Route::prefix('record-shares/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->withoutMiddleware('throttle:60,1')->middleware('throttle:240,1,record-share-public')->group(function (): void {
            Route::get('/', [PublicRecordShareController::class, 'show']);
            Route::get('items/{type}/{id}', [PublicRecordShareController::class, 'item'])->whereNumber('id');
            Route::get('items/{type}/{id}/files/{n}', [PublicRecordShareController::class, 'file'])->whereNumber(['id', 'n']);
            // Sa propre limite seulement : composé juste avant, le compte ne tombe pas sur celle de la page (4e vérification)
            Route::post('downloads', [PublicRecordShareController::class, 'download'])->withoutMiddleware('throttle:240,1,record-share-public')->middleware('throttle:10,1,record-share-download');
        });
        // Vérifier l'authenticité d'une ordonnance : numéro et code imprimés, ou le code QR (01.11.2026)
        Route::get('ordonnances/verifier', VerificationOrdonnanceController::class)->middleware('throttle:20,1,verification-ordonnance');
        // L'écran « Vidéos » sans compte, depuis l'accueil du site (31.10.2026, demande de Jim) : les vidéos
        // des clients et l'aperçu qui se lance tout seul ; regarder, acheter et le reste demandent un compte.
        // Leurs compteurs à part : parcourir les vidéos ne bloque ni la boutique ni l'annuaire
        Route::prefix('videos')->whereUuid('video')->withoutMiddleware('throttle:60,1')->middleware('throttle:120,1,public-videos')->group(function (): void {
            Route::get('/', [VideoCatalogueController::class, 'index']);
            Route::get('home', [VideoCatalogueController::class, 'home']);
            Route::get('{video}', [VideoCatalogueController::class, 'show']);
            Route::get('{video}/teaser', [VideoViewingController::class, 'teaser'])->withoutMiddleware('throttle:120,1,public-videos')->middleware('throttle:240,1,public-video-teaser');
        });
        // Affiches des vidéos : publiques, en cache, au dossier imprévisible (31.10.2026)
        Route::get('videos/posters/{token}/{width}.webp', [VideoPosterController::class, 'show'])->where(['token' => VideoPosters::TOKEN, 'width' => '640|1280|1920'])->name('videos.poster')->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
        Route::get('boutique', [BoutiqueProprietaireController::class, 'vitrine']);
        // Catalogue des formules et des packs : prix et limites, sans compte (28.10.2026)
        Route::get('offres', [OrganisationController::class, 'offres']);
        Route::post('boutique/apercu', [BoutiqueProprietaireController::class, 'apercu']);
        Route::get('cliniques', [CliniqueProprietaireController::class, 'index']);
        // Calendrier de la prise de rendez-vous : une semaine d'heures, libres ou non
        Route::get('cliniques/{clinique}/disponibilites', [CliniqueProprietaireController::class, 'disponibilites'])->whereNumber('clinique');
        // Fiche publique d'un vétérinaire vérifié, montré par une clinique de l'annuaire
        Route::get('veterinaires/{veterinaire}', [VeterinairePublicController::class, 'afficher'])->whereNumber('veterinaire');
        // Demande de rendez-vous sans compte, chez une clinique qui l'accepte
        // Compteurs à part (3e paramètre) : sinon ils partagent celui du groupe,
        // et quelques semaines du calendrier suffiraient à bloquer la demande
        // D'abord le code qui prouve l'adresse (captcha), puis la demande avec ce code
        // Formulaire de contact du site (27.10.2026) : Turnstile ; limité dans le
        // contrôleur, seulement pour un message accepté (28.10.2026)
        Route::post('contact', [ContactController::class, 'envoyer']);
        Route::post('demandes/code', [CliniqueProprietaireController::class, 'codeSansCompte'])->middleware(['throttle:5,1,demandes-code', 'throttle:code-email']);
        Route::post('demandes', [CliniqueProprietaireController::class, 'deposerSansCompte'])->middleware('throttle:5,1,demandes');
        // Son suivi par le lien des e-mails, et le choix d'un horaire proposé
        Route::get('demandes/{jeton}', [DemandePubliqueController::class, 'afficher'])->where('jeton', '[A-Za-z0-9]{48}');
        Route::post('demandes/{jeton}/choisir', [DemandePubliqueController::class, 'choisir'])->where('jeton', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,choix');
        // L'acompte payé : la réservation se confirme (aussi par le webhook de Stripe)
        Route::post('demandes/{jeton}/paiement', [DemandePubliqueController::class, 'paiement'])->where('jeton', '[A-Za-z0-9]{48}')->middleware('throttle:20,1,paiement');
        // Site web publié d'une organisation et de toutes ses cliniques.
        Route::get('sites/{slug}', [SitePublicController::class, 'afficher'])->where('slug', '[a-z0-9-]+');
        // Sa page « Boutique » (07.11.2026) : la vitrine de ce site seulement, son compteur à part
        // La page « Vidéos » du site : les vidéos de la clinique (11.11.2026)
        Route::get('sites/{slug}/videos', [SiteVideosController::class, 'index'])->where('slug', '[a-z0-9-]+')->withoutMiddleware('throttle:60,1')->middleware('throttle:120,1,public-videos');
        Route::get('sites/{slug}/boutique', [SiteShopController::class, 'index'])->where('slug', '[a-z0-9-]+')->withoutMiddleware('throttle:60,1')->middleware('throttle:60,1,site-boutique');
        // Un article seul, pas encore chargé (« Réserver », adresse partagée) : même compteur
        Route::get('sites/{slug}/boutique/articles/{article}', [SiteShopController::class, 'show'])->where('slug', '[a-z0-9-]+')->whereNumber('article')->withoutMiddleware('throttle:60,1')->middleware('throttle:60,1,site-boutique');
        // Domaines personnalisés : site d'un domaine actif ; autorisation de certificat (on-demand TLS)
        Route::get('domaines/{domaine}', [DomainePublicController::class, 'site'])->where('domaine', '[a-z0-9.-]+');
        Route::get('domaines-autorises', [DomainePublicController::class, 'autorise'])->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
        Route::get('photos/{fichier}', [PhotoEmployeController::class, 'afficher'])->where('fichier', '[A-Za-z0-9]+\\.[a-z]+')->name('employe.photo')->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
        // La photo de la vitrine d'un article (03.11.2026)
        Route::get('photos-produits/{fichier}', [ProductPhotoController::class, 'show'])->where('fichier', '[A-Za-z0-9]+\\.[a-z]+')->name('produit.photo')->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
        Route::get('photos-animaux/{fichier}', [PhotoPatientController::class, 'afficher'])->where('fichier', '[A-Za-z0-9]+\\.[a-z]+')->name('patient.photo')->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
        Route::get('photos-cliniques/{fichier}', [PhotoCliniqueController::class, 'afficher'])->where('fichier', '[A-Za-z0-9]+(?:-\\d+)?\\.[a-z]+')->name('clinique.photo')->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
        // Consentement à signer par le lien reçu (SMS, e-mail ; 02.11.2026)
        Route::get('consentements/{token}', [PublicConsentController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,consent-show');
        Route::post('consentements/{token}/signer', [PublicConsentController::class, 'sign'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,consent-sign');
        // Appel vidéo du client par le lien de son e-mail (WebRTC, 28.10.2026) ;
        // la mise en relation échange quelques dizaines de messages par appel
        Route::prefix('visio/{jeton}')->where(['jeton' => '[A-Za-z0-9]{48}'])->group(function (): void {
            Route::get('/', [VisioPubliqueController::class, 'afficher']);
            Route::get('ice', [VisioPubliqueController::class, 'ice'])->middleware('throttle:30,1,visio-ice');
            Route::post('canal', [VisioPubliqueController::class, 'canal']);
            Route::post('signal', [VisioPubliqueController::class, 'signal'])->withoutMiddleware('throttle:60,1')->middleware('throttle:300,1,visio-signal');
            Route::get('signaux/{reference}', [VisioPubliqueController::class, 'lireSignal'])->whereUuid('reference');
        });
        Route::get('sites/{slug}/logo', [SitePublicController::class, 'logo'])->where('slug', '[a-z0-9-]+')->name('site.logo')->withoutMiddleware('throttle:60,1')->middleware('throttle:fichiers');
    });

    // Espace propriétaire (portail client du proto) : tout compte
    // connecté, SANS contexte clinique — le middleware `proprietaire`
    // charge ses fiches client, toutes organisations, et chaque
    // requête filtre par ces fiches.
    Route::prefix('espace')->middleware(['auth:sanctum', 'proprietaire'])->group(function (): void {
        // Vidéos de formation (31.10.2026) : le catalogue pour tout compte,
        // et la vente en son nom pour un vétérinaire vérifié. Chaque limite a
        // son compteur (3e argument) : vingt aperçus ne bloquent jamais l'achat
        Route::prefix('videos')->group(function (): void {
            Route::get('/', [VideoCatalogueController::class, 'index']);
            // L'accueil de l'écran « Vidéos » : ses rangées (continuer, les siennes, nouveautés, une par catégorie)
            Route::get('home', [VideoCatalogueController::class, 'home']);
            Route::get('library', [VideoCatalogueController::class, 'library']);
            Route::get('seller/account', [SellerAccountController::class, 'show']);
            Route::post('seller/account', [SellerAccountController::class, 'store'])->middleware('throttle:20,1,video-seller-account');
            Route::get('seller/videos', [PersonalVideoController::class, 'index']);
            Route::post('seller/videos', [PersonalVideoController::class, 'store'])->middleware('throttle:30,1,video-create');
            Route::prefix('seller/videos/{video}')->whereUuid('video')->group(function (): void {
                Route::patch('/', [PersonalVideoController::class, 'update']);
                Route::delete('/', [PersonalVideoController::class, 'destroy']);
                Route::post('publish', [PersonalVideoController::class, 'publish']);
                Route::post('withdraw', [PersonalVideoController::class, 'withdraw']);
                Route::post('episodes/order', [PersonalVideoController::class, 'reorder']);
                Route::post('poster', [PersonalVideoController::class, 'poster'])->middleware('throttle:30,1,video-poster');
                Route::delete('poster', [PersonalVideoController::class, 'removePoster']);
                Route::post('upload/start', [PersonalVideoUploadController::class, 'start'])->middleware('throttle:30,1,video-upload-start');
                Route::post('upload/urls', [PersonalVideoUploadController::class, 'urls'])->middleware('throttle:600,1,video-upload-urls');
                Route::get('upload/files', [PersonalVideoUploadController::class, 'files']);
                Route::post('upload/finish', [PersonalVideoUploadController::class, 'finish'])->middleware('throttle:30,1,video-upload-finish');
            });
            Route::get('{video}', [VideoCatalogueController::class, 'show'])->whereUuid('video');
            Route::prefix('{video}')->whereUuid('video')->group(function (): void {
                Route::post('play', [VideoViewingController::class, 'play'])->middleware('throttle:60,1,video-play');
                Route::get('teaser', [VideoViewingController::class, 'teaser'])->middleware('throttle:120,1,video-teaser');
                Route::post('progress', [VideoViewingController::class, 'progress'])->middleware('throttle:60,1,video-progress');
                Route::get('download', [VideoViewingController::class, 'download'])->middleware('throttle:20,1,video-download');
                Route::post('certificate', [VideoViewingController::class, 'certificate'])->middleware('throttle:20,1,video-certificate');
                Route::post('purchase', [VideoPurchaseController::class, 'store'])->middleware('throttle:20,1,video-purchase');
                Route::post('purchase/confirm', [VideoPurchaseController::class, 'confirm'])->middleware('throttle:30,1,video-purchase-confirm');
            });
        });
        Route::get('accueil', AccueilController::class);
        // Le lien « Votre espace Veterinary » : la fiche rejoint l'espace, adresse invitée prouvée (03.11.2026)
        Route::post('invitations-fiche/accepter', [OwnerInvitationController::class, 'accept'])->middleware('throttle:30,1,invitation-fiche');
        Route::get('consentements', [OwnerConsentController::class, 'index']);
        Route::post('consentements/{consentRequest}/signer', [OwnerConsentController::class, 'sign'])->whereNumber('consentRequest')->middleware('throttle:10,1,consent-sign');
        Route::get('animaux', [AnimalController::class, 'index']);
        Route::post('animaux', [AnimalController::class, 'store']);
        Route::post('animaux/reclamer', [AnimalController::class, 'reclamer'])->middleware('throttle:espace-reclamer');
        Route::get('animaux/rattachements', [AnimalController::class, 'rattachements']);
        Route::get('animaux/{animal}', [AnimalController::class, 'show']);
        Route::put('animaux/{animal}', [AnimalController::class, 'update']);
        Route::put('animaux/{animal}/acces', [AnimalController::class, 'acces']);
        Route::get('rendez-vous', [RendezVousProprietaireController::class, 'index']);
        Route::post('rendez-vous/{rendezVous}/annuler', [RendezVousProprietaireController::class, 'annuler']);
        Route::post('rendez-vous/{rendezVous}/demander-annulation', [RendezVousProprietaireController::class, 'demanderAnnulation']);
        Route::post('rendez-vous/{rendezVous}/reporter', [RendezVousProprietaireController::class, 'reporter'])->middleware('throttle:espace-demandes');
        Route::get('cliniques', [CliniqueProprietaireController::class, 'index']);
        Route::get('documents', [DocumentProprietaireController::class, 'index']);
        // Un PDF que la clinique a partagé (28.10.2026)
        Route::get('documents/{document}/fichier', [DocumentProprietaireController::class, 'fichier'])->whereNumber('document');
        Route::get('boutique', [BoutiqueProprietaireController::class, 'vitrine']);
        Route::get('messages', [MessageProprietaireController::class, 'index']);
        Route::post('messages', [MessageProprietaireController::class, 'envoyer'])->middleware('throttle:espace-messages');
        // Messages audio et vidéo façon Telegram (07.11.2026) : envoyer, écouter
        Route::post('messages/media', [MessageProprietaireController::class, 'envoyerMedia'])->middleware('throttle:espace-messages');
        Route::get('messages/media/{media}', [MessageProprietaireController::class, 'media'])->whereNumber('media');
        Route::get('messages/media/{media}/apercu', [MessageProprietaireController::class, 'mediaPoster'])->whereNumber('media');
        // Photos et PDF (08.11.2026) : envoyer, ouvrir, l'aperçu d'une photo
        Route::post('messages/pieces', [MessageProprietaireController::class, 'sendAttachments'])->middleware('throttle:espace-messages');
        Route::get('messages/pieces/{attachment}', [MessageProprietaireController::class, 'attachment'])->whereNumber('attachment');
        Route::get('messages/pieces/{attachment}/apercu', [MessageProprietaireController::class, 'attachmentThumb'])->whereNumber('attachment');
        Route::get('coordonnees', [CoordonneesProprietaireController::class, 'afficher']);
        Route::get('preferences', [ReglagesProprietaireController::class, 'preferences']);
        // « Terminer la configuration » du propriétaire (28.10.2026)
        Route::get('configuration', [ConfigurationProprietaireController::class, 'afficher']);
        Route::put('configuration', [ConfigurationProprietaireController::class, 'modifier']);
        Route::put('preferences', [ReglagesProprietaireController::class, 'modifierPreferences']);
        Route::get('appareils', [ReglagesProprietaireController::class, 'appareils']);
        Route::post('appareils/deconnecter-autres', [ReglagesProprietaireController::class, 'deconnecterAutres']);
        Route::get('notifications', [ReglagesProprietaireController::class, 'notifications']);
        Route::post('notifications/lues', [ReglagesProprietaireController::class, 'marquerLues']);
        Route::get('export', [ReglagesProprietaireController::class, 'export']);
        // Suppression du compte (App Store, 27.10.2026) : code par e-mail, effacé 30 jours après
        Route::post('compte/suppression/code', [SuppressionCompteController::class, 'code'])->middleware('throttle:suppression-code');
        Route::post('compte/suppression', [SuppressionCompteController::class, 'demander']);
        // Offre du propriétaire : Gratuit, ou Plus (d'autres planètes)
        Route::get('abonnement', [AbonnementProprietaireController::class, 'afficher']);
        Route::post('abonnement/carte/intention', [AbonnementProprietaireController::class, 'intentionCarte'])->middleware('throttle:espace-achats');
        Route::put('abonnement/carte', [AbonnementProprietaireController::class, 'enregistrerCarte'])->middleware('throttle:espace-achats');
        Route::delete('abonnement/carte', [AbonnementProprietaireController::class, 'supprimerCarte'])->middleware('throttle:espace-achats');
        Route::post('abonnement/plus', [AbonnementProprietaireController::class, 'souscrire'])->middleware('throttle:espace-achats');
        Route::post('abonnement/resilier', [AbonnementProprietaireController::class, 'resilier'])->middleware('throttle:espace-achats');
        Route::post('abonnement/reactiver', [AbonnementProprietaireController::class, 'reactiver'])->middleware('throttle:espace-achats');
        Route::get('abonnement/factures/{facture}', [AbonnementProprietaireController::class, 'facture'])->whereNumber('facture');
        Route::post('abonnement/factures/{facture}/regler', [AbonnementProprietaireController::class, 'regler'])->whereNumber('facture')->middleware('throttle:espace-achats');
        Route::post('abonnement/factures/{facture}/confirmer', [AbonnementProprietaireController::class, 'confirmer'])->whereNumber('facture')->middleware('throttle:espace-achats');
        // Achats dans l'app iPhone (App Store, StoreKit 2)
        Route::get('app-store', [AppStoreController::class, 'afficher']);
        Route::post('app-store/transactions', [AppStoreController::class, 'transactions'])->middleware('throttle:espace-achats');
        // Jeu de la planète : boutique d'objets, décès d'un animal (tombe)
        Route::get('planete', [PlaneteController::class, 'afficher']);
        Route::post('planete/achats', [PlaneteController::class, 'acheter'])->middleware('throttle:espace-achats');
        Route::post('animaux/{animal}/deces', [PlaneteController::class, 'declarerDeces']);
        Route::delete('animaux/{animal}/deces', [PlaneteController::class, 'annulerDeces']);
        Route::put('coordonnees', [CoordonneesProprietaireController::class, 'modifier']);
        // Le mobile seul, pour les SMS (sans prénom ni nom à remplir, 28.10.2026)
        Route::put('mobile', [CoordonneesProprietaireController::class, 'mobile'])->middleware('throttle:10,1,espace-mobile');
        Route::get('commandes', [BoutiqueProprietaireController::class, 'commandes']);
        Route::post('commandes', [BoutiqueProprietaireController::class, 'passer'])->middleware('throttle:espace-commandes');
        Route::post('devis/{devis}/signer', [DocumentProprietaireController::class, 'signerDevis']);
        Route::post('devis/{devis}/refuser', [DocumentProprietaireController::class, 'refuserDevis']);
        Route::post('ordonnances/{ordonnance}/renouvellement', [DocumentProprietaireController::class, 'demanderRenouvellement']);
        Route::post('demandes', [CliniqueProprietaireController::class, 'deposer'])->middleware('throttle:espace-demandes');
        Route::get('demandes', [DemandeProprietaireController::class, 'index']);
        Route::post('demandes/{demande}/choisir', [DemandeProprietaireController::class, 'choisir'])->whereNumber('demande');
        Route::post('email/envoyer', [VerificationEmailController::class, 'envoyer'])->middleware(['throttle:auth', 'throttle:code-email']);
        Route::post('email/verifier', [VerificationEmailController::class, 'verifier'])->middleware('throttle:auth');
    });

    // Toute l'application clinique exige une organisation vérifiée et
    // payée (`organisation.ouverte`), sauf la facturation, pour payer.
    Route::middleware(['auth:sanctum', 'clinique', 'organisation.ouverte'])->group(function (): void {
        // Vidéos de formation vendues au nom de la clinique (31.10.2026)
        Route::middleware('can:organisation')->prefix('videos')->group(function (): void {
            Route::get('/', [ClinicVideoController::class, 'index']);
            Route::post('/', [ClinicVideoController::class, 'store'])->middleware('throttle:30,1,video-create');
            Route::prefix('{video}')->whereUuid('video')->group(function (): void {
                Route::patch('/', [ClinicVideoController::class, 'update']);
                Route::delete('/', [ClinicVideoController::class, 'destroy']);
                Route::post('publish', [ClinicVideoController::class, 'publish']);
                // Retirer reste possible quand l'organisation est fermée : sinon la vidéo reviendrait en vente à la réouverture
                Route::post('withdraw', [ClinicVideoController::class, 'withdraw'])->withoutMiddleware('organisation.ouverte');
                Route::post('episodes/order', [ClinicVideoController::class, 'reorder']);
                Route::post('poster', [ClinicVideoController::class, 'poster'])->middleware('throttle:30,1,video-poster');
                Route::delete('poster', [ClinicVideoController::class, 'removePoster']);
                Route::post('upload/start', [ClinicVideoUploadController::class, 'start'])->middleware('throttle:30,1,video-upload-start');
                Route::post('upload/urls', [ClinicVideoUploadController::class, 'urls'])->middleware('throttle:600,1,video-upload-urls');
                Route::get('upload/files', [ClinicVideoUploadController::class, 'files']);
                Route::post('upload/finish', [ClinicVideoUploadController::class, 'finish'])->middleware('throttle:30,1,video-upload-finish');
            });
        });

        // Annuaire des cliniques de l'organisation (id, nom, boxes) :
        // tout employé — le domaine organisation garde la gestion.
        Route::get('cliniques', AnnuaireCliniqueController::class);

        // Alertes de la personne (stock, consommation) et ses e-mails d'alerte
        Route::get('alertes', [AlertesController::class, 'index']);
        Route::post('alertes/lues', [AlertesController::class, 'lues']);
        Route::get('alertes/reglages', [AlertesController::class, 'reglages']);
        Route::put('alertes/reglages', [AlertesController::class, 'modifierReglages']);
        // Préférences de la personne dans cette clinique : outils favoris, masquer les autres (04.11.2026)
        Route::get('preferences', [StaffPreferenceController::class, 'show']);
        Route::patch('preferences', [StaffPreferenceController::class, 'update'])->middleware('throttle:60,1');
        // « À terminer » : le travail du jour du vétérinaire, bouclé ou non (08.11.2026)
        Route::get('unfinished-work', UnfinishedWorkController::class)->middleware('can:patients');

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
        // L'aperçu de la page Boutique dans l'éditeur, même avant publication (07.11.2026)
        Route::get('site/boutique', [SiteShopController::class, 'preview']);
        Route::get('site/videos', [SiteVideosController::class, 'preview']);
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
        // Calendrier de la clinique pour placer ou proposer : une semaine, sans la limite d'un mois
        Route::get('planning/semaine', SemainePlanningController::class);
        // Remplissage jour par jour (matin, midi, après-midi) pour la bande des jours
        Route::get('planning/occupation', PlanningOccupancyController::class);

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
        Route::post('demandes/{demande}/proposer', [DemandeRendezVousController::class, 'proposer']);
        Route::post('demandes/{demande}/refuser', [DemandeRendezVousController::class, 'refuser']);
        // Annulation demandée par le propriétaire (acompte) : la clinique décide
        Route::post('demandes/{demande}/annulation', [DemandeRendezVousController::class, 'annulation']);
        Route::delete('demandes/{demande}', [DemandeRendezVousController::class, 'destroy']);

        Route::get('liste-attente', [ListeAttenteController::class, 'index']);
        Route::post('liste-attente', [ListeAttenteController::class, 'store']);
        Route::post('liste-attente/{inscription}/placer', [ListeAttenteController::class, 'placer']);
        Route::delete('liste-attente/{inscription}', [ListeAttenteController::class, 'destroy']);

        // Domaine patients & dossier médical. Clients et patients sont
        // de NIVEAU ORGANISATION (partagés entre les cliniques du
        // jeton) ; jamais de suppression de fiche. Les enfants du
        // dossier se résolvent toujours via le patient.
        // Le NPA remplit la localité : répertoire officiel de swisstopo, hors ligne (03.11.2026).
        // Droit Patients ou Organisation, vérifié dans le contrôleur (création d'une clinique, 04.11.2026)
        Route::get('localites', LocalityController::class);
        Route::apiResource('clients', ClientController::class)->except('destroy');
        // L'historique d'un propriétaire sur un fil (fenêtre propriétaire, 03.11.2026)
        Route::get('clients/{client}/historique', ClientHistoryController::class);
        // L'exploitation d'animaux de rente : groupes, animal par sa marque auriculaire, visites, ventes, remises (09.11.2026)
        Route::middleware('can:patients')->prefix('clients/{client}/exploitation')->group(function (): void {
            Route::get('/', [ExploitationController::class, 'show']);
            Route::post('groupes', [ExploitationController::class, 'storeHerd']);
            Route::patch('groupes/{herd}', [ExploitationController::class, 'updateHerd'])->whereNumber('herd');
            Route::post('groupes/{herd}/archiver', [ExploitationController::class, 'archiveHerd'])->whereNumber('herd');
            Route::post('groupes/{herd}/animaux', [ExploitationController::class, 'storeAnimal'])->whereNumber('herd');
            Route::post('visites', [ExploitationController::class, 'storeVisit']);
            Route::post('ventes', [ExploitationController::class, 'storeSale']);
            Route::get('livraisons', [ExploitationController::class, 'deliveries']);
            Route::post('journal', [ExploitationController::class, 'storeEntry']);
            Route::post('conventions', [ExploitationController::class, 'storeAgreement']);
            Route::post('conventions/{agreement}/resilier', [ExploitationController::class, 'terminateAgreement'])->whereNumber('agreement');
            Route::post('stocks', [ExploitationController::class, 'storeStockMovement']);
        });
        // L'invitation dans l'espace : la renvoyer (nouveau lien) ou l'annuler (03.11.2026)
        Route::post('clients/{client}/invitation', [ClientSpaceInvitationController::class, 'store'])->middleware(['can:patients', 'throttle:30,1,invitation-espace']);
        Route::delete('clients/{client}/invitation', [ClientSpaceInvitationController::class, 'destroy'])->middleware('can:patients');
        Route::delete('clients/{client}/compte', [CompteClientController::class, 'supprimer']);

        // Demandes de rattachement (un client réclame un animal par sa
        // puce depuis l'espace) : avant la ressource, sinon
        // `patients/{patient}` capterait `patients/rattachements`.
        Route::get('patients/rattachements', [DemandeRattachementController::class, 'index']);
        Route::post('patients/rattachements/{demande}/accepter', [DemandeRattachementController::class, 'accepter']);
        Route::post('patients/rattachements/{demande}/refuser', [DemandeRattachementController::class, 'refuser']);
        // Les vétérinaires de l'organisation à choisir comme référent (07.11.2026), avant la ressource aussi
        Route::get('patients/veterinaires', ReferringVetController::class);

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
        // « Rappeler le propriétaire » depuis le carnet (28.10.2026)
        Route::post('patients/{patient}/vaccinations/{vaccination}/rappel', [RappelsController::class, 'vaccination'])->middleware('throttle:30,1');

        // Rappels aux propriétaires par e-mail : réglages et fenêtre « Rappels automatiques »
        Route::get('rappels', [RappelsController::class, 'index']);
        Route::get('rappels/reglages', [RappelsController::class, 'reglages']);
        Route::get('rappels/a-faire', [RappelsController::class, 'aFaire']);
        Route::get('rappels/envoyes', [RappelsController::class, 'envoyes']);
        Route::get('rappels/apercu/{objet}', [RappelsController::class, 'apercu']);
        // Messagerie de la clinique (décision de Jim, 28.10.2026)
        Route::middleware('can:patients')->prefix('messages')->group(function (): void {
            Route::get('/', [MessagerieCliniqueController::class, 'index']);
            Route::get('contacts', [MessagerieCliniqueController::class, 'contacts']);
            // « u12 » : le compte du client ; « c34 » : sa fiche (compte effacé). Jamais lié tel quel.
            // « e12 » : un collègue ; « k34 » : une autre clinique (01.11.2026)
            Route::get('{cle}', [MessagerieCliniqueController::class, 'fil'])->where('cle', '[ucek][0-9]+');
            // Le mode silencieux d'une conversation, pour soi (08.11.2026)
            Route::put('{cle}/silence', [MessagerieCliniqueController::class, 'silence'])->where('cle', '[ucek][0-9]+');
            Route::post('{cle}', [MessagerieCliniqueController::class, 'repondre'])->where('cle', '[ucek][0-9]+')->middleware('throttle:60,1,messages-clinique');
            // Messages audio et vidéo façon Telegram (07.11.2026), dans tous les fils où l'on écrit ;
            // la transcription « →A » par Deepgram, une fois par message
            Route::post('{cle}/media', [MessagerieCliniqueController::class, 'repondreMedia'])->where('cle', '[uek][0-9]+')->middleware('throttle:60,1,messages-clinique');
            Route::get('media/{media}', [MessageMediaController::class, 'show'])->whereNumber('media');
            // La première image d'une vidéo ronde (08.11.2026)
            Route::get('media/{media}/apercu', [MessageMediaController::class, 'poster'])->whereNumber('media');
            // Photos et PDF (08.11.2026), dans tous les fils où l'on écrit ; dans le fil d'un client, aussi des documents de son dossier
            Route::post('{cle}/pieces', [MessagerieCliniqueController::class, 'replyWithAttachments'])->where('cle', '[uek][0-9]+')->middleware('throttle:60,1,messages-clinique');
            Route::get('pieces/{attachment}', [MessageAttachmentController::class, 'show'])->whereNumber('attachment');
            Route::get('pieces/{attachment}/apercu', [MessageAttachmentController::class, 'thumb'])->whereNumber('attachment');
            Route::post('media/{media}/transcription', [MessageMediaController::class, 'transcribe'])->whereNumber('media')->middleware('throttle:30,1,messages-transcription');
        });
        Route::put('rappels/reglages', [RappelsController::class, 'modifierReglages']);

        // Catalogue des vaccins autorisés en Suisse (Swissmedic, GTIN Refdata)
        Route::get('vaccins', [VaccinCatalogueController::class, 'index']);
        Route::get('vaccins/gtin/{gtin}', [VaccinCatalogueController::class, 'gtin']);
        // Le catalogue Swissmedic entier, par code-barres ou par nom (nouvel article B, 03.11.2026)
        Route::get('catalogue/produits', CatalogueSearchController::class)->middleware('can:stocks');

        Route::get('patients/{patient}/visites', [VisiteController::class, 'index']);
        Route::post('patients/{patient}/visites', [VisiteController::class, 'store']);

        // Le dossier sur un fil (proposition B, 03.11.2026) : toute l'histoire, ce qui vient en tête
        Route::get('patients/{patient}/fil', PatientTimelineController::class);
        // Soins et interventions : les interventions et les séjours de l'animal, lus sans rien écrire (06.11.2026)
        Route::get('patients/{patient}/soins', PatientCareController::class);

        Route::get('patients/{patient}/documents', [DocumentController::class, 'index']);
        Route::post('patients/{patient}/documents', [DocumentController::class, 'store']);
        Route::get('patients/{patient}/documents/{document}/fichier', [DocumentController::class, 'fichier'])->whereNumber('document');

        // Partager le dossier (09.11.2026) : les pièces d'une période, chacune imprimable et ses fichiers (l'export en PDF),
        // les liens provisoires de l'animal (créer, lister avec leurs ouvertures, couper)
        Route::middleware('can:patients')->group(function (): void {
            Route::get('patients/{patient}/share-items', [RecordShareController::class, 'items']);
            Route::get('patients/{patient}/share-items/{type}/{id}', [RecordShareController::class, 'item'])->whereNumber('id');
            Route::get('patients/{patient}/share-items/{type}/{id}/files/{n}', [RecordShareController::class, 'file'])->whereNumber(['id', 'n']);
            Route::get('patients/{patient}/shares', [RecordShareController::class, 'index']);
            Route::post('patients/{patient}/shares', [RecordShareController::class, 'store'])->middleware('throttle:20,1,record-shares');
            Route::post('record-shares/{share}/revoke', [RecordShareController::class, 'revoke'])->whereNumber('share');

            // La lettre de référence (09.11.2026) : pièces, destinataire, notes, brouillon de l'IA, signature, lien ; la dictée des notes
            Route::get('patients/{patient}/referral-letters', [ReferralLetterController::class, 'index']);
            Route::post('patients/{patient}/referral-letters', [ReferralLetterController::class, 'store']);
            Route::prefix('referral-letters/{letter}')->whereNumber('letter')->group(function (): void {
                Route::get('/', [ReferralLetterController::class, 'show']);
                Route::patch('/', [ReferralLetterController::class, 'update']);
                Route::post('draft', [ReferralLetterController::class, 'draft'])->middleware('throttle:transcription');
                Route::post('sign', [ReferralLetterController::class, 'sign']);
                Route::post('link', [ReferralLetterController::class, 'link'])->middleware('throttle:20,1,record-shares');
                Route::get('enregistrement', [EnregistrementConsultationController::class, 'afficherDicteeReference']);
                Route::post('enregistrement', [EnregistrementConsultationController::class, 'demarrerDicteeReference'])->middleware('throttle:transcription');
                // Envoi C : dans Veterinary, à un vétérinaire ou à une clinique
                Route::post('invitations', [ReferralInvitationController::class, 'store'])->middleware('throttle:20,1,referral-invitations');
            });
            // L'annuaire de l'envoi C ; côté destinataire : ses invitations, accepter, choisir l'animal, refuser ; la lettre et les pièces d'un patient référé
            Route::get('referral-directory', [ReferralInvitationController::class, 'directory'])->middleware('throttle:60,1,referral-directory');
            Route::get('referral-invitations', [ReferralInvitationController::class, 'index']);
            Route::get('patients/{patient}/referrals', [ReferralInvitationController::class, 'forPatient']);
            Route::prefix('referral-invitations/{invitation}')->whereNumber('invitation')->group(function (): void {
                Route::get('/', [ReferralInvitationController::class, 'show']);
                Route::post('open', [ReferralInvitationController::class, 'open']);
                Route::post('accept', [ReferralInvitationController::class, 'accept']);
                Route::post('link', [ReferralInvitationController::class, 'link']);
                Route::post('refuse', [ReferralInvitationController::class, 'refuse']);
                Route::post('revoke', [ReferralInvitationController::class, 'revoke']);
                Route::get('items/{type}/{item}', [ReferralInvitationController::class, 'item'])->whereNumber('item');
                Route::get('items/{type}/{item}/files/{n}', [ReferralInvitationController::class, 'file'])->whereNumber(['item', 'n']);
                // Le retour au vétérinaire référent, depuis le dossier de l'animal référé
                Route::get('return-candidates', [ReferralReturnController::class, 'candidates']);
                Route::get('returns', [ReferralReturnController::class, 'sent']);
                Route::post('returns', [ReferralReturnController::class, 'store'])->middleware('throttle:20,1,referral-returns');
                Route::post('returns/{return}/revoke', [ReferralReturnController::class, 'revoke'])->whereNumber('return');
            });
            // Importer un gros PDF reçu et le découper (étape 3) : la découpe proposée par Claude Haiku 4.5
            Route::post('patients/{patient}/record-imports', [RecordImportController::class, 'store'])->middleware('throttle:10,1,record-imports');
            Route::get('record-imports/{import}', [RecordImportController::class, 'show'])->whereNumber('import');
            Route::post('record-imports/{import}/done', [RecordImportController::class, 'done'])->whereNumber('import');
            // Côté clinique qui a référé : les retours reçus, lus sans copie
            Route::get('patients/{patient}/referral-returns', [ReferralReturnController::class, 'forPatient']);
            Route::prefix('referral-returns/{return}')->whereNumber('return')->group(function (): void {
                Route::post('open', [ReferralReturnController::class, 'open']);
                Route::get('items/{type}/{item}', [ReferralReturnController::class, 'item'])->whereNumber('item');
                Route::get('items/{type}/{item}/files/{n}', [ReferralReturnController::class, 'file'])->whereNumber(['item', 'n']);
            });
        });

        Route::get('patients/{patient}/releves-ophtalmologiques', [ReleveOphtalmologiqueController::class, 'index']);
        Route::post('patients/{patient}/releves-ophtalmologiques', [ReleveOphtalmologiqueController::class, 'store']);
        Route::patch('patients/{patient}/releves-ophtalmologiques/{releve}', [ReleveOphtalmologiqueController::class, 'update']);
        Route::delete('patients/{patient}/releves-ophtalmologiques/{releve}', [ReleveOphtalmologiqueController::class, 'destroy']);

        // Schéma neurologique (08.11.2026) : les signes, examen après examen ; modifiable le jour même
        Route::get('patients/{patient}/neuro-exams', [NeuroExamController::class, 'index']);
        Route::post('patients/{patient}/neuro-exams', [NeuroExamController::class, 'store']);
        Route::patch('patients/{patient}/neuro-exams/{exam}', [NeuroExamController::class, 'update'])->whereNumber('exam');
        Route::delete('patients/{patient}/neuro-exams/{exam}', [NeuroExamController::class, 'destroy'])->whereNumber('exam');

        // Analyses et imagerie du dossier patient — toutes cliniques
        // de l'organisation (le dossier médical est partagé), borné
        // par le patient, scope clinique levé.
        Route::get('patients/{patient}/analyses', AnalysePatientController::class);
        Route::get('patients/{patient}/imagerie', ImageriePatientController::class);

        // Radios annotées (décision de Jim, 28.10.2026) : résolues par le
        // patient, pour toute l'équipe du dossier (cliniques sœurs comprises).
        // L'original relu tel quel, région et incidence validées, annotations
        // dessinées à part, diagnostics confirmés après coup.
        Route::get('referentiels/imagerie', ReferentielImagerieController::class);
        Route::prefix('patients/{patient}/imagerie/{examen}')->whereNumber(['examen', 'cliche', 'annotation', 'confirmation'])->group(function (): void {
            Route::get('cliches', [RadioDossierController::class, 'index']);
            Route::get('cliches/{cliche}/fichier', [RadioDossierController::class, 'fichier']);
            // L'aperçu léger de la radio, fait par l'app (02.11.2026)
            Route::get('cliches/{cliche}/apercu', [RadioPreviewController::class, 'show']);
            Route::post('cliches/{cliche}/apercu', [RadioPreviewController::class, 'store'])->middleware('throttle:60,1,radio-preview');
            Route::patch('cliches/{cliche}', [RadioDossierController::class, 'reperes']);
            Route::get('cliches/{cliche}/annotations', [AnnotationRadioController::class, 'index']);
            Route::post('cliches/{cliche}/annotations', [AnnotationRadioController::class, 'store'])->middleware('throttle:240,1,annotations');
            Route::patch('cliches/{cliche}/annotations/{annotation}', [AnnotationRadioController::class, 'update']);
            Route::delete('cliches/{cliche}/annotations/{annotation}', [AnnotationRadioController::class, 'destroy']);
            Route::get('confirmations', [ConfirmationRadioController::class, 'index']);
            Route::post('confirmations', [ConfirmationRadioController::class, 'store']);
            Route::delete('confirmations/{confirmation}', [ConfirmationRadioController::class, 'destroy']);
        });

        // Odontogramme Triadan — map complète, remplacée d'un bloc (gardé
        // pour la compatibilité) ; l'app envoie dent par dent et le bilan à
        // part : chacun n'écrit que ce qu'il reçoit (04.11.2026).
        Route::get('patients/{patient}/dents', [ReleveDentaireController::class, 'afficher']);
        Route::put('patients/{patient}/dents', [ReleveDentaireController::class, 'remplacer']);
        Route::patch('patients/{patient}/dents/bilan', [ReleveDentaireController::class, 'modifierBilan']);
        Route::patch('patients/{patient}/dents/{dent}', [ReleveDentaireController::class, 'modifierDent'])->whereNumber('dent');

        // Compte rendu SOAP — un par rendez-vous, upsert au PUT. Comme
        // tout le dossier médical, il est de niveau organisation : le
        // rendez-vous est résolu parmi les cliniques de l'organisation
        // (pas de binding implicite, qui le bornerait à la clinique).
        Route::get('rendez-vous/{rendezVous}/compte-rendu', [CompteRenduController::class, 'afficher']);
        Route::put('rendez-vous/{rendezVous}/compte-rendu', [CompteRenduController::class, 'enregistrer']);
        Route::post('rendez-vous/{rendezVous}/compte-rendu/signer', [CompteRenduController::class, 'signer']);
        Route::post('rendez-vous/{rendezVous}/compte-rendu/complements', [CompteRenduController::class, 'completer']);
        // Une ligne notée par un calculateur, à part du texte SOAP (04.11.2026)
        Route::post('rendez-vous/{rendezVous}/compte-rendu/outils', [CompteRenduController::class, 'noterOutil'])->middleware('throttle:60,1');
        Route::get('rendez-vous/{rendezVous}/compte-rendu/historique', [CompteRenduController::class, 'historique']);

        // Transcription des consultations (19.10.2026) — l'audio arrive
        // après la consultation, par morceaux ; il est transcrit, effacé,
        // puis un brouillon SOAP est rédigé pour le compte rendu ci-dessus,
        // que le vétérinaire valide. Niveau organisation, comme lui.
        Route::get('transcription', [TranscriptionController::class, 'etat']);
        Route::get('rendez-vous/{rendezVous}/enregistrement', [EnregistrementConsultationController::class, 'afficher']);
        Route::post('rendez-vous/{rendezVous}/enregistrement', [EnregistrementConsultationController::class, 'demarrer'])->middleware('throttle:transcription');
        Route::post('enregistrements/{enregistrement}/parties/{numero}', [PartieEnregistrementController::class, 'morceau'])->whereNumber(['enregistrement', 'numero'])->middleware('throttle:transcription');
        Route::post('enregistrements/{enregistrement}/terminer', [EnregistrementConsultationController::class, 'terminer'])->whereNumber('enregistrement')->middleware('throttle:transcription');
        Route::post('enregistrements/{enregistrement}/relancer', [EnregistrementConsultationController::class, 'relancer'])->whereNumber('enregistrement')->middleware('throttle:transcription');
        Route::delete('enregistrements/{enregistrement}', [EnregistrementConsultationController::class, 'supprimer'])->whereNumber('enregistrement')->middleware('throttle:transcription');

        // Suggestion diagnostique (23.10.2026) — Claude propose un
        // diagnostic différentiel à partir du compte rendu ci-dessus, tel
        // qu'il est à l'écran ; payante à la suggestion rendue.
        Route::get('diagnostic', [SuggestionDiagnostiqueController::class, 'etat']);
        Route::get('rendez-vous/{rendezVous}/suggestion-diagnostique', [SuggestionDiagnostiqueController::class, 'afficher']);
        Route::post('rendez-vous/{rendezVous}/suggestion-diagnostique', [SuggestionDiagnostiqueController::class, 'demander'])->middleware('throttle:10,1,diagnostic');

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
            // L'onglet Statistiques sur une période choisie (06.11.2026)
            Route::get('statistics', CashStatisticsController::class);

            Route::get('factures', [FactureController::class, 'index']);
            Route::get('factures/journal', JournalFacturesController::class);
            Route::get('factures/ouvertes', FacturesOuvertesController::class);
            // L'export comptable d'un mois, et le mois remis à la fiduciaire (09.11.2026)
            Route::get('ecritures', [EcrituresComptablesController::class, 'index']);
            Route::post('ecritures/{mois}/verrou', [EcrituresComptablesController::class, 'verrouiller']);
            Route::delete('ecritures/{mois}/verrou', [EcrituresComptablesController::class, 'rouvrir']);
            Route::post('factures', [FactureController::class, 'store']);
            Route::get('factures/{facture}', [FactureController::class, 'show']);
            Route::delete('factures/{facture}', [FactureController::class, 'destroy']);
            Route::post('factures/{facture}/lignes', [FactureLigneController::class, 'store']);
            Route::patch('factures/{facture}/lignes/{ligne}', [FactureLigneController::class, 'update'])->whereNumber('ligne');
            Route::delete('factures/{facture}/lignes/{ligne}', [FactureLigneController::class, 'destroy'])->whereNumber('ligne');
            Route::post('factures/{facture}/prescriptions', [FacturePrescriptionController::class, 'store']);
            Route::post('factures/{facture}/encaisser', [EncaissementController::class, 'encaisser'])->middleware('throttle:stripe');
            Route::post('factures/{facture}/payer-plus-tard', [EncaissementController::class, 'payerPlusTard']);
            Route::post('factures/{facture}/reglements/{settlement}/annuler', [EncaissementController::class, 'annulerReglement'])->whereNumber('settlement');
            // Vente du comptoir restée due : la caisse peut aussi l'annuler (28.10.2026)
            Route::post('ventes/{facture}/annuler', [BoutiquePaiementController::class, 'annuler'])->middleware('throttle:stripe');

            // Paiement en caisse : carte sur un terminal de la clinique, ou TWINT, sur le compte de la société
            Route::post('factures/{facture}/paiements', [PaiementCaisseController::class, 'store'])->middleware('throttle:stripe');
            Route::get('paiements/{paiement}', [PaiementCaisseController::class, 'show'])->middleware('throttle:stripe');
            Route::post('paiements/{paiement}/annuler', [PaiementCaisseController::class, 'annuler'])->middleware('throttle:stripe');
            Route::post('paiements/{paiement}/simuler-carte', [PaiementCaisseController::class, 'simulerCarte'])->middleware('throttle:stripe');
            // Compte de paiement de la société (Connect, sans tableau de bord) : état,
            // ouverture, sessions des composants intégrés
            Route::get('compte-paiement', [ComptePaiementController::class, 'afficher'])->middleware('throttle:stripe');
            Route::post('compte-paiement', [ComptePaiementController::class, 'ouvrir'])->middleware('throttle:stripe');
            Route::post('compte-paiement/session', [ComptePaiementController::class, 'session'])->middleware('throttle:30,1,session-paiement');
            Route::put('compte-paiement/integration', [ComptePaiementController::class, 'integration'])->middleware('throttle:stripe');
            Route::get('mode-encaissement', [ComptePaiementController::class, 'mode']);
            Route::get('terminaux', [TerminalPaiementController::class, 'index'])->middleware('throttle:stripe');
            Route::post('terminaux', [TerminalPaiementController::class, 'store'])->middleware('throttle:stripe');
            Route::delete('terminaux/{terminal}', [TerminalPaiementController::class, 'destroy'])->middleware('throttle:stripe');
            Route::put('factures/{facture}/decompte', [DecompteAssuranceController::class, 'enregistrer']);
            Route::post('factures/{facture}/decompte/avancer', [DecompteAssuranceController::class, 'avancer']);

            Route::get('clotures-caisse', [ClotureCaisseController::class, 'index']);
            Route::post('clotures-caisse', [ClotureCaisseController::class, 'store']);
            Route::get('clotures-caisse/apercu', [ClotureCaisseController::class, 'apercu']);
            Route::get('clotures-caisse/{cloture}', [ClotureCaisseController::class, 'show']);

            Route::post('devis/apercu', [DevisController::class, 'apercu']);
            Route::post('devis/{devis}/envoyer', [CycleDevisController::class, 'envoyer']);
            Route::post('devis/{devis}/relancer', [CycleDevisController::class, 'relancer'])->middleware('throttle:30,1');
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
            // Accord pour l'entraînement sur les radios anonymisées (28.10.2026)
            Route::put('entrainement-imagerie', EntrainementImagerieController::class);
            // Ouvertes à une organisation fermée : l'état de l'abonnement,
            // la formule, la carte et les factures, de quoi payer.
            Route::withoutMiddleware('organisation.ouverte')->group(function (): void {
                Route::get('/', [OrganisationController::class, 'afficher']);
                Route::get('abonnement/apercu', [AbonnementController::class, 'apercu']);
                // Appels à Stripe : leur propre compteur, par compte (26.10.2026)
                Route::post('abonnement', [AbonnementController::class, 'changer'])->middleware('throttle:stripe-abonnement');
                Route::post('moyen-paiement/intention', [MoyenPaiementController::class, 'intention'])->middleware('throttle:stripe-abonnement');
                Route::put('moyen-paiement', [MoyenPaiementController::class, 'enregistrer'])->middleware('throttle:stripe-abonnement');
                Route::delete('moyen-paiement', [MoyenPaiementController::class, 'supprimer'])->middleware('throttle:stripe-abonnement');
                Route::get('factures', [OrganisationFactureController::class, 'index']);
                Route::get('factures/{facture}', [OrganisationFactureController::class, 'show'])->whereNumber('facture');
                Route::post('factures/{facture}/regler', [OrganisationFactureController::class, 'regler'])->whereNumber('facture')->middleware('throttle:stripe-abonnement');
                Route::post('factures/{facture}/confirmer', [OrganisationFactureController::class, 'confirmer'])->whereNumber('facture')->middleware('throttle:stripe-abonnement');
            });

            Route::get('societes', [SocieteController::class, 'index']);
            Route::post('societes', [SocieteController::class, 'store']);
            Route::patch('societes/{societe}', [SocieteController::class, 'update'])->whereNumber('societe');

            Route::get('cliniques', [OrganisationCliniqueController::class, 'index']);
            Route::post('cliniques', [OrganisationCliniqueController::class, 'store']);
            Route::patch('cliniques/{clinique}', [OrganisationCliniqueController::class, 'update'])->whereNumber('clinique');
            Route::delete('cliniques/{clinique}', [OrganisationCliniqueController::class, 'destroy'])->whereNumber('clinique');
            // Photo de la clinique, montrée dans l'annuaire des cliniques
            Route::post('cliniques/{clinique}/photo', [PhotoCliniqueController::class, 'televerser'])->whereNumber('clinique');
            Route::delete('cliniques/{clinique}/photo', [PhotoCliniqueController::class, 'supprimer'])->whereNumber('clinique');
            // Horaires d'une clinique de l'organisation (créée ou
            // sœur) — /horaires ne vise que la clinique du jeton.
            Route::get('cliniques/{clinique}/horaires', [OrganisationHoraireController::class, 'afficher'])->whereNumber('clinique');
            Route::put('cliniques/{clinique}/horaires', [OrganisationHoraireController::class, 'modifier'])->whereNumber('clinique');
            // Services, animaux soignés, vétérinaires dans l'annuaire : ouvrent
            // les modules de l'espace vétérinaire et paraissent dans l'annuaire
            Route::put('cliniques/{clinique}/services', OrganisationServicesCliniqueController::class)->whereNumber('clinique');

            Route::post('abonnement/resilier', [AbonnementController::class, 'resilier'])->middleware('throttle:stripe-abonnement');
            Route::post('abonnement/reactiver', [AbonnementController::class, 'reactiver'])->middleware('throttle:stripe-abonnement');
            Route::post('packs', [PackQuotaController::class, 'acheter'])->middleware('throttle:stripe-abonnement');
            Route::get('transcription', [OrganisationTranscriptionController::class, 'afficher']);
            Route::put('transcription', [OrganisationTranscriptionController::class, 'modifier']);
            Route::get('diagnostic', [OrganisationDiagnosticController::class, 'afficher']);
            Route::put('diagnostic', [OrganisationDiagnosticController::class, 'modifier']);
            // Catalogue d'actes : un acte, un prix (lecture : facturation/prestations)
            Route::post('prestations', [OrganisationPrestationController::class, 'store']);
            Route::patch('prestations/{prestation}', [OrganisationPrestationController::class, 'update'])->whereNumber('prestation');
            Route::delete('prestations/{prestation}', [OrganisationPrestationController::class, 'destroy'])->whereNumber('prestation');
            // Premiers pas d'une clinique neuve : étapes et confirmations
            Route::get('premiers-pas', [PremiersPasController::class, 'afficher']);
            Route::put('premiers-pas', [PremiersPasController::class, 'modifier']);
            // Rôles et droits (23.10.2026) : rôles réglables, exceptions par personne
            Route::get('roles', [OrganisationRoleController::class, 'index']);
            Route::post('roles', [OrganisationRoleController::class, 'store']);
            Route::put('roles/{role}', [OrganisationRoleController::class, 'update'])->whereNumber('role');
            Route::delete('roles/{role}', [OrganisationRoleController::class, 'destroy'])->whereNumber('role');
            Route::put('employes/{employe}/exceptions', [OrganisationRoleController::class, 'exceptions'])->whereNumber('employe');
        });

        // Domaine équipe — fiches RH, une par personne. La liste est celle
        // de la clinique du jeton (écran Équipe) ; les gestes (fiche,
        // embauche, cliniques, suspension, licenciement) sont bornés à
        // l'ORGANISATION :
        // l'écran Organisation pilote aussi les cliniques sœurs, la
        // résolution se fait sans binding implicite. Les absences,
        // elles, restent aux routes planning/équipe existantes.
        // Le planning de l'équipe, lu par chacun (11.11.2026) : les nuits de
        // garde, les absences (celles des autres sans raison, sans le droit
        // « Gérer les absences », audit C29), et ses propres demandes de congé
        Route::get('equipe/gardes', [GardeController::class, 'index']);
        Route::get('equipe/planning', PlanningEquipeController::class);
        // Les jours fériés de la clinique (É23) : lus par qui règle horaires ou équipe, changés par l'administration ou les RH
        Route::get('equipe/jours-feries', [JoursFeriesController::class, 'show']);
        Route::put('equipe/jours-feries', [JoursFeriesController::class, 'update']);
        Route::post('equipe/demandes-conge', [DemandeCongeController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('equipe/demandes-conge/{absence}', [DemandeCongeController::class, 'destroy'])->whereNumber('absence');

        Route::middleware('can:equipe')->prefix('equipe')->group(function (): void {
            Route::get('employes', [EquipeEmployeController::class, 'index']);
            Route::get('employes/{employe}', [EquipeEmployeController::class, 'show'])->whereNumber('employe');

            // Gestes RH : droit `rh` (23.10.2026), garde-fous dans EmployePolicy
            Route::middleware('can:rh')->group(function (): void {
                Route::post('employes', [EquipeEmployeController::class, 'store'])->middleware('throttle:30,1');
                Route::patch('employes/{employe}', [EquipeEmployeController::class, 'update'])->whereNumber('employe');
                Route::post('employes/{employe}/suspendre', [AccesEmployeController::class, 'suspendre'])->whereNumber('employe');
                Route::post('employes/{employe}/retablir', [AccesEmployeController::class, 'retablir'])->whereNumber('employe');
                Route::post('employes/{employe}/licencier', [LicenciementEmployeController::class, 'licencier'])->whereNumber('employe');
                Route::post('employes/{employe}/annuler-licenciement', [LicenciementEmployeController::class, 'annuler'])->whereNumber('employe');
                Route::put('employes/{employe}/cliniques', AffectationsEmployeController::class)->whereNumber('employe');
                // Embauche : à qui appartient le code employé donné par la personne
                Route::get('comptes/{code}', CompteEmployeController::class)->middleware('throttle:20,1');
                // Ou invitation par e-mail : la personne accepte avec l'adresse invitée
                Route::get('invitations', [InvitationEquipeController::class, 'index']);
                Route::post('invitations', [InvitationEquipeController::class, 'store'])->middleware('throttle:30,1');
                Route::post('invitations/{invitation}/renvoyer', [InvitationEquipeController::class, 'renvoyer'])->whereNumber('invitation')->middleware('throttle:30,1');
                Route::delete('invitations/{invitation}', [InvitationEquipeController::class, 'annuler'])->whereNumber('invitation');
            });

            Route::put('gardes', [GardeController::class, 'modifier']);
            Route::get('employes/{employe}/conges', [EquipeEmployeController::class, 'conges'])->whereNumber('employe');
            Route::get('conges/a-traiter', [CongesEquipeController::class, 'aTraiter']);
            Route::get('conges/reglages', [CongesEquipeController::class, 'reglages']);
            Route::put('conges/reglages', [CongesEquipeController::class, 'modifierReglages']);
        });

        // Domaine stocks. Catalogue d'organisation, quantités par
        // clinique : la lecture montre la ventilation de toutes les
        // cliniques, les écritures (ajustements, réceptions, lots) ne
        // touchent que la clinique du jeton.
        // Vetpoint (27.10.2026) : la clé de la clinique (droit Organisation)
        // et la recherche au prix de la clinique (droit Stocks)
        Route::prefix('vetpoint')->group(function (): void {
            Route::middleware('can:organisation')->group(function (): void {
                Route::get('connexion', [ConnexionVetpointController::class, 'show']);
                Route::put('connexion', [ConnexionVetpointController::class, 'update'])->middleware('throttle:vetpoint-cle');
                Route::delete('connexion', [ConnexionVetpointController::class, 'destroy']);
            });
            Route::get('produits', RechercheVetpointController::class)->middleware('can:stocks');
        });

        // SI ABV (08.11.2026) : déclarer les antibiotiques à l'OSAV. Réglage de la
        // clinique (droit Organisation), listes de l'OSAV et déclarations (droit
        // Patients, gestes sous droit Prescrire au contrôleur)
        Route::prefix('isabv')->group(function (): void {
            Route::put('connection', [IsabvConnectionController::class, 'update'])->middleware(['can:organisation', 'throttle:10,1']);
            Route::middleware('can:patients')->group(function (): void {
                // Lu aussi par qui prescrit : la variante seulement, sans le token (droit Organisation pour le reste)
                Route::get('connection', [IsabvConnectionController::class, 'show']);
                Route::get('reference', [IsabvReferenceController::class, 'index']);
                Route::get('diagnoses', [IsabvReferenceController::class, 'diagnoses']);
                Route::get('preparations', [IsabvReferenceController::class, 'preparations']);
                Route::get('declarations', [AntibioticDeclarationController::class, 'index']);
                Route::get('declarations/{declaration}', [AntibioticDeclarationController::class, 'show'])->whereNumber('declaration');
                Route::put('declarations/{declaration}', [AntibioticDeclarationController::class, 'update'])->whereNumber('declaration');
                Route::post('declarations/{declaration}/entered-on-web', [AntibioticDeclarationController::class, 'enteredOnWeb'])->whereNumber('declaration');
                Route::post('declarations/{declaration}/deactivated-on-web', [AntibioticDeclarationController::class, 'deactivatedOnWeb'])->whereNumber('declaration');
                Route::get('consultations', [AntibioticDeclarationController::class, 'consultations']);
                Route::post('consultations', [AntibioticDeclarationController::class, 'sendConsultations'])->middleware('throttle:10,1');
                Route::get('duplicates', [AntibioticDeclarationController::class, 'duplicates'])->middleware('throttle:20,1');
                Route::post('duplicates/reactivate', [AntibioticDeclarationController::class, 'reactivate'])->middleware('throttle:10,1');
            });
        });

        Route::middleware('can:stocks')->prefix('stocks')->group(function (): void {
            Route::get('produits', [ProduitController::class, 'index']);
            // La préparation SI ABV d'un médicament, choisie à la main (08.11.2026)
            Route::patch('produits/{produit}/isabv', [ProductPreparationController::class, 'update']);
            // « À surveiller » : la plus proche péremption de chaque article et le stock bas, avec leurs comptes (04.11.2026)
            Route::get('surveillance', StockWatchController::class);
            Route::post('produits/{produit}/vetpoint', [ProduitVetpointController::class, 'store']);
            Route::delete('produits/{produit}/vetpoint', [ProduitVetpointController::class, 'destroy']);
            Route::post('produits', [ProduitController::class, 'store']);
            Route::get('produits/{produit}', [ProduitController::class, 'show']);
            // Le code ATC d'un article existant (mise en garde des allergies, 03.11.2026)
            Route::patch('produits/{produit}/atc', [ProduitController::class, 'updateAtc']);
            Route::patch('produits/{produit}/delais-attente', [ProduitController::class, 'updateWithdrawal']);
            // Ce que contient une unité (médicaments à la dose, 03.11.2026)
            Route::patch('produits/{produit}/contenance', [ProductContentController::class, 'update']);
            // Le taux de TVA à la vente d'un article, et la proposition par famille (03.11.2026)
            Route::get('vat-rates', [ProductVatRateController::class, 'index']);
            Route::patch('produits/{produit}/vat-rate', [ProductVatRateController::class, 'update']);
            // Jeter l'unité entamée (périmée, souillée), même sans unité fermée
            Route::post('produits/{produit}/entame/jeter', [OpenedUnitController::class, 'discard']);
            // L'inventaire du flacon entamé
            Route::patch('produits/{produit}/entame', [OpenedUnitController::class, 'count']);
            Route::post('produits/{produit}/ajuster', AjustementStockController::class);
            Route::get('produits/{produit}/mouvements', [MouvementStockController::class, 'index']);
            // Nouvel article B (03.11.2026) : commande automatique et sources ; photo de la vitrine
            Route::put('produits/{produit}/sources', [ProductSourcesController::class, 'update']);
            Route::post('produits/{produit}/photo', [ProductPhotoController::class, 'upload']);
            Route::delete('produits/{produit}/photo', [ProductPhotoController::class, 'remove']);
            // Les fournisseurs et centrales d'achat de la clinique (03.11.2026)
            Route::get('fournisseurs', [SupplierController::class, 'index']);
            Route::post('fournisseurs', [SupplierController::class, 'store']);
            Route::patch('fournisseurs/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier');
            Route::delete('fournisseurs/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier');
            Route::get('centrales', [SupplierController::class, 'connectors']);

            Route::get('commandes', [CommandeFournisseurController::class, 'index']);
            Route::post('commandes', [CommandeFournisseurController::class, 'store']);
            Route::post('commandes/generer', [CommandeFournisseurController::class, 'generer']);
            // L'onglet « courrier » (03.11.2026) : titres de mois des reçues, aperçu du stock bas
            Route::get('commandes/mois', [CommandeFournisseurController::class, 'months']);
            Route::get('commandes/stock-bas', [CommandeFournisseurController::class, 'lowStock']);
            // Commandes de la clinique du jeton seulement (on commande depuis
            // la clinique, 28.10.2026) ; une autre clinique répond 404.
            Route::post('commandes/{commande}/envoyer', [CommandeFournisseurController::class, 'envoyer'])->whereNumber('commande');
            Route::post('commandes/{commande}/receptionner', [CommandeFournisseurController::class, 'receptionner'])->whereNumber('commande');
            // Vetpoint, étapes 3 et 4 (28.10.2026) : adresses, envoi, détail chez Vetpoint
            Route::get('commandes/{commande}/vetpoint/adresses', [CommandeVetpointController::class, 'adresses'])->whereNumber('commande');
            Route::post('commandes/{commande}/vetpoint', [CommandeVetpointController::class, 'envoyer'])->whereNumber('commande');
            Route::get('commandes/{commande}/vetpoint', [CommandeVetpointController::class, 'detail'])->whereNumber('commande');
            Route::post('commandes/{commande}/vetpoint/abandonner', [CommandeVetpointController::class, 'abandonner'])->whereNumber('commande');
            Route::delete('commandes/{commande}', [CommandeFournisseurController::class, 'destroy'])->whereNumber('commande');
            // Le panier automatique (03.11.2026) : pause, reprise, attendre la date suivante, envoyer maintenant
            Route::post('commandes/{commande}/pause', [AutoBasketController::class, 'pause'])->whereNumber('commande');
            Route::post('commandes/{commande}/reprendre', [AutoBasketController::class, 'resume'])->whereNumber('commande');
            Route::post('commandes/{commande}/attendre', [AutoBasketController::class, 'wait'])->whereNumber('commande');
            Route::post('commandes/{commande}/envoyer-maintenant', [AutoBasketController::class, 'sendNow'])->whereNumber('commande');
            Route::get('commandes/{commande}/adresses', [AutoBasketController::class, 'addresses'])->whereNumber('commande');
            // Lignes d'un brouillon, avant envoi.
            Route::post('commandes/{commande}/lignes', [CommandeFournisseurLigneController::class, 'store'])->whereNumber('commande');
            Route::patch('commandes/{commande}/lignes/{ligne}', [CommandeFournisseurLigneController::class, 'update'])->whereNumber('commande')->whereNumber('ligne');
            Route::delete('commandes/{commande}/lignes/{ligne}', [CommandeFournisseurLigneController::class, 'destroy'])->whereNumber('commande')->whereNumber('ligne');

            Route::get('lots', [LotController::class, 'index']);
            Route::post('lots/{lot}/ecarter', [LotController::class, 'ecarter']);
            // À qui un lot a été donné ou vendu (lots décomptés à chaque sortie, 04.11.2026)
            Route::get('lots/{lot}/sorties', [LotController::class, 'sorties']);
        });

        // Registre des stupéfiants — domaine stocks, tenu PAR CLINIQUE
        // (art. 57, 63 et 64 OCStup ; mis aux normes le 01.11.2026) :
        // journal immuable en création seule (une erreur se corrige par une
        // ligne de correction), substances, bilan d'une période pour les
        // contrôles cantonaux, produits marqués stupéfiants (tableau a ou b).
        Route::middleware('can:stocks')->prefix('stupefiants')->group(function (): void {
            Route::get('mouvements', [MouvementStupefiantController::class, 'index']);
            Route::post('mouvements', [MouvementStupefiantController::class, 'store']);
            // Les ordonnances en cours d'un animal qui prescrivent une substance, pour une remise (01.11.2026)
            Route::get('ordonnances', [MouvementStupefiantController::class, 'ordonnances']);
            Route::get('soldes', [RegistreStupefiantController::class, 'soldes']);
            Route::get('registre', [RegistreStupefiantController::class, 'exporter']);
            Route::put('produits/{produit}', SubstanceStupefiantController::class);
            // Le visa, réservé au vétérinaire responsable que désigne un administrateur (décision de Jim, 01.11.2026)
            Route::get('responsable', [VisaStupefiantController::class, 'show']);
            Route::put('responsable', [VisaStupefiantController::class, 'designer'])->middleware('can:organisation');
            Route::post('visa', [VisaStupefiantController::class, 'viser']);
        });

        // Domaine hospitalisation — rattaché au domaine patients (soins
        // au chevet : l'ASV le tient autant que le vétérinaire). Séjours
        // PAR CLINIQUE (le box est un lit physique), enfants (soins,
        // constantes) résolus via le séjour. Jamais de suppression : la
        // sortie clôt le séjour et facture (effet système).
        Route::middleware(['can:patients', 'service:hospitalisation'])->prefix('hospitalisation')->group(function (): void {
            Route::get('sejours', [SejourController::class, 'index']);
            Route::post('sejours', [SejourController::class, 'store']);
            Route::get('sejours/{sejour}', [SejourController::class, 'show']);
            Route::patch('sejours/{sejour}', [SejourController::class, 'update']);

            Route::post('sejours/{sejour}/soins', [SoinSejourController::class, 'store']);
            Route::post('sejours/{sejour}/soins/{soin}/faire', [SoinSejourController::class, 'faire']);
            Route::post('sejours/{sejour}/constantes', [ConstanteSejourController::class, 'store']);

            Route::get('sejours/{sejour}/facture', [SortieSejourController::class, 'apercu']);
            Route::post('sejours/{sejour}/sortie', [SortieSejourController::class, 'sortir']);

            // La ronde, sur tout le séjour (03.11.2026) : la feuille de chaque
            // jour (06:00 à 06:00), les médicaments et qui les a donnés, la
            // douleur, les observations, le séjour jour par jour, le rapport
            // de la journée rédigé par l'IA
            Route::get('ronde', [StayRoundController::class, 'index']);
            Route::get('ronde/compteurs', [StayRoundController::class, 'counters']);
            Route::get('produits', [StayTreatmentController::class, 'products']);
            Route::get('sejours/{sejour}/jours', [StayRoundController::class, 'days']);
            Route::post('sejours/{sejour}/soins/{soin}/omettre', [SoinSejourController::class, 'skip']);
            Route::post('sejours/{sejour}/soins/{soin}/annuler', [SoinSejourController::class, 'cancel']);
            // Une prise prévue (pas encore en base), donnée en avance dans sa fenêtre de 2 heures
            Route::post('sejours/{sejour}/traitements/{traitement}/prises', [SoinSejourController::class, 'giveTreatmentDose'])->whereNumber('traitement');
            Route::post('sejours/{sejour}/traitements', [StayTreatmentController::class, 'store']);
            Route::post('sejours/{sejour}/traitements/{traitement}/arreter', [StayTreatmentController::class, 'stop'])->whereNumber('traitement');
            Route::post('sejours/{sejour}/douleurs', [StayNoteController::class, 'pain']);
            Route::post('sejours/{sejour}/observations', [StayNoteController::class, 'observation']);
            Route::get('sejours/{sejour}/feuilles/{jour}', [DailyReportController::class, 'show'])->where('jour', '\d{4}-\d{2}-\d{2}');
            Route::post('sejours/{sejour}/feuilles/{jour}/rapport', [DailyReportController::class, 'draft'])->where('jour', '\d{4}-\d{2}-\d{2}');
            Route::patch('sejours/{sejour}/feuilles/{jour}/rapport', [DailyReportController::class, 'update'])->where('jour', '\d{4}-\d{2}-\d{2}');
            Route::post('sejours/{sejour}/feuilles/{jour}/rapport/signer', [DailyReportController::class, 'sign'])->where('jour', '\d{4}-\d{2}-\d{2}');
            Route::post('sejours/{sejour}/feuilles/{jour}/rapport/envoyer', [DailyReportController::class, 'send'])->where('jour', '\d{4}-\d{2}-\d{2}');

            // La lettre de sortie (09.11.2026) : consignes, brouillon de l'IA, signature, envoi ; la dictée des consignes
            Route::get('sejours/{sejour}/letter', [DischargeLetterController::class, 'show']);
            Route::patch('sejours/{sejour}/letter', [DischargeLetterController::class, 'update']);
            Route::post('sejours/{sejour}/letter/draft', [DischargeLetterController::class, 'draft'])->middleware('throttle:transcription');
            Route::post('sejours/{sejour}/letter/sign', [DischargeLetterController::class, 'sign']);
            Route::post('sejours/{sejour}/letter/send', [DischargeLetterController::class, 'send']);
            Route::get('sejours/{sejour}/enregistrement', [EnregistrementConsultationController::class, 'afficherDicteeSejour']);
            Route::post('sejours/{sejour}/enregistrement', [EnregistrementConsultationController::class, 'demarrerDicteeSejour'])->middleware('throttle:transcription');
        });

        // Domaine laboratoire — rattaché au domaine patients (dossier
        // médical : l'ASV tient la paillasse). Demandes PAR CLINIQUE
        // (worklist du labo physique), numérotées A-YY-NNNN, cycle
        // attente → cours → valid → ok un cran à la fois ; résultats
        // aux bornes instantanées, figés par la validation signée.
        // Jamais de suppression.
        Route::middleware(['can:patients', 'service:laboratoire'])->prefix('laboratoire')->group(function (): void {
            Route::get('panels', [PanelLaboController::class, 'index']);

            Route::get('analyses', [AnalyseController::class, 'index']);
            Route::post('analyses', [AnalyseController::class, 'store']);
            Route::get('analyses/{analyse}', [AnalyseController::class, 'show']);
            Route::patch('analyses/{analyse}', [AnalyseController::class, 'update']);

            Route::post('analyses/{analyse}/avancer', CycleAnalyseController::class);
            Route::put('analyses/{analyse}/resultats', ResultatAnalyseController::class);
            // Le résumé d'une analyse validée par l'IA (09.11.2026)
            Route::get('analyses/{analyse}/summary', [LabSummaryController::class, 'show']);
            Route::patch('analyses/{analyse}/summary', [LabSummaryController::class, 'update']);
            Route::post('analyses/{analyse}/summary/draft', [LabSummaryController::class, 'draft'])->middleware('throttle:transcription');
            Route::post('analyses/{analyse}/summary/sign', [LabSummaryController::class, 'sign']);
            Route::post('analyses/{analyse}/summary/send', [LabSummaryController::class, 'send']);
        });

        // Domaine imagerie — rattaché au domaine patients (dossier
        // médical). Examens PAR CLINIQUE (worklist de la modalité
        // physique), même patron que le bloc : l'examen est le dossier
        // maître, le RDV `imagerie` lié n'est que sa projection
        // planning — la signature du compte rendu fige l'examen, clôt
        // le RDV et facture l'acte. Flux DICOM idempotent par SOP
        // Instance UID. Jamais de suppression.
        Route::middleware(['can:patients', 'service:imagerie'])->prefix('imagerie')->group(function (): void {
            Route::get('trames', TrameImagerieController::class);

            Route::get('examens', [ExamenImagerieController::class, 'index']);
            Route::post('examens', [ExamenImagerieController::class, 'store']);
            Route::get('examens/{examen}', [ExamenImagerieController::class, 'show']);
            Route::patch('examens/{examen}', [ExamenImagerieController::class, 'update']);

            Route::post('examens/{examen}/compte-rendu', CompteRenduImagerieController::class);
            // Radio DICOM envoyée depuis l'examen : l'original, tel quel (28.10.2026)
            Route::post('examens/{examen}/cliches/fichier', RadioController::class)->middleware('throttle:120,1,radios');
        });

        // Domaine bloc opératoire — son propre domaine de permission
        // (l'Assistant par défaut ne l'a pas). L'intervention est le
        // dossier maître ; le RDV `chir` lié n'est que sa projection
        // planning. Cycle un cran à la fois, checklist bloquante avant
        // l'incision, consentement coché par sa seule signature.
        Route::middleware(['can:bloc', 'service:chirurgie'])->prefix('bloc')->group(function (): void {
            Route::get('ressources', RessourcesBlocController::class);
            Route::get('occupation', BlocOccupancyController::class);
            Route::get('interventions', [InterventionController::class, 'index']);
            Route::post('interventions', [InterventionController::class, 'store']);
            Route::get('interventions/{intervention}', [InterventionController::class, 'show']);
            Route::patch('interventions/{intervention}', [InterventionController::class, 'update']);

            Route::post('interventions/{intervention}/avancer', CycleInterventionController::class);
            // Rapport de bloc (C, 09.11.2026) : brouillon de l'IA depuis l'écran, les notes et la dictée ; validé, figé
            Route::get('interventions/{intervention}/report', [SurgeryReportController::class, 'show']);
            Route::patch('interventions/{intervention}/report', [SurgeryReportController::class, 'update']);
            Route::post('interventions/{intervention}/report/draft', [SurgeryReportController::class, 'draft'])->middleware('throttle:transcription');
            Route::post('interventions/{intervention}/report/validate', [SurgeryReportController::class, 'validateReport']);
            Route::post('interventions/{intervention}/report/complements', [SurgeryReportController::class, 'complement']);
            Route::get('interventions/{intervention}/enregistrement', [EnregistrementConsultationController::class, 'afficherDictee']);
            Route::post('interventions/{intervention}/enregistrement', [EnregistrementConsultationController::class, 'demarrerDictee'])->middleware('throttle:transcription');
            Route::post('interventions/{intervention}/checklist', [ChecklistInterventionController::class, 'ajouter']);
            Route::put('interventions/{intervention}/checklist/{code}', [ChecklistInterventionController::class, 'cocher']);
            Route::delete('interventions/{intervention}/checklist/{code}', [ChecklistInterventionController::class, 'retirer']);
            Route::post('interventions/{intervention}/consentement', ConsentementInterventionController::class);
            // Envoyé pour signature (SMS, e-mail, espace) ou recueilli sans signature (02.11.2026)
            Route::post('interventions/{intervention}/consentement/envoi', ConsentRequestController::class)->middleware('throttle:10,1,consent-send');
            Route::post('interventions/{intervention}/consentement/sans-signature', ConsentWithoutSignatureController::class);
        });

        // Domaine téléconsultation — rattaché au domaine planning (la
        // session pilote les statuts du RDV). La session est le
        // dossier maître de l'appel, le RDV `tele` lié n'est que sa
        // projection : démarrage → cours, « Terminer » → fini +
        // facture (effet système), « Quitter » ne touche à rien. La
        // durée est servie par le serveur. Jamais de suppression.
        // Les appels qu'on me transfère, de toutes mes cliniques (un collègue
        // de deux cliniques les retrouve après un rechargement, 28.10.2026)
        Route::get('tele/transferts', [TransfertAppelController::class, 'mesTransferts'])->middleware('can:planning');

        Route::middleware(['can:planning', 'service:teleconsultation'])->prefix('tele')->group(function (): void {
            Route::get('sessions', [SessionTeleconsultationController::class, 'index']);
            Route::post('sessions', [SessionTeleconsultationController::class, 'store']);
            Route::get('sessions/{session}', [SessionTeleconsultationController::class, 'show']);
            Route::post('sessions/{session}/terminer', FinTeleconsultationController::class);
            // Appel vidéo maison en WebRTC (28.10.2026) : lien du client, serveurs ICE, mise en relation
            Route::post('sessions/{session}/lien', [VisioController::class, 'lien'])->middleware('throttle:10,1,visio-lien');
            Route::get('sessions/{session}/ice', [VisioController::class, 'ice'])->middleware('throttle:30,1,visio-ice');
            Route::post('sessions/{session}/signal', [VisioController::class, 'signal']);
            Route::get('sessions/{session}/signaux/{reference}', [VisioController::class, 'lireSignal'])->whereUuid('reference');
            // Transfert de l'appel à un collègue (28.10.2026)
            Route::get('sessions/{session}/collegues', [TransfertAppelController::class, 'collegues']);
            Route::post('sessions/{session}/transferer', [TransfertAppelController::class, 'transferer']);
            Route::post('sessions/{session}/transfert/annuler', [TransfertAppelController::class, 'annuler']);
            Route::post('sessions/{session}/prendre', [TransfertAppelController::class, 'prendre']);
        });

        // Boutique — domaine stocks (arbitrage : l'ASV la tient, c'est
        // le stock de la clinique qui bouge). La vente comptoir crée sa
        // facture comme effet système, payée tout de suite en espèces ou
        // sans paiement intégré, sinon au terminal ou par TWINT ; la remise d'une commande
        // en ligne sort le stock de la clinique de retrait.
        Route::middleware(['can:stocks', 'service:boutique'])->prefix('boutique')->group(function (): void {
            // Vente du comptoir payée au terminal ou par TWINT, depuis la boutique (28.10.2026)
            Route::get('ventes', [BoutiquePaiementController::class, 'enAttente']);
            Route::get('ventes/{facture}', [BoutiquePaiementController::class, 'afficherVente']);
            Route::get('terminaux', [BoutiquePaiementController::class, 'terminaux'])->middleware('throttle:stripe');
            Route::post('ventes/{facture}/paiements', [BoutiquePaiementController::class, 'payer'])->middleware('throttle:stripe');
            Route::post('ventes/{facture}/especes', [BoutiquePaiementController::class, 'especes'])->middleware('throttle:stripe');
            Route::post('ventes/{facture}/annuler', [BoutiquePaiementController::class, 'annuler'])->middleware('throttle:stripe');
            Route::get('paiements/{paiement}', [BoutiquePaiementController::class, 'afficher'])->middleware('throttle:stripe');
            Route::post('paiements/{paiement}/annuler', [BoutiquePaiementController::class, 'annulerPaiement'])->middleware('throttle:stripe');
            Route::get('commandes', [CommandeBoutiqueController::class, 'index']);
            Route::post('commandes', [CommandeBoutiqueController::class, 'store']);
            Route::post('commandes/{commande}/preparer', [CommandeBoutiqueController::class, 'preparer']);
            Route::post('commandes/{commande}/retirer', [CommandeBoutiqueController::class, 'retirer']);

            Route::post('vente', VenteComptoirController::class);
            // Le ticket chiffré avant l'encaissement, du calcul de la facture
            Route::post('vente/apercu', [VenteComptoirController::class, 'apercu']);
            Route::patch('produits/{produit}/vitrine', [VitrineController::class, 'modifier']);
            Route::get('statistiques', StatistiquesBoutiqueController::class);
        });
    });
});
