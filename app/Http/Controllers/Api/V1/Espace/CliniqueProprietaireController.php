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
     * Prochains créneaux libres de la clinique du contexte : les trois
     * premiers sur les sept prochains jours, à partir de maintenant.
     *
     * @return list<array{debut: string, ligne_planning_id: int}>
     */
    private function creneaux(CalculateurCreneaux $calculateur): array
    {
        $creneaux = [];
        $jour = $calculateur->aujourdHui();

        for ($i = 0; $i < self::JOURS_EXPLORES && count($creneaux) < self::CRENEAUX_PAR_CLINIQUE; $i++) {
            $libres = $calculateur->creneauxLibres($jour->addDays($i), self::DUREE_MINUTES)
                ->filter(fn (array $creneau): bool => $creneau['debut']->isFuture())
                ->sortBy(fn (array $creneau) => $creneau['debut']->timestamp);

            foreach ($libres as $creneau) {
                $creneaux[] = ['debut' => $creneau['debut']->toIso8601String(), 'ligne_planning_id' => $creneau['ligne_planning_id']];
                if (count($creneaux) >= self::CRENEAUX_PAR_CLINIQUE) {
                    break;
                }
            }
        }

        return $creneaux;
    }
}
