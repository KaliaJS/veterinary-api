<?php

namespace App\Http\Requests\V1\Auth;

use App\Enums\Specialite;
use App\Services\Inscription\VerificationVeterinaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Profil vétérinaire d'un compte : son GLN (facultatif) et ses spécialités (la liste entière). */
class ModifierProfilVeterinaireRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('gln'))) {
            $this->merge(['gln' => preg_replace('/\D/', '', $this->input('gln')) ?: null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gln' => ['nullable', 'string', 'size:13'],
            'specialites' => ['present', 'array'],
            'specialites.*' => ['distinct', Rule::enum(Specialite::class)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $gln = $this->input('gln');
            if ($gln && ! $validator->errors()->has('gln') && ! VerificationVeterinaire::glnValide($gln)) {
                $validator->errors()->add('gln', 'Ce GLN n’est pas valide : vérifiez les 13 chiffres.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['gln.size' => 'Le GLN a 13 chiffres (il commence par 7601).'];
    }
}
