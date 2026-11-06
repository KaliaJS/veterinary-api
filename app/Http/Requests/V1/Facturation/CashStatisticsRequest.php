<?php

namespace App\Http\Requests\V1\Facturation;

use App\Auth\ContexteClinique;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * La période des statistiques de la Caisse (06.11.2026) : du et au, en
 * jours du calendrier de la clinique, au plus dix ans, jamais après
 * aujourd'hui ni avant l'an 2000 ; une clinique de l'organisation du jeton,
 * ou toute l'organisation ; une comparaison facultative.
 */
class CashStatisticsRequest extends FormRequest
{
    /** Le plus long écart accepté, en années */
    public const int MAX_YEARS = 10;

    public const string EARLIEST_DAY = '2000-01-01';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $contexte = app(ContexteClinique::class);
        $fuseaux = $contexte->clinique()?->societe->organisation->cliniques()->pluck('cliniques.fuseau_horaire', 'cliniques.id')->all() ?? [];
        $cliniques = array_keys($fuseaux);
        // Aujourd'hui dans le fuseau de la clinique choisie, comme les chiffres (vérification du 06.11.2026)
        $choisie = $this->integer('clinic_id');
        $fuseau = ($this->filled('clinic_id') && array_key_exists($choisie, $fuseaux) ? $fuseaux[$choisie] : $contexte->clinique()?->fuseau_horaire) ?: 'Europe/Zurich';
        $aujourdhui = CarbonImmutable::now($fuseau)->toDateString();

        return [
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST_DAY],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$aujourdhui],
            'clinic_id' => ['nullable', 'integer', Rule::in($cliniques)],
            'compare' => ['nullable', 'string', Rule::in(['previous', 'last_year', 'none'])],
            'history' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }
                $du = CarbonImmutable::parse($this->string('from')->toString());
                $au = CarbonImmutable::parse($this->string('to')->toString());
                if ($du->lessThan($au->subYearsNoOverflow(self::MAX_YEARS)->addDay())) {
                    $validator->errors()->add('from', 'Pas plus de '.self::MAX_YEARS.' ans à la fois.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.required' => 'Choisissez le début de la période.',
            'from.date_format' => 'Le début de la période est illisible.',
            'from.after_or_equal' => 'La période commence au plus tôt le 1er janvier 2000.',
            'to.required' => 'Choisissez la fin de la période.',
            'to.date_format' => 'La fin de la période est illisible.',
            'to.after_or_equal' => 'La fin de la période vient avant son début.',
            'to.before_or_equal' => 'La période ne peut pas finir après aujourd’hui.',
            'clinic_id' => 'Cette clinique n’est pas dans votre organisation.',
            'compare' => 'Cette comparaison n’existe pas.',
            'history.boolean' => 'Choisissez oui ou non.',
        ];
    }
}
