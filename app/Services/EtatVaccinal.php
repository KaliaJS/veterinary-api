<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Vaccination;
use App\Services\Catalogue\DonneesVaccins;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * État vaccinal d un patient, valence par valence (16.10.2026) : à partir de
 * l historique complet des injections (chacune garde ses valences au moment
 * de l injection), la dernière injection couvrant chaque maladie, son
 * échéance et son statut :
 * - `primo` : primo-vaccination en cours (le schéma du produit demande
 *   plusieurs injections, pas encore toutes faites) ;
 * - `retard` : échéance dépassée ; `proche` : dans RAPPEL_JOURS ; `ok` ;
 * - `inconnu` : sans échéance (sérum, schéma particulier).
 * Sert à l onglet clinique et au carnet du propriétaire.
 */
final class EtatVaccinal
{
    public const RAPPEL_JOURS = 30;

    /** @var array<string, int> ordre d affichage des statuts */
    private const ORDRE = ['retard' => 0, 'primo' => 1, 'proche' => 2, 'inconnu' => 3, 'ok' => 4];

    /**
     * @param  Collection<int, Vaccination>  $vaccinations  chargées avec `produit.schema` et `produit.valences`
     * @return list<array<string, mixed>>
     */
    public static function parValence(Collection $vaccinations, ?Carbon $aujourdhui = null): array
    {
        $aujourdhui ??= aujourdhui();
        $parValence = [];
        $noms = [];
        foreach ($vaccinations->sortBy('injectee_le')->values() as $vaccination) {
            foreach (self::valencesDe($vaccination) as $code => $nom) {
                $parValence[$code][] = $vaccination;
                $noms[$code] = $nom;
            }
        }
        $etats = [];
        foreach ($parValence as $code => $injections) {
            /** @var Vaccination $derniere */
            $derniere = end($injections);
            $primo = self::primoEnCours($code, $injections, $aujourdhui);
            $echeance = $primo['echeance'] ?? $derniere->echeance_le;
            $jours = $echeance ? (int) $aujourdhui->diffInDays($echeance, false) : null;
            $statut = $primo !== null ? 'primo'
                : ($jours === null ? 'inconnu' : ($jours < 0 ? 'retard' : ($jours <= self::RAPPEL_JOURS ? 'proche' : 'ok')));
            $etats[] = [
                'valence' => $code,
                'nom' => $noms[$code]['nom'],
                'nom_court' => $noms[$code]['court'],
                'statut' => $statut,
                'jours' => $jours,
                'echeance_le' => $echeance?->toDateString(),
                'injectee_le' => $derniere->injectee_le->toDateString(),
                'produit' => $derniere->marque ?: $derniere->produit?->nom_court,
                'lot' => $derniere->lot,
                'vaccination_id' => $derniere->id,
                'injections' => count($injections),
                'primo' => $primo ? ['faites' => $primo['faites'], 'attendues' => $primo['attendues']] : null,
            ];
        }
        usort($etats, fn (array $a, array $b): int => [self::ORDRE[$a['statut']], $a['echeance_le'] ?? '9999'] <=> [self::ORDRE[$b['statut']], $b['echeance_le'] ?? '9999']);

        return $etats;
    }

