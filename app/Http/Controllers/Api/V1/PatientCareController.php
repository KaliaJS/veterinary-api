<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Intervention;
use App\Models\Patient;
use App\Models\Sejour;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Soins et interventions du dossier (06.11.2026) : les interventions du
 * bloc et les séjours d'hospitalisation de l'animal, de toutes les
 * cliniques de son organisation (le dossier médical est partagé), du plus
 * récent au plus ancien. Une lecture seule : rien n'est écrit en base, pas
 * même la feuille du jour qu'ouvre la liste des séjours de la clinique.
 * Une intervention ou un séjour d'une clinique d'une autre organisation
 * n'est jamais servi.
 */
class PatientCareController extends Controller
{
    /** Le plus de lignes lues par sorte : une vie d'animal n'en approche pas */
    public const int LIMIT = 500;

    public function __invoke(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $clinics = Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($q) => $q->where('organisation_id', $patient->organisation_id))
            ->get(['id', 'nom', 'fuseau_horaire'])
            ->keyBy('id');

        $surgeries = Intervention::withoutGlobalScopes()
            ->with(['chirurgien' => fn ($q) => $q->withoutGlobalScope('clinique')])
            ->where('patient_id', $patient->id)
            ->whereIn('clinique_id', $clinics->keys())
            ->orderByDesc('prevu_le')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Intervention $i) use ($clinics): array {
                $clinic = $clinics->get($i->clinique_id);

                return [
                    'type' => 'intervention', 'id' => $i->id, 'date' => $i->prevu_le->toIso8601String(),
                    // Le jour au fuseau de la clinique : le bloc s'ouvre sur ce jour-là
                    'jour' => CarbonImmutable::instance($i->prevu_le)->setTimezone($clinic?->fuseau_horaire ?: 'Europe/Zurich')->toDateString(),
                    'titre' => $i->procedure,
                    'statut' => $i->statut->value,
                    'chirurgien' => $i->chirurgien?->nom,
                    'salle' => $i->salle_nom,
                    'duree_minutes' => $i->duree_minutes,
                    'consentement_recueilli' => $i->consentement_signe_le !== null,
                    'clinique_id' => $i->clinique_id,
                    'clinique' => $clinic?->nom,
                ];
            });

        $stays = Sejour::withoutGlobalScopes()
            ->with(['veterinaire' => fn ($q) => $q->withoutGlobalScope('clinique')])
            ->where('patient_id', $patient->id)
            ->whereIn('clinique_id', $clinics->keys())
            ->orderByDesc('admis_le')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Sejour $s) use ($clinics): array {
                $clinic = $clinics->get($s->clinique_id);
                // Les jours du séjour, comptés comme la facture (joursFactures), sans relire la clinique
                $s->setRelation('clinique', $clinic);

                return [
                    'type' => 'sejour', 'id' => $s->id, 'date' => $s->admis_le->toIso8601String(),
                    'titre' => $s->motif ?: 'Hospitalisation',
                    'statut' => $s->statut->value,
                    'en_cours' => ! $s->estSorti(),
                    'sorti_le' => $s->sorti_le?->toIso8601String(),
                    'box' => $s->box,
                    'veterinaire' => $s->veterinaire?->nom,
                    'jours' => $s->joursFactures(),
                    'clinique_id' => $s->clinique_id,
                    'clinique' => $clinic?->nom,
                ];
            });

        return response()->json([
            'data' => $surgeries->concat($stays)
                ->sort(fn (array $a, array $b): int => [CarbonImmutable::parse($b['date'])->getTimestamp(), $b['type'], $b['id']] <=> [CarbonImmutable::parse($a['date'])->getTimestamp(), $a['type'], $a['id']])
                ->values()
                ->all(),
        ]);
    }
}
