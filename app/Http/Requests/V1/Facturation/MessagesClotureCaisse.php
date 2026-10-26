<?php

namespace App\Http\Requests\V1\Facturation;

/**
 * Messages en français de la clôture de caisse, pour l'aperçu comme pour
 * l'enregistrement du Z (26.10.2026).
 */
trait MessagesClotureCaisse
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fond.*' => 'Le fond de caisse est un montant de 0 à 99 999 CHF.',
            'nouveau_fond.*' => 'Le nouveau fond est un montant de 0 à 99 999 CHF.',
            'depot.*' => 'Le dépôt est un montant de 0 à 999 999 CHF.',
            'especes_comptees.*' => 'Les espèces comptées sont un montant de 0 à 999 999 CHF.',
            'comptage.array' => 'Ces billets ou pièces n’existent pas en francs suisses.',
            'comptage.*.*' => 'Entre 0 et 999 billets ou pièces de chaque sorte.',
        ];
    }
}
