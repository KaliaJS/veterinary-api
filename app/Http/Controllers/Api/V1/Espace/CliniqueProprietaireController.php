<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Auth\SousContexteClinique;
use App\Enums\ModeDemandeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\DeposerDemandeRendezVousRequest;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Patient;
use App\Models\SiteWeb;
use App\Services\CalculateurCreneaux;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Prendre rendez-vous : annuaire de toutes les cliniques (carte du
 * proto) avec, pour chacune, ses prochains créneaux libres calculés
 * comme depuis la clinique (SousContexteClinique) ; dépôt d'une
 * demande de rendez-vous dans la file de la clinique — elle confirme
 * ensuite (mode « à valider »), boucle réelle du proto.
 */
class CliniqueProprietaireController extends Controller
{
    private const int CRENEAUX_PAR_CLINIQUE = 3;

    private const int JOURS_EXPLORES = 7;

    private const int DUREE_MINUTES = 20;

    public function index(SousContexteClinique $sousContexte, CalculateurCreneaux $calculateur): JsonResponse
    {
        $cliniques = Clinique::withoutGlobalScopes()
            ->with('societe.organisation')
            ->orderBy('id')
            ->get();

        // Site web publié de l'organisation de chaque clinique (lien depuis l'annuaire).
        $sites = SiteWeb::withoutGlobalScopes()->where('est_publie', true)->pluck('slug', 'organisation_id');

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
            'site_slug' => $sites[$clinique->societe->organisation->id] ?? null,
            'creneaux' => $sousContexte->pour($clinique, fn (): array => $this->creneaux($calculateur)),
        ])->all()]);
    }

    public function deposer(DeposerDemandeRendezVousRequest $request, ContexteProprietaire $contexte, SousContexteClinique $sousContexte): JsonResponse
    {
        $donnees = $request->validated();
        $clinique = Clinique::withoutGlobalScopes()->with('societe')->findOrFail((int) $donnees['clinique_id']);

        $patient = isset($donnees['patient_id'])
            ? Patient::withoutGlobalScopes()->find((int) $donnees['patient_id'])
            : null;

        // Fiche du compte dans l'organisation de la clinique, si elle existe.
        $fiche = $contexte->clients()->firstWhere('organisation_id', $clinique->societe->organisation_id);
        $user = $contexte->user();
        $nomProprietaire = trim((string) ($donnees['nom_proprietaire'] ?? ''))
            ?: ($fiche ? trim($fiche->prenom.' '.$fiche->nom) : $user->name);

        $debut = CarbonImmutable::parse($donnees['debut_souhaite'])->setTimezone($clinique->fuseau_horaire ?? 'Europe/Zurich');

        $demande = $sousContexte->pour($clinique, fn (): DemandeRendezVous => DemandeRendezVous::create([
            'patient_id' => $patient?->id,
            'client_id' => $fiche?->id ?? $patient?->client_id,
            'patient_name' => $patient?->nom ?? $donnees['patient_name'],
            'species' => $patient?->espece ?? $donnees['espece'],
            'owner_name' => $nomProprietaire,
            'reason' => $donnees['motif'],
            'souhait' => ($donnees['mode'] === 'visio' ? 'Téléconsultation vidéo' : 'Sur place').' · '.$debut->format('d.m.Y à H:i').' (espace client)',
            'mode' => ModeDemandeRendezVous::AValider,
            'duree_minutes' => self::DUREE_MINUTES,
        ]));

        return response()->json(['data' => [
            'demande_id' => $demande->id,
            'clinique' => ['id' => $clinique->id, 'nom' => $clinique->nom, 'ville' => $clinique->ville],
            'debut_souhaite' => $debut->toIso8601String(),
            'mode' => $donnees['mode'],
            'patient_name' => $demande->patient_name,
            'motif' => $demande->reason,
        ]], 201);
    }

    /**
     * Prochains créneaux libres de la clinique du contexte, à des heures
     * DISTINCTES et étalés sur les jours (proto : « Auj. 14:00 »,
     * « Dem. 09:15 »…) : les trous libres de chaque jour sont découpés
     * au pas de la durée, on retient d'abord le premier créneau de
     * chaque jour, puis on complète chronologiquement.
     *
     * @return list<array{debut: string, ligne_planning_id: int}>
     */
    private function creneaux(CalculateurCreneaux $calculateur): array
    {
        $jour = $calculateur->aujourdHui();
        $parJour = [];

        for ($i = 0; $i < self::JOURS_EXPLORES; $i++) {
            $candidats = [];

            foreach ($calculateur->creneauxLibres($jour->addDays($i), self::DUREE_MINUTES) as $trou) {
                for ($debut = $trou['debut']; $debut->addMinutes(self::DUREE_MINUTES)->lte($trou['fin']); $debut = $debut->addMinutes(self::DUREE_MINUTES)) {
                    if ($debut->isFuture()) {
                        $candidats[$debut->timestamp] ??= ['debut' => $debut->toIso8601String(), 'ligne_planning_id' => $trou['ligne_planning_id']];
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
                return $retenus;
            }
        }

        $restants = array_merge(...array_map(fn (array $c): array => array_slice($c, 1), $parJour ?: [[]]));
        usort($restants, fn (array $a, array $b): int => strcmp($a['debut'], $b['debut']));

        return array_slice(array_merge($retenus, $restants), 0, self::CRENEAUX_PAR_CLINIQUE);
    }
}
