<?php

namespace App\Http\Requests\V1\Videos;

use App\Enums\VideoAudience;
use App\Enums\VideoCategory;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ce qui se vend : titre, description, prix (gratuit, ou 2 CHF au moins, sans maximum), public, catégorie, Plus. */
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
            // Gratuite (0, demande de Jim du 31.10.2026), ou 2 CHF au moins
            'price_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.Video::MAX_PRICE_CENTS, function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_numeric($value) && (int) $value > 0 && (int) $value < Video::MIN_PRICE_CENTS) {
                    $fail('Une vidéo coûte au moins 2 CHF, ou elle est gratuite.');
                }
            }],
            'audience' => ['sometimes', 'nullable', Rule::enum(VideoAudience::class)],
            'category' => ['sometimes', 'nullable', Rule::enum(VideoCategory::class)],
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
            'price_cents.min' => 'Une vidéo coûte au moins 2 CHF, ou elle est gratuite.',
            'audience.enum' => 'Choisissez à qui la vidéo est destinée.',
            'category.enum' => 'Choisissez une catégorie de la liste.',
        ];
    }
}
