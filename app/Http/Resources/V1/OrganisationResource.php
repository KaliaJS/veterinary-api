<?php

namespace App\Http\Resources\V1;

use App\Enums\FormuleSaas;
use App\Models\Organisation;
use App\Services\Abonnements\AbonnementSaas;
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
            // « Client depuis » (Réglages, Facturation) : l'ouverture de l'organisation, jour de Zurich
            'client_depuis' => $this->created_at?->copy()->timezone('Europe/Zurich')->toDateString(),
            'moyen_paiement' => $this->moyen_paiement,
            /* Abonnement (tenu par l'application) : statut (null : aucun
               abonnement payant ; actif | impaye | resilie), période payée
               (fin : prochain renouvellement, ou fin d'accès si résilié),
               formule moins chère programmée, nouvelle tentative de
               prélèvement après un refus */
            'verification' => $this->verification->value,
            'abonnement' => [
                'statut' => $this->statut_abonnement,
                'periode_debut' => $this->periode_debut?->toDateString(),
                'periode_fin' => $this->periode_fin?->toDateString(),
                'formule_suivante' => ($suivante = FormuleSaas::tryFrom((string) $this->formule_suivante))
                    ? ['code' => $suivante->value, 'nom' => $suivante->nom(), 'prix_mensuel' => $suivante->prixMensuel()]
                    : null,
                'prochaine_tentative_le' => $this->prochaine_tentative_le?->toIso8601String(),
                // Renouvellement refusé : l'accès se ferme ce jour-là sans paiement
                'fermeture_le' => AbonnementSaas::fermetureLe($this->resource)?->toDateString(),
                'carte_expire_le' => $this->carte_expire_le?->toDateString(),
            ],
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
