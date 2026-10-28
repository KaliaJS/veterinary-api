<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Auth\SousContexteClinique;
use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\ServiceClinique;
use App\Enums\Specialite;
use App\Enums\StatutDemandeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\DeposerDemandeRendezVousRequest;
use App\Http\Requests\V1\Espace\DeposerDemandeSansCompteRequest;
use App\Mail\DemandeRendezVousEnvoyee;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CliniqueEspece;
use App\Models\CliniqueService;
use App\Models\DemandeRendezVous;
use App\Models\Employe;
use App\Models\HoraireOuverture;
use App\Models\Patient;
use App\Models\SiteWeb;
use App\Models\SpecialiteVeterinaire;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Rules\AdresseSansAccent;
use App\Services\CalculateurCreneaux;
use App\Services\Captcha\Turnstile;
use App\Services\CodeDemandePublique;
use App\Services\Paiements\AcomptesRendezVous;
use App\Services\Photos\PhotosCliniques;
use App\Services\SemaineDisponible;
use App\Services\TraitementDemande;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Prendre rendez-vous : annuaire de toutes les cliniques (carte du
 * proto) avec, pour chacune, ses prochains créneaux libres calculés
 * pour toutes les cliniques d'un coup (CalculateurCreneaux) ; dépôt d'une
 * demande de rendez-vous dans la file de la clinique — elle confirme
 * ensuite (mode « à valider »), boucle réelle du proto.
 */
class CliniqueProprietaireController extends Controller
{
    private const int CRENEAUX_PAR_CLINIQUE = 3;

    private const int JOURS_EXPLORES = 7;

    private const int DUREE_MINUTES = 20;

    /** Demandes ou rendez-vous à venir par personne et par clinique */
    private const int DEMANDES_OUVERTES_MAX = 5;

    /** Les créneaux proposés tombent au quart d'heure (horaires suisses : décalage en heures entières). */

