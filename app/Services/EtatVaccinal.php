<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Vaccination;
use App\Services\Catalogue\DonneesVaccins;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        // La date, puis l'ordre d'enregistrement : la même « dernière » que la règle du renouvellement, quel que soit l'ordre de chargement
        foreach ($vaccinations->sortBy([['injectee_le', 'asc'], ['id', 'asc']])->values() as $vaccination) {
            foreach (self::valencesDe($vaccination) as $code => $nom) {
                $parValence[$code][] = $vaccination;
                $noms[$code] = $nom;
            }
        }
        // La règle unique : une valence dont la dernière injection est renouvelée (ancien « Rage » en texte
        // libre refait par un « Rage » du catalogue) ne fait pas une ligne de plus (vérification du 01.11.2026)
        $renouvelees = self::renouvelees($vaccinations);
        $etats = [];
        foreach ($parValence as $code => $injections) {
            /** @var Vaccination $derniere */
            $derniere = end($injections);
            if (isset($renouvelees[$derniere->id])) {
                continue;
            }
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
     * Les injections d'un animal déjà renouvelées : une injection plus
     * récente du même vaccin (même nom, espaces et casse ignorés), ou des
     * injections plus récentes qui couvrent toutes ses valences (combiné,
     * autre produit). La seule règle, pour l'envoi des rappels, le carnet et
     * la liste des patients (vérification du 01.11.2026).
     *
     * @param  iterable<Vaccination>  $injections  toutes celles de l'animal, produits et valences chargés
     * @return array<int, true> les identifiants des injections renouvelées
     */
    public static function renouvelees(iterable $injections): array
    {
        $liste = collect($injections)->values();
        $renouvelees = [];
        foreach ($liste as $vaccination) {
            $siennes = array_keys(self::valencesDe($vaccination));
            $nom = mb_strtolower(trim((string) $vaccination->nom));
            $couvertes = [];
            foreach ($liste as $autre) {
                $plusRecente = $autre->injectee_le->gt($vaccination->injectee_le)
                    || ($autre->injectee_le->equalTo($vaccination->injectee_le) && $autre->id > $vaccination->id);
                if (! $plusRecente) {
                    continue;
                }
                if (mb_strtolower(trim((string) $autre->nom)) === $nom) {
                    $renouvelees[$vaccination->id] = true;

                    continue 2;
                }
                $couvertes += array_fill_keys(array_keys(self::valencesDe($autre)), true);
                // Une plus récente saisie à la main couvre les maladies qu'elle nomme sans ambiguïté (« Rage »,
                // pas « Toux de chenil », qui peut n'être que l'une des deux)
                foreach (self::maladiesDuNom($autre) ?? [] as $candidates) {
                    if (count($candidates) === 1) {
                        $couvertes[$candidates[0]] = true;
                    }
                }
            }
            if ($siennes !== [] && array_diff($siennes, array_keys($couvertes)) === []) {
                $renouvelees[$vaccination->id] = true;

                continue;
            }
            // Saisie à la main (« Rage », « Leucose (FeLV) », « Typhus / Coryza ») : renouvelée quand chaque maladie
            // qu'elle peut désigner est couverte (« Toux de chenil » : parainfluenza ET bordetellose), jamais à tort ;
            // ici seulement, l'affichage et la primo-vaccination gardent le nom libre (vérification du 01.11.2026)
            $parties = self::maladiesDuNom($vaccination);
            if ($parties !== null && $parties !== [] && collect($parties)->every(fn (array $candidates): bool => array_diff($candidates, array_keys($couvertes)) === [])) {
                $renouvelees[$vaccination->id] = true;
            }
        }

        return $renouvelees;
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

    /** Les noms que l'app et les cliniques emploient pour une maladie, en plus des noms de DonneesVaccins */
    private const ALIAS = [
        'felv' => ['leucose'], 'parvo' => ['parvovirose'], 'lepto' => ['leptospirose'], 'typhus' => ['panleucopenie'],
        'coryza' => ['herpes_felin', 'calicivirose'], 'vhd' => ['rhd'], 'rhd' => ['rhd'], 'toux de chenil' => ['parainfluenza', 'bordetellose'],
        'teigne' => ['dermatophytose'], 'piroplasmose' => ['babesiose'], 'panleucopenie' => ['panleucopenie'],
    ];

    /**
     * Les maladies que nomme une injection saisie à la main, partie par
     * partie (« Typhus / Coryza » : [[panleucopénie], [herpès, calici]]) :
     * nom long, nom court, ce qu'il y a entre parenthèses, alias. Null si
     * l'injection a ses valences, ou si une partie ne nomme aucune maladie
     * connue (« Rage, Rabisin »). Pour la règle du renouvellement seulement
     * (vérification du 01.11.2026).
     *
     * @return list<list<string>>|null
     */
    private static function maladiesDuNom(Vaccination $vaccination): ?array
    {
        if ($vaccination->valences || $vaccination->produit?->valences->isNotEmpty()) {
            return null;
        }
        static $index = null;
        $cle = fn (string $texte): string => trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($texte))) ?? '');
        if ($index === null) {
            $index = [];
            $poser = function (string $texte, string $code) use (&$index, $cle): void {
                foreach (array_filter([$cle($texte), $cle((string) preg_replace('/\s*\(.*?\)\s*/', ' ', $texte))]) as $k) {
                    $index[$k][] = $code;
                }
                // Ce qu'il y a entre parenthèses (« FeLV », « RHD/VHD », « coryza »)
                preg_match_all('/\(([^)]*)\)/', $texte, $m);
                foreach ($m[1] as $dedans) {
                    foreach (preg_split('#\s*[/,]\s*#', $dedans) ?: [] as $k) {
                        if ($cle($k) !== '') {
                            $index[$cle($k)][] = $code;
                        }
                    }
                }
            };
            foreach (DonneesVaccins::VALENCES as $code => $valence) {
                $poser($valence['nom'], $code);
                $poser($valence['court'], $code);
            }
            foreach (self::ALIAS as $alias => $codes) {
                foreach ($codes as $code) {
                    $index[$alias][] = $code;
                }
            }
            // Des mots trop généraux pour nommer une maladie (« (sérum) » du poulain, « (herpès) » du chat)
            unset($index['serum'], $index['herpes']);
        }
        // Les séparateurs au premier niveau seulement : « Toux de chenil (Bordetella + Pi) » reste une partie
        $decouper = function (string $texte): array {
            $parties = [''];
            $profondeur = 0;
            foreach (preg_split('/(\s*(?:·|,|\+|\/|&|\bet\b)\s*|[()])/u', $texte, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $bout) {
                if ($bout === '(' || $bout === ')') {
                    $profondeur += $bout === '(' ? 1 : -1;
                    $parties[count($parties) - 1] .= $bout;
                } elseif ($profondeur === 0 && preg_match('/^\s*(?:·|,|\+|\/|&|\bet\b)\s*$/u', $bout)) {
                    $parties[] = '';
                } else {
                    $parties[count($parties) - 1] .= $bout;
                }
            }

            return array_values(array_filter(array_map('trim', $parties), fn (string $p): bool => $p !== ''));
        };
        $connu = fn (string $k): ?array => $k !== '' && isset($index[$k]) ? array_values(array_unique($index[$k])) : null;
        // Le nom entier ; sinon le nom sans ses parenthèses, que les parenthèses peuvent seulement préciser
        // (« Toux de chenil (Bordetella) » : la bordetellose) ou compléter (« Rage (+ Lepto) » : les deux, exigées) ;
        // un nom inconnu devant ses parenthèses reste libre (vérification du 01.11.2026)
        $chercher = function (string $partie) use ($connu, $cle, $decouper): ?array {
            if ($codes = $connu($cle($partie))) {
                return $codes;
            }
            $base = $connu($cle((string) preg_replace('/\s*\(.*?\)\s*/', ' ', $partie)));
            if ($base === null) {
                return null;
            }
            preg_match_all('/\(([^)]*)\)/', $partie, $m);
            foreach ($m[1] as $dedans) {
                $sous = array_map(fn (string $p): ?array => $connu($cle($p)), $decouper($dedans));
                if ($sous === [] || in_array(null, $sous, true)) {
                    continue;
                }
                $precis = array_values(array_unique(array_merge(...$sous)));
                $base = array_diff($precis, $base) === [] ? $precis : array_values(array_unique(array_merge($base, $precis)));
            }

            return $base;
        };
        $parties = $decouper((string) $vaccination->nom);
        if ($parties === []) {
            return null;
        }
        $maladies = [];
        foreach ($parties as $partie) {
            $codes = $chercher($partie);
            if ($codes === null) {
                return null;
            }
            $maladies[] = $codes;
        }

        return $maladies;
    }
}
