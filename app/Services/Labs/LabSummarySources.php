<?php

namespace App\Services\Labs;

use App\Enums\DrapeauResultatLabo;
use App\Enums\StatutAnalyse;
use App\Models\Analyse;
use App\Models\AnalyseResultat;
use App\Models\Clinique;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Les sources du résumé d'une analyse, numérotées (09.11.2026) : V, chaque
 * valeur de cette analyse avec ses bornes et sa position (dans les normes,
 * à la limite, hors normes) ; T, l'évolution d'une valeur mesurée au moins
 * trois fois ces deux dernières années (règle de Jim), son sens calculé
 * ici, jamais par l'IA. L'IA ne cite que ces numéros.
 */
final class LabSummarySources
{
    /** Au moins tant de mesures, sur tant de mois, pour parler d'évolution */
    public const TREND_MIN = 3;

    public const TREND_MONTHS = 24;

    /** Un écart de moins de 10 % de l'intervalle des normes (ou de la première valeur) : stable */
    private const STABLE_SHARE = 0.1;

    /** @return list<array{id: string, kind: string, label: string, text: string}> */
    public function for(Analyse $analyse): array
    {
        $analyse->loadMissing('resultats');
        $sources = [];
        $v = 0;
        foreach ($analyse->resultats as $r) {
            if ($r->valeur === null) {
                continue;
            }
            $sources[] = ['id' => 'V'.(++$v), 'kind' => 'value', 'label' => $r->parametre, 'text' => $this->valueText($r)];
        }
        $t = 0;
        foreach ($this->trends($analyse) as $trend) {
            $sources[] = ['id' => 'T'.(++$t), 'kind' => 'trend', 'label' => $trend['parametre'], 'text' => $trend['text']];
        }

        return $sources;
    }

    private function valueText(AnalyseResultat $r): string
    {
        $value = self::number($r->valeur).($r->unite ? ' '.$r->unite : '');
        $bounds = ' (normes '.self::number($r->borne_basse).' à '.self::number($r->borne_haute).')';
        $level = DrapeauResultatLabo::niveau((float) $r->valeur, (float) $r->borne_basse, (float) $r->borne_haute);
        $where = match (true) {
            $level === null => ' : dans les normes',
            default => ' : '.($level === 'hors' ? 'hors normes' : 'à la limite').', '.((float) $r->valeur > (float) $r->borne_haute ? 'au-dessus' : 'au-dessous'),
        };

        return $r->parametre.' '.$value.$bounds.$where;
    }

    /**
     * L'évolution, calculée : les mesures de chaque valeur sur deux ans (analyses validées de l'animal, toutes cliniques de l'organisation), au moins trois
     *
     * @return list<array{parametre: string, direction: string, text: string}>
     */
    public function trends(Analyse $analyse): array
    {
        $at = CarbonImmutable::parse($analyse->preleve_le ?? $analyse->valide_le ?? now());
        // Les jours à l'heure de la clinique
        $zone = $analyse->clinique->fuseau_horaire ?? 'Europe/Zurich';
        $clinics = Clinique::withoutGlobalScopes()->whereHas('societe', fn ($q) => $q->where('organisation_id', $analyse->clinique->societe->organisation_id ?? 0))->pluck('id');
        $analyses = Analyse::withoutGlobalScopes()->where('patient_id', $analyse->patient_id)->whereIn('clinique_id', $clinics)
            ->where(fn ($q) => $q->where('statut', StatutAnalyse::Valide->value)->orWhereKey($analyse->id))
            // Jusqu'à cette analyse seulement : une prise de sang du soir n'entre pas dans le résumé de celle du matin
            ->where('preleve_le', '>=', $at->subMonths(self::TREND_MONTHS))
            ->where(fn ($q) => $q->where('preleve_le', '<', $at)->orWhere(fn ($same) => $same->where('preleve_le', $at)->where('id', '<=', $analyse->id)))
            ->with('resultats')->orderBy('preleve_le')->get();
        /** @var Collection<string, list<array{at: CarbonImmutable, name: string, value: float, unit: ?string, low: float, high: float}>> $series */
        $series = collect();
        foreach ($analyses as $a) {
            foreach ($a->resultats as $r) {
                if ($r->valeur === null) {
                    continue;
                }
                $key = mb_strtolower(trim($r->parametre)).'|'.mb_strtolower(trim((string) $r->unite));
                $series[$key] = [...($series[$key] ?? []), ['at' => CarbonImmutable::parse($a->preleve_le), 'name' => $r->parametre, 'value' => (float) $r->valeur, 'unit' => $r->unite, 'low' => (float) $r->borne_basse, 'high' => (float) $r->borne_haute]];
            }
        }
        $out = [];
        // Seulement une valeur mesurée dans cette analyse (une case vide ne fait pas d'évolution)
        $present = $analyse->resultats->filter(fn (AnalyseResultat $r) => $r->valeur !== null)->map(fn (AnalyseResultat $r) => mb_strtolower(trim($r->parametre)).'|'.mb_strtolower(trim((string) $r->unite)))->flip();
        foreach ($series as $key => $points) {
            if (count($points) < self::TREND_MIN || ! $present->has($key)) {
                continue;
            }
            $first = $points[0];
            $last = $points[count($points) - 1];
            // L'écart compté sur l'intervalle des normes ; sans intervalle, sur la première valeur
            $scale = $last['high'] > $last['low'] ? $last['high'] - $last['low'] : abs($first['value']);
            $delta = $last['value'] - $first['value'];
            $threshold = self::STABLE_SHARE * $scale;
            $values = array_column($points, 'value');
            // Monté puis redescendu (ou l'inverse) : « variable », jamais « stable »
            $direction = match (true) {
                $scale > 0 && abs($delta) >= $threshold => $delta > 0 ? 'en hausse' : 'en baisse',
                $scale > 0 && max($values) - min($values) >= $threshold => 'variable',
                default => 'stable',
            };
            $months = (int) round($first['at']->diffInMonths($last['at']));
            $span = $months >= 1 ? ' en '.$months.' mois' : ' en moins d’un mois';
            $list = implode(', ', array_map(fn ($p) => self::number($p['value']).' le '.self::day($p['at'], $zone), $points));
            $out[] = [
                'parametre' => $last['name'],
                'direction' => $direction,
                'text' => $last['name'].' '.$direction.' sur '.count($points).' mesures'.$span.($last['unit'] ? ' ('.$last['unit'].')' : '').' : '.$list,
            ];
        }

        return $out;
    }

    /** Un jour à l'heure de la clinique */
    public static function day(CarbonImmutable $at, string $zone): string
    {
        return $at->setTimezone($zone)->format('d.m.Y');
    }

    public static function number(mixed $value): string
    {
        $n = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return str_replace('.', ',', $n === '-0' ? '0' : $n);
    }
}
