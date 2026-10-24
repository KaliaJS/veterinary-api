<?php

namespace App\Services;

use App\Enums\StatutRendezVous;
use App\Models\ExamenImagerie;
use App\Models\User;

/**
 * Même patron que le bloc (ProjectionPlanningBloc) : l'examen
 * d'imagerie est le dossier maître, le rendez-vous `imagerie` lié
 * n'est que sa projection planning. La synchronisation est à sens
 * unique — l'examen pousse horaires et patient vers le RDV via
 * MutationRendezVous (donc auditée), la signature du compte rendu le
 * clôt ; rien ne remonte du planning vers l'examen. Un RDV annulé
 * n'est plus touché.
 */
class ProjectionPlanningImagerie
{
    public function __construct(private MutationRendezVous $mutation) {}

    /**
     * Pousse les horaires de l'examen vers le RDV lié. Jamais le patient
     * (24.10.2026) : le rendez-vous reste celui de l'animal pour qui il a été
     * pris, la requête refuse un lien vers celui d'un autre.
     */
    public function aligner(ExamenImagerie $examen, ?User $auteur): void
    {
        $rendezVous = $examen->rendezVous;

        if ($rendezVous === null || $rendezVous->status === StatutRendezVous::Annule) {
            return;
        }

        $attributs = [
            'starts_at' => $examen->realise_le,
            'ends_at' => $examen->realise_le->copy()->addMinutes($examen->duree_minutes),
        ];

        // Parité audit planning : un déplacement n'audite pas le
        // `endsAt` qui suit le début ; un pur changement de durée, si.
        $champsNonAudites = $rendezVous->starts_at->ne($attributs['starts_at']) ? ['ends_at'] : [];

        $this->mutation->appliquer($rendezVous, $attributs, $auteur, $champsNonAudites);
    }

    /** Reflète un statut sur le RDV lié (clôture à la signature du CR). */
    public function refleterStatut(ExamenImagerie $examen, StatutRendezVous $statut, ?User $auteur): void
    {
        $rendezVous = $examen->rendezVous;

        if ($rendezVous === null || $rendezVous->status === StatutRendezVous::Annule) {
            return;
        }

        $this->mutation->appliquer($rendezVous, ['status' => $statut], $auteur);
    }
}
