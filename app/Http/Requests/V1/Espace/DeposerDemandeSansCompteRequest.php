<?php

namespace App\Http\Requests\V1\Espace;

use App\Enums\Espece;
use App\Models\Clinique;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Demande de rendez-vous sans compte Veterinary, seulement chez une
 * clinique qui l'accepte (`rdv_sans_compte`, non par défaut) : l'animal
 * par son nom et son espèce, le propriétaire par son nom, son e-mail et
 * son téléphone, pour que la clinique puisse lui répondre.
 */
class DeposerDemandeSansCompteRequest extends DeposerDemandeRendezVousRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            'debut_souhaite' => self::reglesDebut(),
            'mode' => ['required', Rule::in(['place', 'visio'])],
            'patient_name' => ['required', 'string', 'max:120'],
            'espece' => ['required', Rule::enum(Espece::class)],
            'motif' => ['required', 'string', 'max:120'],
            'nom_proprietaire' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'telephone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9 ().\/-]{8,}$/'],
            // Le code reçu à cette adresse (public/demandes/code) : elle est prouvée
            'code' => ['required', 'digits:6'],
            ...self::reglesUrgence(),
        ];
    }

    /** En plus de la visio : la clinique doit accepter les demandes sans compte. */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($validator->errors()->has('clinique_id')) {
                    return;
                }
                $clinique = Clinique::withoutGlobalScopes()->find((int) $this->input('clinique_id'));
                if ($clinique && ! $clinique->rdv_sans_compte) {
                    $validator->errors()->add('clinique_id', 'Cette clinique demande un compte Veterinary pour prendre rendez-vous.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'patient_name.required' => 'Indiquez le nom de votre animal',
            'nom_proprietaire.required' => 'Indiquez votre nom',
            'email.required' => 'Indiquez votre e-mail : la clinique vous répond par là.',
            'telephone.required' => 'Indiquez un numéro où la clinique peut vous joindre.',
            'code.required' => 'Saisissez le code reçu par e-mail.',
            'code.digits' => 'Le code compte six chiffres.',
            'telephone.regex' => 'Ce numéro de téléphone ne semble pas complet.',
        ];
    }
}
