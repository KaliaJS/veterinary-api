<?php

namespace App\Services\Clients;

use App\Enums\StatutRendezVous;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Vaccination;
use App\Models\Visite;
use App\Services\EtatVaccinal;
use Illuminate\Support\Collection;

/**
 * L'état de chaque animal d'un propriétaire, en un mot (fenêtre
 * propriétaire, 03.11.2026) : en traitement, vaccin en retard, jamais vu,
 * à jour. Lu dans le dossier, jamais saisi.
 */
final class AnimalStates
{
    /**
     * @param  Collection<int, Patient>  $patients
     * @return list<array{patient_id: int, code: string, libelle: string, detail: ?string}>
     */
    public static function for(Collection $patients): array
    {
        if ($patients->isEmpty()) {
            return [];
        }
        $ids = $patients->pluck('id')->all();
        $vaccinations = Vaccination::query()->with(['produit.schema', 'produit.valences'])->whereIn('patient_id', $ids)->get()->groupBy('patient_id');
        $visites = Visite::query()->whereIn('patient_id', $ids)->distinct()->pluck('patient_id')->all();
        $venus = RendezVous::withoutGlobalScopes()->whereIn('patient_id', $ids)->where('status', StatutRendezVous::Fini)->distinct()->pluck('patient_id')->all();

        $etats = [];
        foreach ($patients as $patient) {
            $valences = EtatVaccinal::parValence($vaccinations->get($patient->id, collect()));
            $retard = collect($valences)->firstWhere('statut', 'retard');
            $prochain = collect($valences)->whereIn('statut', ['proche', 'ok'])->sortBy('echeance_le')->first();
            $etats[] = ['patient_id' => $patient->id, ...match (true) {
                $patient->decede_le !== null => ['code' => 'decede', 'libelle' => 'Décédé', 'detail' => null],
                filled($patient->traitement_en_cours) => ['code' => 'traitement', 'libelle' => 'En traitement', 'detail' => (string) $patient->traitement_en_cours],
                $retard !== null => ['code' => 'retard', 'libelle' => 'Vaccin en retard', 'detail' => $retard['nom'].' échu depuis le '.date('d.m.Y', strtotime((string) $retard['echeance_le']))],
                ! in_array($patient->id, $visites, true) && ! in_array($patient->id, $venus, true) => ['code' => 'jamais_vu', 'libelle' => 'Jamais vu', 'detail' => 'Pas encore venu à la clinique'],
                default => ['code' => 'a_jour', 'libelle' => 'À jour', 'detail' => $prochain !== null ? 'Prochain vaccin le '.date('d.m.Y', strtotime((string) $prochain['echeance_le'])) : null],
            }];
        }

        return $etats;
    }
}
