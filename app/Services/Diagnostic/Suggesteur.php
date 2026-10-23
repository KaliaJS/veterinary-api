<?php

namespace App\Services\Diagnostic;

/**
 * Propose un diagnostic différentiel à partir du compte rendu en cours.
 * Une erreur temporaire (surcharge, réseau) remonte en RuntimeException
 * pour être réessayée ; un refus définitif en SuggestionImpossible.
 */
interface Suggesteur
{
    /**
     * @param  array{s: string, o: string, a: string, p: string}  $soap
     * @param  array<string, string>  $signalement
     * @param  list<array{date: string, motif: string, a: string, p: string}>  $antecedents
     * @param  list<array{partie: int, voix: int, debut: float, texte: string}>  $segments
     */
    public function suggerer(array $soap, array $signalement, array $antecedents, array $segments): ResultatSuggestion;
}
