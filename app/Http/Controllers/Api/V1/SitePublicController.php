<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\CalculateurCreneaux;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Site public d'une organisation (visiteur sans compte) : contenu
 * publié et TOUTES ses cliniques, avec coordonnées et horaires lus sur
 * chacune. Les horaires sortent mis en forme (sept jours, plages,
 * statut « ouvert / fermé ») avec les mêmes bornes que le planning —
 * pause comprise, comme à l'accueil.
 */
class SitePublicController extends Controller
{
    private const array JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function afficher(string $slug, CalculateurCreneaux $calculateur): JsonResponse
    {
        $site = SiteWeb::withoutGlobalScopes()->where('slug', $slug)->where('est_publie', true)->firstOrFail();
        $organisation = Organisation::findOrFail($site->organisation_id);
        $cliniques = $organisation->cliniques()->withoutGlobalScopes()->orderBy('cliniques.id')->get();

        return response()->json(['data' => [
            'slug' => $site->slug,
            'couleur' => $site->couleur,
            'accroche' => $site->accroche,
            'presentation' => $site->presentation,
            'email' => $site->email,
            'tel_urgences' => $site->tel_urgences,
            'services' => array_values($site->services ?? []),
            'equipe' => array_values($site->equipe ?? []),
            'actualites' => collect($site->actualites ?? [])->sortByDesc('date')->values()->all(),
            'organisation' => ['nom' => $organisation->nom],
            'cliniques' => $cliniques->map(fn (Clinique $clinique): array => [
                'id' => $clinique->id,
                'nom' => $clinique->nom,
                'rue' => $clinique->rue,
                'npa' => $clinique->npa,
                'ville' => $clinique->ville,
                'tel' => $clinique->tel,
                'latitude' => $clinique->latitude === null ? null : (float) $clinique->latitude,
                'longitude' => $clinique->longitude === null ? null : (float) $clinique->longitude,
                'horaires' => $this->horaires($calculateur, $clinique),
            ])->values()->all(),
        ]]);
    }

    /** @return array{jours: list<array{jour: string, est_aujourdhui: bool, plages: list<string>}>, est_ouvert: bool, statut: string} */
    private function horaires(CalculateurCreneaux $calculateur, Clinique $clinique): array
    {
        $horaires = HoraireOuverture::withoutGlobalScopes()->firstOrCreate(['clinique_id' => $clinique->id], HoraireOuverture::DEFAUTS);
        $maintenant = CarbonImmutable::now($clinique->fuseau_horaire ?? 'Europe/Zurich');
        $lundi = $maintenant->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        $jours = [];
        for ($i = 0; $i < 7; $i++) {
            $jour = $lundi->addDays($i);
            $jours[] = [
                'jour' => self::JOURS[$i],
                'est_aujourdhui' => $jour->isSameDay($maintenant),
                'plages' => $this->plages($calculateur->bornesDuJour($horaires, $jour)),
            ];
        }

        return ['jours' => $jours, ...$this->statut($calculateur, $horaires, $maintenant)];
    }

    /**
     * @param  array{fermee: bool, ouverture: int, fermeture: int, pause: array{int, int}|null}  $bornes
     * @return list<string> « 08:00 – 12:30 », « 14:00 – 19:30 » ; vide si fermé
     */
    private function plages(array $bornes): array
    {
        if ($bornes['fermee']) {
            return [];
        }

        $intervalles = $bornes['pause']
            ? [[$bornes['ouverture'], $bornes['pause'][0]], [$bornes['pause'][1], $bornes['fermeture']]]
            : [[$bornes['ouverture'], $bornes['fermeture']]];

        return array_map(fn (array $p): string => $this->heure($p[0]).' – '.$this->heure($p[1]), $intervalles);
    }

    /** @return array{est_ouvert: bool, statut: string} */
    private function statut(CalculateurCreneaux $calculateur, HoraireOuverture $horaires, CarbonImmutable $maintenant): array
    {
        $minute = $maintenant->hour * 60 + $maintenant->minute;

        for ($i = 0; $i < 8; $i++) {
            $jour = $maintenant->startOfDay()->addDays($i);
            $bornes = $calculateur->bornesDuJour($horaires, $jour);

            if ($bornes['fermee']) {
                continue;
            }

            $intervalles = $bornes['pause']
                ? [[$bornes['ouverture'], $bornes['pause'][0]], [$bornes['pause'][1], $bornes['fermeture']]]
                : [[$bornes['ouverture'], $bornes['fermeture']]];

            foreach ($intervalles as [$debut, $fin]) {
                if ($i === 0 && $minute >= $debut && $minute < $fin) {
                    return ['est_ouvert' => true, 'statut' => 'Ouvert · jusqu’à '.$this->heure($fin)];
                }

                if ($i > 0 || $minute < $debut) {
                    $quand = match ($i) {
                        0 => 'à',
                        1 => 'demain à',
                        default => mb_strtolower(self::JOURS[$jour->dayOfWeekIso - 1]).' à',
                    };

                    return ['est_ouvert' => false, 'statut' => 'Fermé · ouvre '.$quand.' '.$this->heure($debut)];
                }
            }
        }

        return ['est_ouvert' => false, 'statut' => 'Fermé'];
    }

    private function heure(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
