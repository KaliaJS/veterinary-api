<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\Specialite;
use App\Models\Employe;
use App\Models\SpecialiteVeterinaire;
use App\Services\Equipe\SoldesConges;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Fiche de l'équipe (écrans Équipe et Organisation — deux vues de la
 * même table). Ce qu'elle montre suit les droits (audit C29, Jim,
 * 14.11.2026) : « Voir l'équipe » donne l'identité, le rôle, le téléphone
 * et l'e-mail ; « Gérer les absences » ajoute les soldes de congés ;
 * « Gérer l'équipe » le contrat, le taux, le salaire et le licenciement.
 * Sa propre fiche se lit en entier, sauf le salaire et le motif d'un
 * licenciement. Le solde de congés est DÉRIVÉ : droit − pris − congés
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
        $voitContrat = $voitRh || app(ContexteClinique::class)->employe()?->id === $this->id;
        $voitConges = $voitContrat || Gate::allows(DomainePermission::GererAbsences->value);

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
            'specialites' => ($this->user->specialites ?? collect())
                ->sortBy(fn (SpecialiteVeterinaire $ligne): int => array_search($ligne->specialite, Specialite::cases(), true))
                ->map(fn (SpecialiteVeterinaire $ligne): string => $ligne->specialite->value)
                ->values()->all(),
            'photo_url' => $this->photoUrl(),
            'role_id' => $this->role_id,
            'taux' => $this->when($voitContrat, fn () => $this->taux),
            'contrat' => $this->when($voitContrat, fn () => $this->contrat),
            'contrat_statut' => $this->when($voitContrat, fn () => $this->contrat_statut),
            'entree_le' => $this->when($voitContrat, fn (): ?string => $this->entree_le?->toDateString()),
            'tel' => $this->tel,
            'email' => $this->email,
            // Salaire et motif de licenciement : gestion de l'équipe seulement
            'salaire_mensuel' => $this->when($voitRh, fn (): ?float => $this->salaire_mensuel === null ? null : (float) $this->salaire_mensuel),
            'acces_suspendu' => $this->acces_suspendu,
            'licenciement' => $this->when($voitContrat && $this->estLicencie(), fn (): array => [
                'notifie_le' => $this->licenciement_notifie_le->toDateString(),
                'fin_contrat_le' => $this->fin_contrat_le?->toDateString(),
                'motif' => $voitRh ? $this->licenciement_motif : null,
            ]),
            'ligne_planning_id' => $this->whenLoaded('lignePlanning', fn (): ?int => $this->lignePlanning?->id),
            'conges' => $this->when($voitConges && $request->boolean('avec_soldes', true), fn (): array => $this->soldeConges()),
            // Les jours d'avant Veterinary, ajoutés à la première année (É22)
            'conges_report_arrivee' => $this->when($voitConges, fn (): int|float => SoldesConges::nombre((float) $this->conges_report_arrivee)),
        ];
    }
}
