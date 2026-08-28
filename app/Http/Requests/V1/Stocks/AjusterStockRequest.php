<?php

namespace App\Http\Requests\V1\Stocks;

use App\Enums\MotifAjustementStock;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajustement manuel : écart signé, toujours motivé
 * (docs/data-model.md §14). La clinique est celle du jeton, ou une
 * clinique sœur de l'organisation (`clinique_id`).
 */
class AjusterStockRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantite' => ['required', 'integer', 'between:-9999,9999', 'not_in:0'],
            'motif' => ['required', Rule::enum(MotifAjustementStock::class)],
            'clinique_id' => ['sometimes', 'nullable', 'integer', $this->estUneCliniqueDeLOrganisation()],
        ];
    }

    public function cliniqueId(): ?int
    {
        return $this->filled('clinique_id') ? (int) $this->validated('clinique_id') : null;
    }
}
