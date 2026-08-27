<?php

namespace App\Http\Resources\V1;

use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * L'organisation vue par son administrateur : formule et limites
 * (null = illimité), usage du mois avec les packs achetés, statistiques
 * dérivées — rien de tout cela n'est stocké en double.
 *
 * @mixin Organisation
 */
class OrganisationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'plan' => [
                'code' => $this->plan->value,
                'nom' => $this->plan->nom(),
                'prix_mensuel' => $this->plan->prixMensuel(),
                'limites' => $this->plan->limites(),
            ],
            'cycle' => $this->cycle,
            'resilie_le' => $this->resilie_le?->toDateString(),
            'moyen_paiement' => $this->moyen_paiement,
            'usage' => [
                'emails' => $this->usage_emails,
                'sms' => $this->usage_sms,
                'stockage_go' => (float) $this->usage_stockage_go,
            ],
            'quota_extra' => [
                'emails' => $this->quota_extra_emails,
                'sms' => $this->quota_extra_sms,
                'stockage_go' => $this->quota_extra_stockage_go,
            ],
            'stats' => [
                'cliniques' => $this->cliniques()->count(),
                'societes' => $this->societes()->count(),
                'employes_actifs' => $this->employesActifs(),
                'patients' => $this->patients()->count(),
            ],
        ];
    }
}
