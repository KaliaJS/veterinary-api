<?php

namespace App\Services;

use App\Enums\StatutRendezVous;
use App\Models\Intervention;
use App\Models\User;

/**
 * Tranche la divergence « RDV du planning vs modèle métier parallèle »
 * (docs/branchement.md §1.8) : l'intervention est le dossier maître du
 * bloc, le rendez-vous `chir` lié n'est que sa projection planning. La
 * synchronisation est à sens unique — l'intervention pousse horaires,
 * salle et clôture vers le RDV via MutationRendezVous (donc
 * auditée comme n'importe quel geste planning) ; rien ne remonte du
 * planning vers le bloc. Un RDV annulé n'est plus touché.
 */
class ProjectionPlanningBloc
{
    public function __construct(private MutationRendezVous $mutation) {}

    /**
     * Pousse horaires et salle de l'intervention vers le RDV lié. Jamais
     * le patient (24.10.2026) : le rendez-vous reste celui de l'animal
     * pour qui il a été pris, la requête refuse un lien vers un autre.
     */
    public function aligner(Intervention $intervention, ?User $auteur): void
    {
        $rendezVous = $intervention->rendezVous;

        if ($rendezVous === null || $rendezVous->status === StatutRendezVous::Annule) {
            return;
        }

        $attributs = [
            'starts_at' => $intervention->prevu_le,
            'ends_at' => $intervention->prevu_le->copy()->addMinutes($intervention->duree_minutes),
            'salle_id' => $intervention->salle_id,
        ];

        // Parité audit planning : un déplacement n'audite pas le
        // `endsAt` qui suit le début ; un pur changement de durée
        // (redimensionnement), si.
        $champsNonAudites = $rendezVous->starts_at->ne($attributs['starts_at']) ? ['ends_at'] : [];

        $this->mutation->appliquer($rendezVous, $attributs, $auteur, $champsNonAudites);
    }

    /** Reflète un statut du cycle opératoire sur le RDV lié. */
    public function refleterStatut(Intervention $intervention, StatutRendezVous $statut, ?User $auteur): void
    {
        $rendezVous = $intervention->rendezVous;

        if ($rendezVous === null || $rendezVous->status === StatutRendezVous::Annule) {
            return;
        }

        $this->mutation->appliquer($rendezVous, ['status' => $statut], $auteur);
    }
}