    /**
     * L'annuaire : les cliniques ouvertes avec photo, adresse complète et
     * position. `?cliniques=1,2` sert une clinique précise même hors de
     * l'annuaire (le site d'une clinique y prend ses créneaux, 23.10.2026).
     */
    public function index(Request $request, CalculateurCreneaux $calculateur): JsonResponse
    {
        $ids = array_slice(array_filter(array_map('intval', explode(',', $request->string('cliniques')->toString()))), 0, 20);
        $secondes = (int) config('plateforme.annuaire_cache_secondes');

        return response()->json(['data' => $secondes > 0
            ? Cache::remember('annuaire:'.implode(',', $ids), $secondes, fn (): array => $this->annuaire($ids, $calculateur))
            : $this->annuaire($ids, $calculateur)]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function annuaire(array $ids, CalculateurCreneaux $calculateur): array
    {

        $cliniques = Clinique::withoutGlobalScopes()
            ->ouvertes()
            ->when($ids !== [], fn ($requete) => $requete->whereKey($ids))
            ->with([
                'societe.organisation',
                'services',
                'especes',
                // Les vétérinaires de la clinique, leur compte (badge) et leurs spécialités
                'employes' => fn ($employes) => $employes->withoutGlobalScope('clinique')->where('acces_suspendu', false),
                'employes.user.specialites',
                // L'heure d'ouverture fixe la grille des créneaux proposés
                'horaireOuverture' => fn ($horaires) => $horaires->withoutGlobalScope('clinique'),
            ])
            ->orderBy('id')
            ->get()
            ->when($ids === [], fn ($liste) => $liste->filter(fn (Clinique $clinique): bool => $clinique->estDansLAnnuaire())->values());

        // Une semaine de créneaux pour toutes les cliniques d'un coup : le nombre de requêtes ne dépend pas du nombre de cliniques.
        $semaines = $calculateur->creneauxDesCliniques($cliniques, self::JOURS_EXPLORES, self::DUREE_MINUTES);

        // Site web de l'organisation de chaque clinique : lien s'il est publié, logo dans tous les cas.
        $sites = SiteWeb::withoutGlobalScopes()->get()->keyBy('organisation_id');

        return $cliniques->map(fn (Clinique $clinique): array => [
            'id' => $clinique->id,
            'nom' => $clinique->nom,
            'rue' => $clinique->rue,
            'npa' => $clinique->npa,
            'ville' => $clinique->ville,
            'tel' => $clinique->tel,
            'latitude' => $clinique->latitude === null ? null : (float) $clinique->latitude,
            'longitude' => $clinique->longitude === null ? null : (float) $clinique->longitude,
            'organisation' => ['id' => $clinique->societe->organisation->id, 'nom' => $clinique->societe->organisation->nom],
            'site_slug' => ($site = $sites[$clinique->societe->organisation->id] ?? null)?->est_publie ? $site->slug : null,
            'site_url' => $site?->est_publie ? $site->urlPublique() : null,
            'logo_url' => $site?->logoUrl(),
            'photo' => PhotosCliniques::pour($clinique),
            // Sans photo, adresse complète ni position, l'annuaire ne la montre
            // pas ; demandée par son id, elle sert aux créneaux de son site
            'dans_annuaire' => $clinique->estDansLAnnuaire(),
            'creneaux' => $this->retenir($semaines[$clinique->id], $clinique),
            ...$this->offre($clinique),
        ])->values()->all();
    }

    /**
     * Ce que la clinique propose, pour sa fiche, les filtres et la
     * recherche : ses services (cochés par l'administrateur), les animaux
     * soignés, et ses vétérinaires vérifiés par la plateforme avec leurs
     * spécialités. Un vétérinaire non vérifié n'y paraît pas ; si la
     * clinique masque ses vétérinaires, seules leurs spécialités restent.
     *
     * @return array<string, mixed>
     */
    private function offre(Clinique $clinique): array
    {
        $ordre = fn (array $cas): \Closure => fn ($valeur): int => (int) array_search($valeur, $cas, true);

        $services = $clinique->services
            ->sortBy(fn (CliniqueService $ligne): int => $ordre(ServiceClinique::cases())($ligne->service))
            ->values();

        // Seulement les fiches de l'organisation de la clinique (défense en profondeur)
        $verifies = $clinique->employes->where('organisation_id', $clinique->societe?->organisation_id)
            ->filter(fn (Employe $fiche): bool => $fiche->estVeterinaire() && (bool) $fiche->user?->estVerifie())
            ->sortBy('nom')
            ->values();
        $specialitesDe = fn (Employe $fiche): array => $fiche->user->specialites
            ->map(fn (SpecialiteVeterinaire $ligne): Specialite => $ligne->specialite)
            ->sortBy($ordre(Specialite::cases()))
            ->map(fn (Specialite $specialite): string => $specialite->value)
            ->values()->all();

        return [
            'services' => $services->map(fn (CliniqueService $ligne): array => ['service' => $ligne->service->value, 'modalite' => $ligne->modalite?->value])->all(),
            'nombre_boxes' => $clinique->aService(ServiceClinique::Hospitalisation) ? $clinique->nombre_boxes : null,
            'especes' => $clinique->especes
                ->map(fn (CliniqueEspece $ligne): Espece => $ligne->espece)
                ->sortBy($ordre(Espece::cases()))
                ->map(fn (Espece $espece): string => $espece->value)
                ->values()->all(),
            // Toutes celles de ses vétérinaires vérifiés (filtres, recherche, icônes)
            'specialites' => collect(Specialite::cases())
                ->filter(fn (Specialite $specialite): bool => $verifies->contains(fn (Employe $fiche): bool => in_array($specialite->value, $specialitesDe($fiche), true)))
                ->map(fn (Specialite $specialite): string => $specialite->value)
                ->values()->all(),
            // Demandes de rendez-vous sans compte acceptées (non par défaut)
            'rdv_sans_compte' => $clinique->rdv_sans_compte,
            // Rendez-vous réservé aussitôt (défaut), ou demande que la clinique valide
            'rdv_a_valider' => $clinique->rdv_a_valider,
            'urgences_a_valider' => $clinique->urgences_a_valider,
            // Acompte pour réserver (centimes, null : aucun) et délai de remboursement
            'acompte_centimes' => $clinique->acompteExigible(),
            // null : la clinique ne montre pas ses vétérinaires (les icônes seules)
            // Nommés seulement s'ils l'ont accepté (nLPD, 23.10.2026)
            'veterinaires' => $clinique->affiche_veterinaires
                ? $verifies->filter(fn (Employe $fiche): bool => $fiche->user?->annuaire_accord_le !== null)->values()->map(fn (Employe $fiche): array => [
                    // Le compte : sa fiche publique (public/veterinaires/{id})
                    'id' => $fiche->user_id,
                    'nom' => $fiche->nom,
                    'photo_url' => $fiche->photoUrl(),
                    'specialites' => $specialitesDe($fiche),
                ])->all()
                : null,
        ];
    }

    public function deposer(DeposerDemandeRendezVousRequest $request, ContexteProprietaire $contexte, SousContexteClinique $sousContexte): JsonResponse
    {
        $donnees = $request->validated();
        $clinique = Clinique::withoutGlobalScopes()->ouvertes()->with('societe')->findOrFail((int) $donnees['clinique_id']);
        $user = $contexte->user();

        // Une adresse prouvée : la clinique ne réserve pas pour une adresse inventée
        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'email' => 'Confirmez d’abord votre adresse e-mail (Réglages, Mon compte) : un code vous y attend.',
            ]);
        }
        $this->plafond($clinique, fn ($q) => $q->where('user_id', $user->id));

