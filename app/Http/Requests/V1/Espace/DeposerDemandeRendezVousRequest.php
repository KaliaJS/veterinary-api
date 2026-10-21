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
 * que le propriétaire voit (symptômes). Le moment : un créneau libre de
 * l'annuaire, ou un souhait (« Personnaliser ») : un jour précis ou un
 * délai, et le moment de la journée.
 */
class DeposerDemandeRendezVousRequest extends FormRequest
{
    /** Délais proposés quand aucun créneau ne convient */
    public const PERIODES = ['cette_semaine', 'semaine_prochaine', 'deux_semaines', 'un_mois'];

    public const MOMENTS = ['matin', 'apres_midi'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $patients = app(ContexteProprietaire::class)->patientIds();

        return [
            'clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            ...self::reglesQuand(),
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
    protected static function reglesQuand(): array
    {
        return [
            'debut_souhaite' => ['required_without_all:jour_souhaite,periode', 'nullable', 'date', 'after:now'],
            'jour_souhaite' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'periode' => ['nullable', Rule::in(self::PERIODES)],
            'moment' => ['nullable', Rule::in(self::MOMENTS)],
        ];
    }

    /** @return array<string, mixed> */
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
            'debut_souhaite.required_without_all' => 'Choisissez un créneau, ou dites quand vous arrange.',
            'jour_souhaite.after_or_equal' => 'Choisissez un jour à venir.',
        ];
    }
}