    /**
     * Échéance à poser sur une nouvelle injection d un produit du
     * catalogue : prochaine dose de la primo-vaccination si elle n est pas
     * finie, sinon premier rappel ou rappel courant du schéma. Null quand
     * le schéma n en prévoit pas (sérum, schéma par gestation).
     *
     * @param  Collection<int, Vaccination>  $anterieures  injections du patient déjà enregistrées
     */
    public static function echeanceProposee(Patient $patient, Vaccination $nouvelle, Collection $anterieures): ?Carbon
    {
        $schema = $nouvelle->produit?->schema;
        if ($schema === null) {
            return null;
        }
        $injectee = $nouvelle->injectee_le ?? aujourdhui();
        $codes = array_keys(self::valencesDe($nouvelle));
        $couvrantes = $anterieures
            ->filter(fn (Vaccination $v) => $v->injectee_le->lte($injectee))
            ->filter(fn (Vaccination $v) => array_intersect($codes, array_keys(self::valencesDe($v))) !== [])
            ->sortBy('injectee_le')->values();
        // Rappel d un animal déjà protégé : une injection couvrante assez
        // récente (moins de deux fois l intervalle de rappel, 2 ans au
        // moins), même en retard, ne relance pas la primo-vaccination.
        $derniere = $couvrantes->last();
        $tolerance = max(24, 2 * ($schema->rappel_mois ?? 12));
        if ($derniere && $derniere->injectee_le->gte($injectee->copy()->subMonths($tolerance))) {
            $fenetre = $injectee->copy()->subWeeks(max(8, 2 * ($schema->primo_intervalle_semaines ?? 4) * max(1, ($schema->primo_injections ?? 1) - 1)));
            $recentes = $couvrantes->filter(fn (Vaccination $v) => $v->injectee_le->gte($fenetre))->count();
            if (($schema->primo_injections ?? 1) > 1 && $recentes > 0 && 1 + $recentes < $schema->primo_injections) {
                return $injectee->copy()->addWeeks($schema->primo_intervalle_semaines ?? 4);
            }
            if ($recentes > 0 && $couvrantes->count() <= max(0, ($schema->primo_injections ?? 1) - 1) && $schema->rappel_premier_mois) {
                return $injectee->copy()->addMonths($schema->rappel_premier_mois);
            }

            return $schema->rappel_mois ? $injectee->copy()->addMonths($schema->rappel_mois) : null;
        }
        // Primo-vaccination (jamais vacciné, ou protection trop ancienne)
        if (($schema->primo_injections ?? 1) > 1) {
            return $injectee->copy()->addWeeks($schema->primo_intervalle_semaines ?? 4);
        }
        if ($schema->rappel_premier_mois) {
            return $injectee->copy()->addMonths($schema->rappel_premier_mois);
        }

        return $schema->rappel_mois ? $injectee->copy()->addMonths($schema->rappel_mois) : null;
    }

    /**
     * Primo-vaccination en cours pour cette valence : le schéma de la
     * dernière injection demande N doses, et moins de N ont été faites
     * dans la fenêtre récente ; l échéance est la prochaine dose.
     *
     * @param  list<Vaccination>  $injections
     * @return array{faites: int, attendues: int, echeance: Carbon}|null
     */
    private static function primoEnCours(string $code, array $injections, Carbon $aujourdhui): ?array
    {
        $derniere = end($injections);
        $schema = $derniere->produit?->schema;
        if ($schema === null || ($schema->primo_injections ?? 1) < 2) {
            return null;
        }
        $intervalle = $schema->primo_intervalle_semaines ?? 4;
        $fenetre = $derniere->injectee_le->copy()->subWeeks(2 * $intervalle * ($schema->primo_injections - 1) + 4);
        $faites = count(array_filter($injections, fn (Vaccination $v) => $v->injectee_le->gte($fenetre)));
        if ($faites >= $schema->primo_injections) {
            return null;
        }
        // Rappel d un animal déjà protégé (injection plus ancienne mais
        // pas trop) : pas une primo-vaccination
        $tolerance = max(24, 2 * ($schema->rappel_mois ?? 12));
        $ancienne = array_filter($injections, fn (Vaccination $v) => $v->injectee_le->lt($fenetre) && $v->injectee_le->gte($derniere->injectee_le->copy()->subMonths($tolerance)));
        if ($ancienne !== []) {
            return null;
        }
        // Primo abandonnée depuis longtemps : on juge sur l échéance normale
        if ($derniere->injectee_le->copy()->addWeeks(3 * $intervalle)->lt($aujourdhui)) {
            return null;
        }

        return ['faites' => $faites, 'attendues' => $schema->primo_injections, 'echeance' => $derniere->injectee_le->copy()->addWeeks($intervalle)];
    }

    /**
     * Valences d une injection : la photographie posée à l injection,
     * sinon celles du produit, sinon le nom libre comme valence unique.
     *
     * @return array<string, array{nom: string, court: string}>
     */
    public static function valencesDe(Vaccination $vaccination): array
    {
        $codes = $vaccination->valences ?: $vaccination->produit?->valences->pluck('code')->all() ?: [];
        if ($codes === []) {
            $nom = trim((string) $vaccination->nom) ?: 'Vaccin';

            return ['libre:'.mb_strtolower($nom) => ['nom' => $nom, 'court' => mb_substr($nom, 0, 24)]];
        }
        $toutes = DonneesVaccins::VALENCES;
        $resultat = [];
        foreach ($codes as $code) {
            $resultat[$code] = ['nom' => $toutes[$code]['nom'] ?? $code, 'court' => $toutes[$code]['court'] ?? $code];
        }

        return $resultat;
    }
}
