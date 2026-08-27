<?php

namespace App\Http\Requests\V1\Stocks;

use App\Enums\MotifAjustementStock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajustement manuel : écart signé, toujours motivé
 * (docs/data-model.md §14). La clinique est celle du jeton.
 */
class AjusterStockRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantite' => ['required', 'integer', 'between:-9999,9999', 'not_in:0'],
            'motif' => ['required', Rule::enum(MotifAjustementStock::class)],
        ];
    }
}
