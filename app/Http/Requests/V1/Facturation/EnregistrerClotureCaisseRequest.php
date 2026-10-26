<?php

namespace App\Http\Requests\V1\Facturation;

use App\Services\Facturation\ArreteDeCaisse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Arrêté de caisse : fond initial, espèces comptées, dépôt et nouveau
 * fond. Les totaux par moyen sont calculés côté serveur sur les
 * encaissements non couverts — la garde métier (écart justifié) vit au
 * contrôleur. Pas de rapprochement manuel TPE/TWINT : il reviendra,
 * automatique, avec l'intégration Stripe (Terminal, TWINT).
 */
class EnregistrerClotureCaisseRequest extends FormRequest
{
    use MessagesClotureCaisse;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fond' => ['required', 'numeric', 'min:0', 'max:99999'],
            // Les billets et pièces comptés (centimes : nombre), additionnés
            // par le serveur (26.10.2026) ; vide, rien n'a été compté. Le
            // montant compté reste accepté (anciennes versions de l'app)
            'comptage' => ['nullable', 'array:'.implode(',', ArreteDeCaisse::COUPURES)],
            'comptage.*' => ['integer', 'min:0', 'max:999'],
            'especes_comptees' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // Ce que l'écran montrait : un encaissement arrivé entre-temps est signalé
            'encaissements_vus' => ['nullable', 'integer', 'min:0'],
            'total_vu' => ['nullable', 'numeric'],
            'depot' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'nouveau_fond' => ['required', 'numeric', 'min:0', 'max:99999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
