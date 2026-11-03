<?php

namespace App\Services\Patients;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une clinique suit-elle cet animal ? (02.11.2026, étape 0 de la fusion des
 * dossiers) Oui dès qu'une ligne d'une clinique le nomme : rendez-vous,
 * facture, demande, ou acte fait par une organisation (provenance). Toutes
 * les tables qui ont un `patient_id` sont lues : une table ajoutée demain
 * compte d'elle-même.
 */
final class ClinicFollow
{
    /** @var array<string, list<string>>|null table => colonnes qui disent la clinique */
    private ?array $tables = null;

    public function isFollowed(Patient $patient): bool
    {
        foreach ($this->tables() as $table => $colonnes) {
            $suivi = DB::table($table)
                ->where('patient_id', $patient->id)
                ->where(function ($requete) use ($colonnes): void {
                    foreach ($colonnes as $colonne) {
                        $requete->orWhereNotNull($colonne);
                    }
                })
                ->exists();
            if ($suivi) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<string>> */
    private function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $this->tables = [];
        foreach (Schema::getTables() as $table) {
            $nom = $table['name'];
            $colonnes = Schema::getColumnListing($nom);
            if ($nom === 'patients' || ! in_array('patient_id', $colonnes, true)) {
                continue;
            }
            $cles = array_values(array_intersect(['organisation_id', 'clinique_id', 'source_organisation_id'], $colonnes));
            if ($cles !== []) {
                $this->tables[$nom] = $cles;
            }
        }

        return $this->tables;
    }
}
