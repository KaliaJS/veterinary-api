<?php

namespace App\Http\Requests\V1\Site;

use App\Models\SiteWeb;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Domaine saisi tel quel (« https://www.Clinique.ch/ » accepté) puis
 * normalisé : minuscules, sans protocole, chemin, port ni point final ;
 * nom accentué converti (punycode). Unique, et jamais un de nos domaines.
 */
class ConnecterDomaineRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $brut = strtolower(trim((string) $this->input('domaine')));
        $brut = preg_replace('#^[a-z]+://#', '', $brut);
        $brut = preg_replace('#[/?\#:].*$#', '', $brut);
        $brut = rtrim($brut, '.');

        if ($brut !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $brut)) {
            $brut = idn_to_ascii($brut, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $brut;
        }

        $this->merge(['domaine' => $brut]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $site = SiteWeb::query()->first();

        return [
            'domaine' => [
                'required',
                'max:253',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/',
                Rule::notIn(config('sites.domaines.reserves')),
                // Ni un sous-domaine des nôtres (23.10.2026)
                function (string $attribut, mixed $valeur, Closure $echec): void {
                    foreach (config('sites.domaines.reserves') as $reserve) {
                        $base = implode('.', array_slice(explode('.', (string) $reserve), -2));
                        if ($base !== '' && ($valeur === $base || str_ends_with((string) $valeur, '.'.$base))) {
                            $echec('Ce domaine ne peut pas être utilisé.');

                            return;
                        }
                    }
                },
                Rule::unique('sites_web', 'domaine')->ignore($site?->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'domaine.required' => 'Indiquez votre nom de domaine.',
            'domaine.regex' => 'Ce nom de domaine n’est pas valide (exemple : www.ma-clinique.ch).',
            'domaine.not_in' => 'Ce domaine ne peut pas être utilisé.',
            'domaine.unique' => 'Ce domaine est déjà relié à un autre site.',
        ];
    }
}
