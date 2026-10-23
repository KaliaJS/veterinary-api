<?php

namespace App\Http\Resources\V1;

use App\Enums\DomainePermission;
use App\Enums\Specialite;
use App\Models\Employe;
use App\Models\SpecialiteVeterinaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Fiche RH complète (écrans Équipe et Organisation — deux vues de la
 * même table). Le solde de congés est DÉRIVÉ : droit − pris − congés
 * validés à venir ; il ne sort que sur demande (show / liste équipe).
 *
 * @mixin Employe
 */
class EmployeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $voitRh = Gate::allows(DomainePermission::Rh->value);

        return [
            'id' => $this->id,
            // Cliniques où la personne travaille (affectations)
            'cliniques' => $this->cliniques->pluck('id')->values()->all(),
            'nom' => $this->nom,
            'initiales' => $this->initiales,
            'role_libelle' => $this->role_libelle,
            // Spécialités : celles d'un vétérinaire de métier (intitulé)
            'est_veterinaire' => $this->estVeterinaire(),
            // Badge « Vérifié manuellement » de la plateforme
            'verifie' => (bool) $this->user?->estVerifie(),
            // Celles que le vétérinaire déclare sur son compte
            'specialites' => ($this->user?->specialites ?? collect())
                ->sortBy(fn (SpecialiteVeterinaire $ligne): int => array_search($ligne->specialite, Specialite::cases(), true))
                ->map(fn (SpecialiteVeterinaire $ligne): string => $ligne->specialite->value)
                ->values()->all(),
            'photo_url' => $this->photoUrl(),
            'role_id' => $this->role_id,
            'taux' => $this->taux,
            'contrat' => $this->contrat,
            'contrat_statut' => $this->contrat_statut,
            'entree_le' => $this->entree_le?->toDateString(),
            'tel' => $this->tel,
            'email' => $this->email,
            // Salaire et motif de licenciement : gestion de l'équipe seulement
            'salaire_mensuel' => $this->when($voitRh, fn (): ?float => $this->salaire_mensuel === null ? null : (float) $this->salaire_mensuel),
            'acces_suspendu' => $this->acces_suspendu,
            'licenciement' => $this->when($this->estLicencie(), fn (): array => [
                'notifie_le' => $this->licenciement_notifie_le->toDateString(),
                'fin_contrat_le' => $this->fin_contrat_le?->toDateString(),
                'motif' => $voitRh ? $this->licenciement_motif : null,
            ]),
            'ligne_planning_id' => $this->whenLoaded('lignePlanning', fn (): ?int => $this->lignePlanning?->id),
            'conges' => $this->when((bool) $request->boolean('avec_soldes', true), fn (): array => $this->soldeConges()),
        ];
    }
}
