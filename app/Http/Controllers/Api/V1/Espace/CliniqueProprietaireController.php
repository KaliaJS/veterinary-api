<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Auth\SousContexteClinique;
use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\ServiceClinique;
use App\Enums\Specialite;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\DeposerDemandeRendezVousRequest;
use App\Http\Requests\V1\Espace\DeposerDemandeSansCompteRequest;
use App\Models\Clinique;
use App\Models\CliniqueEspece;
use App\Models\CliniqueService;
use App\Models\DemandeRendezVous;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\SiteWeb;
use App\Models\SpecialiteVeterinaire;
use App\Services\CalculateurCreneaux;
use App\Services\Photos\PhotosCliniques;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

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

    /** Les créneaux proposés tombent au quart d'heure (horaires suisses : décalage en heures entières). */
    private const int QUART = 900;

    public function index(CalculateurCreneaux $calculateur): JsonResponse
    {
        $cliniques = Clinique::withoutGlobalScopes()
            ->ouvertes()
            ->with([
                'societe.organisation',
                'services',
                'especes',
                // Les vétérinaires de la clinique, leur compte (badge) et leurs spécialités
                'employes' => fn ($employes) => $employes->withoutGlobalScope('clinique')->where('acces_suspendu', false),
                'employes.user.specialites',
            ])
            ->orderBy('id')
            ->get();

        // Une semaine de créneaux pour toutes les cliniques d'un coup : le nombre de requêtes ne dépend pas du nombre de cliniques.
        $semaines = $calculateur->creneauxDesCliniques($cliniques, self::JOURS_EXPLORES, self::DUREE_MINUTES);

        // Site web de l'organisation de chaque clinique : lien s'il est publié, logo dans tous les cas.
        $sites = SiteWeb::withoutGlobalScopes()->get()->keyBy('organisation_id');

        return response()->json(['data' => $cliniques->map(fn (Clinique $clinique): array => [
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
            // pas ; elle reste ici pour les créneaux du site de sa clinique
            'dans_annuaire' => $clinique->estDansLAnnuaire(),
            'creneaux' => $this->retenir($semaines[$clinique->id]),
            ...$this->offre($clinique),
        ])->all()]);
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

        $verifies = $clinique->employes
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
            // null : la clinique ne montre pas ses vétérinaires (les icônes seules)
            'veterinaires' => $clinique->affiche_veterinaires
                ? $verifies->map(fn (Employe $fiche): array => [
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

        $patient = isset($donnees['patient_id'])
            ? Patient::withoutGlobalScopes()->find((int) $donnees['patient_id'])
            : null;

        // Fiche du compte dans l'organisation de la clinique, si elle existe.
        $fiche = $contexte->clients()->firstWhere('organisation_id', $clinique->societe->organisation_id);
        $user = $contexte->user();
        $nomProprietaire = trim((string) ($donnees['nom_proprietaire'] ?? ''))
            ?: ($fiche ? trim($fiche->prenom.' '.$fiche->nom) : $user->name);

        return $this->enregistrer($clinique, $donnees, $sousContexte, [
            'patient_id' => $patient?->id,
            'client_id' => $fiche?->id ?? $patient?->client_id,
            'patient_name' => $patient?->nom ?? $donnees['patient_name'],
            'species' => $patient?->espece ?? $donnees['espece'],
            'owner_name' => $nomProprietaire,
        ]);
    }

    /** Sans compte, chez une clinique qui l'accepte : ses coordonnées pour lui répondre. */
    public function deposerSansCompte(DeposerDemandeSansCompteRequest $request, SousContexteClinique $sousContexte): JsonResponse
    {
        $donnees = $request->validated();
        $clinique = Clinique::withoutGlobalScopes()->ouvertes()->findOrFail((int) $donnees['clinique_id']);

        return $this->enregistrer($clinique, $donnees, $sousContexte, [
            'patient_name' => trim($donnees['patient_name']),
            'species' => $donnees['espece'],
            'owner_name' => trim($donnees['nom_proprietaire']),
            'email' => mb_strtolower(trim($donnees['email'])),
            'telephone' => trim($donnees['telephone']),
        ]);
    }

    /** « Personnaliser » : le délai et le moment, tels que la clinique les lit */
    private const PERIODES = [
        'cette_semaine' => 'cette semaine',
        'semaine_prochaine' => 'la semaine prochaine',
        'deux_semaines' => 'dans 2 semaines',
        'un_mois' => 'dans un mois',
    ];

    private const MOMENTS = ['matin' => 'le matin', 'apres_midi' => 'l’après-midi'];

    /**
     * La demande dans la file de la clinique : le créneau souhaité (ou un
     * jour, ou un délai), le mode, le motif, et pour une urgence ce que
     * voit le propriétaire.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $qui  animal et propriétaire
     */
    private function enregistrer(Clinique $clinique, array $donnees, SousContexteClinique $sousContexte, array $qui): JsonResponse
    {
        $debut = isset($donnees['debut_souhaite'])
            ? CarbonImmutable::parse($donnees['debut_souhaite'])->setTimezone($clinique->fuseau_horaire ?? 'Europe/Zurich')
            : null;
        $quand = $debut?->format('d.m.Y à H:i') ?? $this->souhaitLibre($donnees);
        $urgence = (bool) ($donnees['urgence'] ?? false);

        $demande = $sousContexte->pour($clinique, fn (): DemandeRendezVous => DemandeRendezVous::create([
            ...$qui,
            'reason' => $donnees['motif'],
            'urgence' => $urgence,
            'symptomes' => $urgence ? trim((string) ($donnees['symptomes'] ?? '')) : null,
            'souhait' => ($donnees['mode'] === 'visio' ? 'Téléconsultation vidéo' : 'Sur place').' · '.$quand.(isset($qui['email']) ? ' (sans compte)' : ' (espace client)'),
            'mode' => ModeDemandeRendezVous::AValider,
            'duree_minutes' => self::DUREE_MINUTES,
        ]));

        return response()->json(['data' => [
            'demande_id' => $demande->id,
            'clinique' => ['id' => $clinique->id, 'nom' => $clinique->nom, 'ville' => $clinique->ville],
            'debut_souhaite' => $debut?->toIso8601String(),
            'souhait' => $quand,
            'mode' => $donnees['mode'],
            'patient_name' => $demande->patient_name,
            'motif' => $demande->reason,
            'urgence' => $demande->urgence,
        ]], 201);
    }

    /**
     * « le 28.10.2026, le matin », « la semaine prochaine » : le souhait
     * d'un propriétaire à qui aucun créneau ne convient.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function souhaitLibre(array $donnees): string
    {
        $quand = isset($donnees['jour_souhaite'])
            ? 'le '.CarbonImmutable::parse($donnees['jour_souhaite'])->format('d.m.Y')
            : self::PERIODES[$donnees['periode']];

        return isset($donnees['moment']) ? $quand.', '.self::MOMENTS[$donnees['moment']] : $quand;
    }

    /**
     * Prochains créneaux libres d'une clinique, sur sa semaine, à des heures
     * DISTINCTES et étalés sur les jours (proto : « Auj. 14:00 »,
     * « Dem. 09:15 »…) : les trous libres de chaque jour sont découpés
     * au pas de la durée, on retient d'abord le premier créneau de
     * chaque jour, puis on complète chronologiquement.
     *
     * @param  list<Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>>  $semaine
     * @return list<array{debut: string, ligne_planning_id: int}>
     */
    private function retenir(array $semaine): array
    {
        // En horodatages entiers : des milliers de pas par semaine, une date Carbon par pas coûterait cher.
        $pas = self::DUREE_MINUTES * 60;
        $maintenant = now()->getTimestamp();
        $parJour = [];

        foreach ($semaine as $trous) {
            $candidats = [];

            foreach ($trous as $trou) {
                $fin = $trou['fin']->getTimestamp();
                // Au quart d'heure : aujourd'hui, un trou commence à l'heure qu'il est (« 17:56 »)
                $premier = (int) (ceil($trou['debut']->getTimestamp() / self::QUART) * self::QUART);

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

        return array_map(fn (array $c): array => [
            'debut' => CarbonImmutable::createFromTimestampUTC($c['debut'])->toIso8601String(),
            'ligne_planning_id' => $c['ligne_planning_id'],
        ], $retenus);
    }
}
