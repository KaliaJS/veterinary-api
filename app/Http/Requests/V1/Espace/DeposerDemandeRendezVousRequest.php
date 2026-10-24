<?php

namespace App\Http\Requests\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\Espece;
use App\Enums\ServiceClinique;
use App\Models\Clinique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Demande de rendez-vous depuis la carte (proto « FINALISER ») : une
 * clinique, un créneau souhaité, un animal du compte OU un nom libre
 * avec son espèce, un motif, sur place ou en visio. Une urgence dit ce
 * que le propriétaire voit (symptômes). Le créneau : le plus tôt
 * possible ou celui choisi dans le calendrier des disponibilités.
 */
class DeposerDemandeRendezVousRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $patients = app(ContexteProprietaire::class)->patientIds();

        return [
            'clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            'debut_souhaite' => self::reglesDebut(),
            'mode' => ['required', Rule::in(['place', 'visio'])],
            'patient_id' => ['nullable', 'integer', Rule::in($patients)],
            'patient_name' => ['required_without:patient_id', 'nullable', 'string', 'max:120'],
            'espece' => ['required_without:patient_id', 'nullable', Rule::enum(Espece::class)],
            'motif' => ['required', 'string', 'max:120'],
            'nom_proprietaire' => ['nullable', 'string', 'max:120'],
            ...self::reglesUrgence(),
        ];
    }

    /** @return array<string, mixed> */
    /**
     * L'heure souhaitée : une date à venir. Qu'elle soit une heure du
     * calendrier (grille de 20 minutes depuis l'ouverture, hors pause, dans
     * le mois qu'il montre) se contrôle au dépôt, avec les horaires de la
     * clinique (SemaineDisponible::estUneHeureProposee).
     *
     * @return list<mixed>
     */
    protected static function reglesDebut(): array
    {
        return ['required', 'date', 'after:now'];
    }

    protected static function reglesUrgence(): array
    {
        return [
            'urgence' => ['sometimes', 'boolean'],
            'symptomes' => ['nullable', 'required_if_accepted:urgence', 'string', 'max:2000'],
        ];
    }

    /** La visio n'est proposée que par les cliniques qui ont la téléconsultation. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('mode') !== 'visio' || $validator->errors()->has('clinique_id')) {
                return;
            }
            $clinique = Clinique::withoutGlobalScopes()->find((int) $this->input('clinique_id'));
            if ($clinique && ! $clinique->aService(ServiceClinique::Teleconsultation)) {
                $validator->errors()->add('mode', 'Cette clinique ne propose pas la téléconsultation.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'patient_name.required_without' => 'Indiquez le nom de votre animal',
            'patient_id.in' => 'Cet animal n’est pas dans votre compte.',
            'symptomes.required_if_accepted' => 'Dites ce que vous voyez : la clinique se prépare avant votre arrivée.',
        ];
    }
}
