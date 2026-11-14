<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Models\Client;
use App\Services\Clients\AnimalStates;
use App\Services\Clients\OwnerPreferences;
use App\Services\Clients\OwnerSpaceInvitations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Les champs agrégés (nombre d'animaux, solde dû = reste à payer de ses factures dues,
 * no-shows, client depuis = min des fiches) ne sortent que lorsque le
 * contrôleur les a chargés — jamais recalculés ici. Le solde dû, seulement
 * avec le droit de la facturation (audit C10, 14.11.2026).
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pourLEquipe = app(ContexteClinique::class)->estDefini();

        return [
            'id' => $this->id,
            'civilite' => $this->civilite,
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'nom_affiche' => $this->nom_affiche,
            'tel' => $this->tel,
            'email' => $this->email,
            'rappels_sms' => (bool) $this->rappels_sms,
            'rue' => $this->rue,
            'npa' => $this->npa,
            'localite' => $this->localite,
            // L'unité d'élevage (SI ABV, 08.11.2026)
            'holding_number' => $this->holding_number,
            'holding_address' => $this->holding_address,
            // Une exploitation d'animaux de rente : l'onglet Exploitation (09.11.2026)
            'is_farm' => (bool) $this->is_farm,
            'a_un_compte' => $this->user_id !== null,
            // Le compte, pour lui écrire depuis sa fiche (messagerie, 28.10.2026)
            'compte_id' => $this->user_id,
            'nombre_patients' => $this->whenHas('patients_count'),
            'noms_patients' => $this->when(
                $this->relationLoaded('patientsParNom') || $this->relationLoaded('patients'),
                fn (): array => ($this->relationLoaded('patientsParNom') ? $this->patientsParNom : $this->patients->sortBy('nom'))
                    ->pluck('nom')
                    ->values()
                    ->all(),
            ),
            'solde_du' => $this->when(Gate::allows(DomainePermission::Facturation->value), fn () => $this->whenHas('solde_du', fn () => round((float) $this->solde_du, 2))),
            'no_shows' => $this->whenHas('no_shows_count'),
            'client_depuis' => $this->whenHas('patients_min_client_depuis'),
            'patients' => PatientResource::collection($this->whenLoaded('patients')),
            // Fenêtre propriétaire (03.11.2026) : l'état de chaque animal, le contact
            // d'urgence, la note de l'accueil, les rappels et préférences. Pour
            // l'équipe d'une clinique seulement, jamais dans l'espace du propriétaire
            'etats_animaux' => $this->when($pourLEquipe && $this->relationLoaded('patients'), fn (): array => AnimalStates::for($this->patients)),
            'emergency_contact' => $this->when($pourLEquipe, $this->emergency_contact),
            'emergency_phone' => $this->when($pourLEquipe, $this->emergency_phone),
            'note' => $this->when($pourLEquipe, $this->note),
            // L'invitation dans l'espace en attente (03.11.2026) : l'adresse et la
            // date, jamais le jeton ni son haché
            'space_invitation' => $this->when($pourLEquipe, fn (): ?array => $this->space_invitation_token_hash === null ? null : [
                'email' => $this->space_invitation_email,
                'sent_at' => $this->space_invitation_sent_at?->toIso8601String(),
                // Lien plus valable après 30 jours : la clinique le voit et peut renvoyer
                'is_expired' => $this->space_invitation_sent_at === null || $this->space_invitation_sent_at->lt(now()->subDays(OwnerSpaceInvitations::VALID_DAYS)),
            ]),
            // La version de la fiche : le web écarte une lecture plus ancienne
            // qu'un enregistrement déjà reçu (03.11.2026)
            'version' => (int) $this->version,
            'preferences' => $this->when($pourLEquipe && $this->relationLoaded('patients'), fn (): array => OwnerPreferences::forClient($this->resource)),
        ];
    }
}
