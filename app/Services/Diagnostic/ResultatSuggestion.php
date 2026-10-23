<?php

namespace App\Services\Diagnostic;

/**
 * Suggestion rendue : synthèse, hypothèses classées (probabilité,
 * éléments pour et contre, examens), signaux d'alerte, examens
 * prioritaires, informations qui manquent. Chaque liste est nettoyée :
 * les entrées vides disparaissent.
 */
final readonly class ResultatSuggestion
{
    /** @param  array<string, mixed>  $contenu */
    public function __construct(
        public array $contenu,
        public string $modele,
        public int $jetonsEntree,
        public int $jetonsSortie,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function depuisJson(array $json, string $modele, int $jetonsEntree, int $jetonsSortie): self
    {
        $textes = fn (mixed $liste) => array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) $liste), fn ($t) => $t !== ''));
        $objets = fn (mixed $liste, array $cles) => array_values(array_filter(array_map(
            fn ($o) => is_array($o) ? array_map(fn ($cle) => trim((string) ($o[$cle] ?? '')), array_combine($cles, $cles)) : null,
            (array) $liste,
        ), fn ($o) => $o !== null && $o[$cles[0]] !== ''));

        return new self([
            'synthese' => trim((string) ($json['synthese'] ?? '')),
            'hypotheses' => array_values(array_filter(array_map(fn ($h) => is_array($h) ? [
                'nom' => trim((string) ($h['nom'] ?? '')),
                'probabilite' => in_array($h['probabilite'] ?? null, ['elevee', 'moyenne', 'faible'], true) ? $h['probabilite'] : 'moyenne',
                'pour' => $textes($h['pour'] ?? []),
                'contre' => $textes($h['contre'] ?? []),
                'examens' => $textes($h['examens'] ?? []),
            ] : null, (array) ($json['hypotheses'] ?? [])), fn ($h) => $h !== null && $h['nom'] !== '')),
            'alertes' => $objets($json['alertes'] ?? [], ['nom', 'raison']),
            'examens' => $objets($json['examens'] ?? [], ['examen', 'raison']),
            'manques' => $textes($json['manques'] ?? []),
        ], $modele, $jetonsEntree, $jetonsSortie);
    }
}
