<?php

/*
 * Convertit le répertoire officiel des localités de swisstopo (CSV du zip
 * ortschaftenverzeichnis_plz_2056.csv.zip, voir README.md) en
 * localites.json. Rien n'est ajouté ni corrigé : NPA (PLZ4), nom officiel
 * (Ortschaftsname) et canton (Kantonskürzel, null au Liechtenstein) ; une
 * localité partagée entre plusieurs communes ne compte qu'une fois ; ordre :
 * chiffre additionnel (Zusatzziffer) croissant, puis le nom.
 *
 * Usage : php resources/data/convert-localites.php AMTOVZ_CSV_LV95.csv > resources/data/localites.json
 */

$path = $argv[1] ?? null;
if ($path === null || ! is_readable($path)) {
    fwrite(STDERR, "Usage : php convert-localites.php AMTOVZ_CSV_LV95.csv\n");
    exit(1);
}

$lines = preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path)));
$head = str_getcsv((string) array_shift($lines), ';', '"', '');
$column = array_flip($head);

$byNpa = [];
foreach ($lines as $line) {
    if ($line === '') {
        continue;
    }
    $cells = str_getcsv($line, ';', '"', '');
    $npa = $cells[$column['PLZ4']];
    $name = $cells[$column['Ortschaftsname']];
    $extra = $cells[$column['Zusatzziffer']];
    $canton = $cells[$column['Kantonskürzel']] !== '' ? $cells[$column['Kantonskürzel']] : null;
    if (! isset($byNpa[$npa][$name])) {
        $byNpa[$npa][$name] = ['name' => $name, 'canton' => $canton, 'extra' => $extra];
    } elseif (strcmp($byNpa[$npa][$name]['extra'], $extra) > 0) {
        $byNpa[$npa][$name]['extra'] = $extra;
    }
}

ksort($byNpa, SORT_STRING);
$collator = new Collator('de');
$out = [];
foreach ($byNpa as $npa => $localities) {
    $list = array_values($localities);
    usort($list, fn (array $a, array $b): int => strcmp($a['extra'], $b['extra']) ?: $collator->compare($a['name'], $b['name']));
    $out[(string) $npa] = array_map(fn (array $l): array => [$l['name'], $l['canton']], $list);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
