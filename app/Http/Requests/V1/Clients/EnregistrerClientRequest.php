<?php

namespace App\Http\Requests\V1\Clients;

use App\Enums\Civilite;
use App\Models\Client;
use App\Rules\AdresseSansAccent;
use App\Rules\NumeroBdta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * La civilité est facultative : le formulaire du front ne la demande
 * pas pour un propriétaire créé à la main (docs/data-model.md §5).
 * Fenêtre « Nouveau propriétaire » (03.11.2026) : contact d'urgence,
 * rappels réglés par la clinique, ou invitation dans son espace (avec
 * son e-mail, rien n'est rattaché avant qu'il l'accepte) ; un mobile déjà connu dans l'organisation arrête la
 * création.
 */
class EnregistrerClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'civilite' => ['nullable', Rule::enum(Civilite::class)],
            'prenom' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'tel' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', new AdresseSansAccent, 'required_if_accepted:invite_to_space'],
            'rue' => ['nullable', 'string', 'max:255'],
            'npa' => ['nullable', 'digits:4'],
            'localite' => ['nullable', 'string', 'max:255'],
            'holding_number' => ['nullable', 'string', 'max:32', new NumeroBdta],
            'holding_address' => ['nullable', 'string', 'max:255'],
            'is_farm' => ['sometimes', 'boolean'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:255'],
            // Case cochée par la clinique à la demande du client (28.10.2026)
            'rappels_sms' => ['sometimes', 'boolean'],
            'rappels_email' => ['sometimes', 'boolean'],
            // Un e-mail part, il réglera lui-même ses rappels (décision de Jim, 03.11.2026)
            'invite_to_space' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required_if_accepted' => 'Pour l’inviter dans son espace, il faut son e-mail.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('tel')) {
                    return;
                }
                $existing = Client::withMobile($this->string('tel')->toString() ?: null);
                if ($existing !== null) {
                    $validator->errors()->add('tel', 'Ce mobile est déjà celui de '.$existing->fullName().'.');
                }
            },
        ];
    }
}
