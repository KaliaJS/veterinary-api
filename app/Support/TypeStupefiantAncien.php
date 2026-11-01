<?php

namespace App\Support;

/**
 * Le type d'une ligne du registre des stupéfiants d'avant la mise aux
 * normes (migration 2026_11_01_000004), d'après son sens et son motif : un
 * report d'ouverture ou un inventaire est un inventaire, un vol un vol, un
 * flacon cassé une perte, une destruction une élimination, un retour au
 * fournisseur un retour ; une entrée sans réception ni report, ou une
 * sortie sans animal ni motif connu, une correction. Les mots se comparent
 * entiers (vérification du 01.11.2026 : « volume » ou « involontairement »
 * ne sont pas un vol).
 */
final class TypeStupefiantAncien
{
    public static function type(float $quantite, bool $versUnAnimal, string $motif): string
    {
        $m = ' '.preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($motif)).' ';
        $mot = fn (string ...$mots): bool => (bool) preg_match('/ ('.implode('|', $mots).') /u', $m);
        $debut = fn (string ...$racines): bool => (bool) preg_match('/ ('.implode('|', $racines).')/u', $m);

        return match (true) {
            $versUnAnimal => 'administration',
            $mot('report', 'reports', 'ouverture', 'inventaire', 'inventaires') => 'inventaire',
            $quantite < 0 && $mot('retour', 'retourné', 'retournée', 'retournés', 'retournées') => 'retour',
            $quantite > 0 && ($debut('récep', 'recep', 'grossiste', 'commande', 'livraison') || $mot('lot')) => 'reception',
            $mot('vol', 'volé', 'volée', 'volés', 'volées', 'cambriolage', 'effraction') => 'vol',
            $debut('cass', 'perte', 'perdu', 'renvers', 'vidé', 'gaspill', 'tomb') => 'perte',
            $debut('destruct', 'détruit', 'périm', 'perim', 'élimin', 'elimin') => 'elimination',
            $quantite > 0 && trim($m) === '' => 'reception',
            default => 'correction',
        };
    }
}