        $patient = isset($donnees['patient_id'])
            ? Patient::withoutGlobalScopes()->find((int) $donnees['patient_id'])
            : null;

        // Fiche du compte dans l'organisation de la clinique, si elle existe.
        $fiche = $contexte->clients()->firstWhere('organisation_id', $clinique->societe->organisation_id);
        // Jamais la fiche d'une autre organisation (animal suivi ailleurs)
        $clientDuPatient = $patient && Client::withoutGlobalScopes()->whereKey($patient->client_id)
            ->where('organisation_id', $clinique->societe->organisation_id)->exists() ? $patient->client_id : null;
        $nomProprietaire = trim((string) ($donnees['nom_proprietaire'] ?? ''))
            ?: ($fiche ? trim($fiche->prenom.' '.$fiche->nom) : $user->name);

        return $this->enregistrer($clinique, $donnees, $sousContexte, auteur: $user, qui: [
            'patient_id' => $patient?->id,
            'client_id' => $fiche->id ?? $clientDuPatient,
            'user_id' => $user->id,
            'patient_name' => $patient->nom ?? $donnees['patient_name'],
            'species' => $patient->espece ?? $donnees['espece'],
            'owner_name' => $nomProprietaire,
        ]);
    }

    /**
     * Sans compte, d'abord : le code qui prouve l'adresse, envoyé seulement
     * si le captcha a passé (un robot ne fait partir aucun e-mail).
     */
    public function codeSansCompte(Request $request, Turnstile $captcha, CodeDemandePublique $codes): JsonResponse
    {
        $donnees = $request->validate([
            'clinique_id' => ['required', 'integer'],
            'email' => ['required', 'email', 'max:190', new AdresseSansAccent],
            'captcha' => ['required', 'string', 'max:4096'],
        ], [
            'captcha.required' => 'Cochez la vérification anti-robot.',
        ]);
        $clinique = Clinique::withoutGlobalScopes()->ouvertes()->where('rdv_sans_compte', true)->findOrFail((int) $donnees['clinique_id']);

        if (! $captcha->verifier($donnees['captcha'], $request->ip())) {
            throw ValidationException::withMessages(['captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.']);
        }

        $codes->envoyer(mb_strtolower(trim($donnees['email'])), $clinique);

        return response()->json(['message' => 'Un code a été envoyé.'], 202);
    }

    /** Puis la demande, avec le code reçu : ses coordonnées pour lui répondre. */
    public function deposerSansCompte(DeposerDemandeSansCompteRequest $request, SousContexteClinique $sousContexte, CodeDemandePublique $codes): JsonResponse
    {
        $donnees = $request->validated();
        $clinique = Clinique::withoutGlobalScopes()->ouvertes()->findOrFail((int) $donnees['clinique_id']);
        $email = mb_strtolower(trim($donnees['email']));

        // Vérifié et réservé d'un coup : deux envois du même code ne font jamais
        // deux demandes (24.10.2026). Un refus ensuite (heure prise entre-temps,
        // plafond) rend le code : on choisit une autre heure sans nouvel e-mail
        $codes->verifier($email, $clinique, $donnees['code']);
        $marque = $codes->reserver($email, $clinique);
        if ($marque === null) {
            throw ValidationException::withMessages(['code' => 'Ce code a déjà servi : demandez-en un nouveau.']);
        }

        try {
            $this->plafond($clinique, fn ($q) => $q->whereNull('user_id')->where('email', $email));
            $reponse = $this->enregistrer($clinique, $donnees, $sousContexte, [
                'patient_name' => trim($donnees['patient_name']),
                'species' => $donnees['espece'],
                'owner_name' => trim($donnees['nom_proprietaire']),
                'email' => mb_strtolower(trim($donnees['email'])),
                'telephone' => trim($donnees['telephone']),
                // Le lien de ses e-mails, pour suivre la demande sans compte
                'jeton' => Str::random(48),
            ]);
        } catch (ValidationException $refus) {
            // Refusée par une règle (heure prise, plafond) : rien n'est enregistré, le code resservira
            $codes->liberer($email, $clinique, $marque);

            throw $refus;
        } catch (Throwable $e) {
            // Toute autre erreur : la demande a pu être enregistrée, le code ne resservira pas
            $codes->consommer($email, $clinique);

            throw $e;
        }
        $codes->consommer($email, $clinique);

        return $reponse;
    }

    /** Le dernier jour que montre le calendrier : dans un mois. */
    private static function limiteDuCalendrier(Clinique $clinique): CarbonImmutable
    {
        // Sans déborder : le 31 octobre, jusqu'au 30 novembre, pas au 1er décembre
        return CarbonImmutable::today($clinique->fuseau_horaire ?? 'Europe/Zurich')->addMonthNoOverflow();
    }

    /**
     * Pas plus de DEMANDES_OUVERTES_MAX demandes ou rendez-vous à venir
     * par personne et par clinique : un script ne remplit pas un agenda.
     * Les reports demandés comptent aussi (24.10.2026).
     *
     * @param  callable(Builder<DemandeRendezVous>): mixed  $qui
     */
    public static function plafond(Clinique $clinique, callable $qui): void
    {
        $ouvertes = DemandeRendezVous::withoutGlobalScopes()
            ->where('clinique_id', $clinique->id)
            ->where($qui)
            ->where(fn ($q) => $q
                ->whereIn('statut', [StatutDemandeRendezVous::ATraiter, StatutDemandeRendezVous::APayer, StatutDemandeRendezVous::Proposee])
                ->orWhere(fn ($acceptee) => $acceptee->where('statut', StatutDemandeRendezVous::Acceptee)->where('debut_souhaite', '>', now())))
            ->count();

        if ($ouvertes >= self::DEMANDES_OUVERTES_MAX) {
            throw ValidationException::withMessages([
                'clinique_id' => 'Vous avez déjà '.self::DEMANDES_OUVERTES_MAX.' demandes ou rendez-vous à venir chez '.$clinique->nom.' : attendez sa réponse, ou appelez la clinique.',
            ]);
        }
    }

    /**
     * La demande dans la file de la clinique : le créneau souhaité, le
     * mode, le motif, et pour une urgence ce que voit le propriétaire.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $qui  animal et propriétaire
     */
    private function enregistrer(Clinique $clinique, array $donnees, SousContexteClinique $sousContexte, array $qui, ?User $auteur = null): JsonResponse
    {
        $debut = CarbonImmutable::parse($donnees['debut_souhaite'])->setTimezone($clinique->fuseau_horaire ?? 'Europe/Zurich');
        // Une heure du calendrier, et pas au-delà du mois qu'il montre
        if (! app(SemaineDisponible::class)->estUneHeureProposee($clinique, $debut, self::limiteDuCalendrier($clinique))) {
            throw ValidationException::withMessages(['debut_souhaite' => 'Choisissez une heure proposée par le calendrier.']);
        }
        $urgence = (bool) ($donnees['urgence'] ?? false);
        // Par défaut, l'heure libre est réservée aussitôt ; une urgence attend
        // la clinique (sauf si elle ne veut pas les valider), et la clinique
        // peut préférer valider chaque rendez-vous (déconseillé)
        $reserver = $urgence ? ! $clinique->urgences_a_valider : ! $clinique->rdv_a_valider;
        // Acompte de la clinique : pour toute réservation directe, urgence
        // comprise (23.10.2026 : cocher « urgence » ne l'évite pas) ; jamais
        // pour une demande que la clinique valide
        $acompte = $reserver ? $clinique->acompteExigible() : null;

        $demande = $sousContexte->pour($clinique, function () use ($qui, $donnees, $urgence, $debut, $reserver, $acompte, $auteur): DemandeRendezVous {
            $traitement = app(TraitementDemande::class);
            $ligne = $reserver ? $traitement->ligneLibreA($debut->utc(), self::DUREE_MINUTES) : null;

            if ($reserver && $ligne === null) {
                throw ValidationException::withMessages(['debut_souhaite' => 'Cette heure vient d’être prise : choisissez-en une autre.']);
            }

            return DB::transaction(function () use ($qui, $donnees, $urgence, $debut, $reserver, $acompte, $auteur, $traitement, $ligne): DemandeRendezVous {
                $demande = DemandeRendezVous::create([
                    ...$qui,
                    // Avec acompte : réservée une fois payée ; le jeton ramène le client après le paiement
                    'statut' => $acompte ? StatutDemandeRendezVous::APayer : StatutDemandeRendezVous::ATraiter,
                    'acompte_centimes' => $acompte,
                    'jeton' => $qui['jeton'] ?? ($acompte ? Str::random(48) : null),
                    'reason' => $donnees['motif'],
                    'urgence' => $urgence,
                    'symptomes' => $urgence ? trim((string) ($donnees['symptomes'] ?? '')) : null,
                    'souhait' => ($donnees['mode'] === 'visio' ? 'Téléconsultation vidéo' : 'Sur place').' · '.$debut->format('d.m.Y à H:i').(isset($qui['email']) ? ' (sans compte)' : ' (espace client)'),
                    'debut_souhaite' => $debut->utc(),
                    'teleconsultation' => $donnees['mode'] === 'visio',
                    // Réservée par le client (confirme), ou à valider par la clinique
                    'mode' => $reserver ? ModeDemandeRendezVous::Confirmee : ModeDemandeRendezVous::AValider,
                    'duree_minutes' => self::DUREE_MINUTES,
                ]);

                // Le rendez-vous est pris, le propriétaire reçoit la confirmation
                if ($reserver && ! $acompte) {
                    $traitement->accepter($demande->load(['clinique', 'user']), $debut, $ligne, $auteur);
                }

                return $demande;
            });
        });

        // Acompte : le paiement s'ouvre dans le parcours
        $paiement = null;
        if ($acompte) {
            try {
                $paiement = app(AcomptesRendezVous::class)->ouvrir($demande);
            } catch (ErreurPaiement $e) {
                $demande->delete();
                throw ValidationException::withMessages(['paiement' => $e->getMessage()]);
            } catch (Throwable $e) {
                // Paiements fermés entre-temps, ou autre : pas de demande à
                // payer orpheline (24.10.2026)
                $demande->delete();
                throw $e;
            }
        }

        // À valider : accusé de réception, avec le lien où suivre la réponse
        if (! $reserver) {
            $adresse = $demande->load(['clinique', 'user'])->adresseDuProprietaire();
            // La demande est enregistrée : un accusé qui ne part pas (panne du
            // serveur d'e-mails) ne la fait pas échouer (24.10.2026)
            if ($adresse) {
                try {
                    Mail::to($adresse)->send(new DemandeRendezVousEnvoyee($demande));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->json(['data' => [
            'demande_id' => $demande->id,
            'clinique' => ['id' => $clinique->id, 'nom' => $clinique->nom, 'ville' => $clinique->ville],
            'debut_souhaite' => $debut->toIso8601String(),
            'mode' => $donnees['mode'],
            'patient_name' => $demande->patient_name,
            'motif' => $demande->reason,
            'urgence' => $demande->urgence,
            // `acceptee` : le rendez-vous est réservé ; `a_traiter` : la clinique
            // répond ; `a_payer` : l'acompte d'abord (`paiement`), puis la
            // confirmation par le jeton (public/demandes/{jeton}/paiement)
            'statut' => $demande->refresh()->statut,
            'rendez_vous_id' => $demande->rendez_vous_id,
            'paiement' => $paiement,
            'jeton' => $paiement ? $demande->jetonClair() : null,
        ]], 201);
    }

    /**
     * Calendrier de la prise de rendez-vous : une semaine (du lundi au
     * dimanche) de la clinique, chaque jour avec toutes ses heures
     * d'ouverture au pas d'un rendez-vous, libres ou non (pause exclue,
     * heure passée jamais libre). D'aujourd'hui à un mois plus tard : les
     * jours hors de cette période restent à leur place dans la semaine,
     * `visible` à faux et sans heures. `precedente` et `suivante` donnent
     * les lundis des semaines voisines.
     */
    public function disponibilites(Request $request, SemaineDisponible $semaines, int $clinique): JsonResponse
    {
        $fiche = Clinique::withoutGlobalScopes()->ouvertes()->findOrFail($clinique);
        $request->validate(['semaine' => ['nullable', 'date_format:Y-m-d']]);

        $limite = self::limiteDuCalendrier($fiche);

        return response()->json(['data' => $semaines->pour($fiche, $request->query('semaine'), $limite, self::DUREE_MINUTES)]);
    }

    /**
     * Prochains créneaux libres d'une clinique, sur sa semaine, à des heures
     * DISTINCTES et étalés sur les jours (proto : « Auj. 14:00 »,
     * « Dem. 09:15 »…) : les trous libres de chaque jour sont découpés
     * au pas de la durée, on retient d'abord le premier créneau de
     * chaque jour, puis on complète chronologiquement.
     *
     * Les heures proposées sont celles de la grille du calendrier (24.10.2026) :
     * le pas de 20 minutes compté depuis l'ouverture, que la demande exige
     * ensuite. Avant, au quart d'heure, « le plus vite possible » était
     * refusé.
     *
     * @param  list<Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>>  $semaine
     * @return list<array{debut: string}>
     */
    private function retenir(array $semaine, Clinique $clinique): array
    {
        // En horodatages entiers : des milliers de pas par semaine, une date Carbon par pas coûterait cher.
        $pas = self::DUREE_MINUTES * 60;
        $maintenant = now()->getTimestamp();
        $fuseau = $clinique->fuseau_horaire ?? 'Europe/Zurich';
        $ouverture = CarbonImmutable::parse((string) (($clinique->horaireOuverture ?? new HoraireOuverture(HoraireOuverture::DEFAUTS))->ouverture));
        $minuteOuverture = $ouverture->hour * 60 + $ouverture->minute;
        $parJour = [];

        foreach ($semaine as $trous) {
            $candidats = [];

            foreach ($trous as $trou) {
                $fin = $trou['fin']->getTimestamp();
                // La première heure de la grille strictement à venir dans ce trou
                // (« 14:00 » à 14:00:20 donne 14:20 sur une grille de 8:00)
                $depart = CarbonImmutable::createFromTimestamp(max($trou['debut']->getTimestamp(), $maintenant + 1), $fuseau);
                $minutes = $depart->hour * 60 + $depart->minute + ($depart->second > 0 ? 1 : 0);
                $surGrille = $minuteOuverture + (int) ceil(max(0, $minutes - $minuteOuverture) / SemaineDisponible::PAS_MINUTES) * SemaineDisponible::PAS_MINUTES;
                // setTime et non minuit + minutes : juste aux jours du changement d'heure
                $premier = $depart->setTime(intdiv($surGrille, 60), $surGrille % 60)->getTimestamp();

                for ($debut = $premier; $debut + $pas <= $fin; $debut += $pas) {
                    if ($debut > $maintenant) {
                        $candidats[$debut] ??= ['debut' => $debut, 'ligne_planning_id' => $trou['ligne_planning_id']];
                    }
                }
            }

            if ($candidats !== []) {
                ksort($candidats);
                $parJour[] = array_values($candidats);
            }
        }

        $retenus = [];
        foreach ($parJour as $candidats) {
            $retenus[] = array_shift($candidats);
            if (count($retenus) >= self::CRENEAUX_PAR_CLINIQUE) {
                break;
            }
        }

        if (count($retenus) < self::CRENEAUX_PAR_CLINIQUE) {
            $restants = array_merge(...array_map(fn (array $c): array => array_slice($c, 1), $parJour ?: [[]]));
            usort($restants, fn (array $a, array $b): int => $a['debut'] <=> $b['debut']);
            $retenus = array_slice(array_merge($retenus, $restants), 0, self::CRENEAUX_PAR_CLINIQUE);
        }

        // L'heure seule : l'organisation interne du planning reste à la clinique (23.10.2026)
        return array_map(fn (array $c): array => [
            'debut' => CarbonImmutable::createFromTimestampUTC($c['debut'])->toIso8601String(),
        ], $retenus);
    }
}
