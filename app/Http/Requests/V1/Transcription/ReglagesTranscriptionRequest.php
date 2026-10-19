<?php

namespace App\Http\Requests\V1\Transcription;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Réglages de transcription de l'organisation : plafond mensuel en francs
 * HT, mots rares à faire reconnaître (cent au plus, Deepgram n'en prenant
 * qu'une centaine en tout).
 */
class ReglagesTranscriptionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $termes = $this->input('termes');
        if (is_array($termes)) {
            $this->merge(['termes' => array_values(array_filter(array_map(fn ($terme) => is_string($terme) ? trim($terme) : $terme, $termes), fn ($terme) => $terme !== null && $terme !== ''))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plafond' => ['required', 'numeric', 'min:0', 'max:10000'],
            'termes' => ['present', 'array', 'max:100'],
            'termes.*' => ['string', 'max:60', 'distinct:ignore_case'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'termes.max' => 'Cent mots au plus.',
            'termes.*.max' => 'Un mot ou une expression de 60 caractères au plus.',
            'termes.*.distinct' => 'Ce mot figure déjà dans la liste.',
        ];
    }
}
