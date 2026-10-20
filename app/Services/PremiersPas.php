<?php

namespace App\Services;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Organisation;
use App\Models\Salle;
use App\Models\SiteWeb;
use App\Models\TerminalPaiement;
use Illuminate\Validation\ValidationException;

/**
 * Premiers pas d'une clinique neuve, pour ses administrateurs : ce qui
 * reste à mettre en place avant de travailler. Chaque étape se déduit des
 * données (une salle existe, l'équipe compte plus d'une personne…) ; celles
 * dont la valeur de départ peut suffire (horaires, rappels) ou qui sont
 * facultatives (caisse, site) se confirment aussi à la main. La liste
 * peut être masquée.
 */
final class PremiersPas
{
    /** Étapes, dans l'ordre affiché ; les confirmables acceptent « c'est bon ». */
    public const array ETAPES = [
        'praticiens' => false,
        'salles' => false,
        'horaires' => true,
        'equipe' => true,
        'carte' => false,
        'rappels' => true,
        'caisse' => true,
        'site' => true,
    ];

    /** @return array{masque: bool, etapes: list<array{cle: string, fait: bool, confirmable: bool}>} */
    public function etat(Clinique $clinique): array
    {
        $organisation = $clinique->societe->organisation;
        $confirmees = $organisation->premiers_pas['confirmees'] ?? [];
        $confirmee = fn (string $cle): bool => in_array($this->cleConfirmee($cle, $clinique), $confirmees, true);

        $faites = [
            'praticiens' => LignePlanning::withoutGlobalScopes()->where('clinique_id', $clinique->id)->whereNotNull('employe_id')->exists(),
            'salles' => Salle::withoutGlobalScopes()->where('clinique_id', $clinique->id)->exists(),
            'horaires' => $confirmee('horaires') || HoraireOuverture::withoutGlobalScopes()
                ->where('clinique_id', $clinique->id)
                ->whereColumn('updated_at', '>', 'created_at')
                ->exists(),
            'equipe' => $confirmee('equipe') || Employe::withoutGlobalScopes()
                ->whereIn('clinique_id', $organisation->cliniques()->pluck('cliniques.id'))
                ->count() > 1,
            'carte' => $clinique->latitude !== null && $clinique->longitude !== null,
            'rappels' => $confirmee('rappels'),
            'caisse' => $confirmee('caisse') || TerminalPaiement::withoutGlobalScopes()->where('clinique_id', $clinique->id)->exists(),
            'site' => $confirmee('site') || SiteWeb::withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('est_publie', true)->exists(),
        ];

        return [
            'masque' => (bool) ($organisation->premiers_pas['masque'] ?? false),
            'etapes' => array_map(
                fn (string $cle): array => ['cle' => $cle, 'fait' => $faites[$cle], 'confirmable' => self::ETAPES[$cle]],
                array_keys(self::ETAPES),
            ),
        ];
    }

    /** @throws ValidationException */
    public function confirmer(Clinique $clinique, string $cle): void
    {
        if (! (self::ETAPES[$cle] ?? false)) {
            throw ValidationException::withMessages(['confirmer' => 'Cette étape se termine dans l’application.']);
        }

        $organisation = $clinique->societe->organisation;
        $etat = $organisation->premiers_pas ?? [];
        $etat['confirmees'] = array_values(array_unique([...($etat['confirmees'] ?? []), $this->cleConfirmee($cle, $clinique)]));
        $organisation->forceFill(['premiers_pas' => $etat])->save();
    }

    public function masquer(Organisation $organisation, bool $masque): void
    {
        $organisation->forceFill(['premiers_pas' => [...($organisation->premiers_pas ?? []), 'masque' => $masque]])->save();
    }

    /** Les horaires et la caisse sont propres à chaque clinique ; le reste vaut pour l'organisation. */
    private function cleConfirmee(string $cle, Clinique $clinique): string
    {
        return in_array($cle, ['horaires', 'caisse'], true) ? "$cle:{$clinique->id}" : $cle;
    }
}
