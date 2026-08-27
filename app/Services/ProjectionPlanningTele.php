<?php

namespace App\Services;

use App\Enums\StatutRendezVous;
use App\Models\SessionTeleconsultation;
use App\Models\User;

/**
 * Même patron que le bloc (ProjectionPlanningBloc) : la session de
 * téléconsultation est le dossier maître, le rendez-vous `tele` lié
 * n'est que sa projection planning. La synchronisation est à sens
 * unique via MutationRendezVous (donc auditée) : le démarrage de
 * l'appel passe le RDV en `cours`, la clôture en `fini` — quitter
 * l'appel sans clore ne touche à rien. Un RDV annulé n'est plus
 * touché.
 */
class ProjectionPlanningTele
{
    public function __construct(private MutationRendezVous $mutation) {}

    /** Le démarrage de l'appel passe un RDV prévu ou arrivé en cours. */
    public function demarrer(SessionTeleconsultation $session, ?User $auteur): void
    {
        $rendezVous = $session->rendezVous;

        if ($rendezVous === null
            || ! in_array($rendezVous->status, [StatutRendezVous::Prevu, StatutRendezVous::Salle], true)) {
            return;
        }

        $this->mutation->appliquer($rendezVous, ['status' => StatutRendezVous::Cours], $auteur);
    }

    /** La clôture de l'appel clôt le RDV lié. */
    public function cloturer(SessionTeleconsultation $session, ?User $auteur): void
    {
        $rendezVous = $session->rendezVous;

        if ($rendezVous === null || $rendezVous->status === StatutRendezVous::Annule) {
            return;
        }

        $this->mutation->appliquer($rendezVous, ['status' => StatutRendezVous::Fini], $auteur);
    }
}
