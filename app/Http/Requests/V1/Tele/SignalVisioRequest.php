<?php

namespace App\Http\Requests\V1\Tele;

use App\Services\Tele\SignalisationVisio;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un message de mise en relation WebRTC (vétérinaire ou client du lien). */
class SignalVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(SignalisationVisio::TYPES)],
            // Une description de session (SDP) fait quelques Ko : au-delà de 64 Ko, refusé
            'donnees' => ['present', 'array', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (strlen((string) json_encode($valeur)) > 65536) {
                    $echec('Message de connexion trop long.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Message de connexion inconnu.',
            'donnees.array' => 'Message de connexion illisible.',
        ];
    }
}
