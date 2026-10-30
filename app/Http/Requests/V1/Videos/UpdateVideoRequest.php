<?php

namespace App\Http\Requests\V1\Videos;

use App\Enums\VideoAudience;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ce qui se vend : titre, description, prix (2 CHF au moins, sans maximum), public, Plus. */
class UpdateVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price_cents' => ['sometimes', 'nullable', 'integer', 'min:'.Video::MIN_PRICE_CENTS, 'max:4000000000'],
            'audience' => ['sometimes', 'nullable', Rule::enum(VideoAudience::class)],
            'in_plus' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.max' => 'Le titre dépasse 160 caractères.',
            'description.max' => 'La description dépasse 5000 caractères.',
            'price_cents.integer' => 'Le prix est un montant en centimes.',
            'price_cents.min' => 'Une vidéo coûte au moins 2 CHF.',
            'audience.enum' => 'Choisissez à qui la vidéo est destinée.',
        ];
    }
}
