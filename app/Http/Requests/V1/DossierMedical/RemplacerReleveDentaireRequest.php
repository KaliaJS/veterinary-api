<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Enums\EtatDent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Odontogramme Triadan complet en une map « n° de dent → état » —
 * l'absence de clé vaut sain (la dent est alors retirée du relevé).
 * Les clés sont validées à la main : quadrants 1–8 (adultes 1–4,
 * déciduales 5–8), positions 01–11.
 */
class RemplacerReleveDentaireRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dents' => ['present', 'array'],
            'dents.*' => [Rule::enum(EtatDent::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_keys((array) $this->input('dents', [])) as $dent) {
                $quadrant = intdiv((int) $dent, 100);
                $position = (int) $dent % 100;

                if (! preg_match('/^\d{3}$/', (string) $dent) || $quadrant < 1 || $quadrant > 8 || $position < 1 || $position > 11) {
                    $validator->errors()->add('dents', "« {$dent} » n'est pas un numéro de dent Triadan.");
                }
            }
        });
    }
}
