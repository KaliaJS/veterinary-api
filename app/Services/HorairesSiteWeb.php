<?php

namespace App\Services;

use App\Models\Clinique;
use App\Models\HoraireOuverture;
use Carbon\CarbonImmutable;

/**
 * Horaires d'une clinique mis en forme pour le site web (public et
 * aperçu de l'éditeur) : sept jours, plages « 08:00 – 12:30 », statut
 * « Ouvert · jusqu'à … » / « Fermé · ouvre … » dans le fuseau de la
 * clinique — mêmes bornes que le planning, pause comprise.
 */
class HorairesSiteWeb
{
    private const array JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function __construct(private CalculateurCreneaux $calculateur) {}

    /**
     * Lecture seule : sans réglages enregistrés, les valeurs par défaut
     * s'appliquent sans être écrites (l'éditeur lit aussi les cliniques
     * sœurs, hors de la clinique du jeton).
     *
     * @return array{jours: list<array{jour: string, est_aujourdhui: bool, plages: list<string>}>, est_ouvert: bool, statut: string}
     */
    public function pour(Clinique $clinique): array
    {
        $horaires = HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $clinique->id)->first()
            ?? new HoraireOuverture(HoraireOuverture::DEFAUTS);
        $maintenant = CarbonImmutable::now($clinique->fuseau_horaire ?? 'Europe/Zurich');
        $lundi = $maintenant->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        $jours = [];
        for ($i = 0; $i < 7; $i++) {
            $jour = $lundi->addDays($i);
            $jours[] = [
                'jour' => self::JOURS[$i],
                'est_aujourdhui' => $jour->isSameDay($maintenant),
                'plages' => $this->plages($this->calculateur->bornesDuJour($horaires, $jour)),
            ];
        }

        return ['jours' => $jours, ...$this->statut($horaires, $maintenant)];
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
    private function statut(HoraireOuverture $horaires, CarbonImmutable $maintenant): array
    {
        $minute = $maintenant->hour * 60 + $maintenant->minute;

        for ($i = 0; $i < 8; $i++) {
            $jour = $maintenant->startOfDay()->addDays($i);
            $bornes = $this->calculateur->bornesDuJour($horaires, $jour);

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
