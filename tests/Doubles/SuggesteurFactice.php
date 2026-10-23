<?php

namespace Tests\Doubles;

use App\Services\Diagnostic\ResultatSuggestion;
use App\Services\Diagnostic\Suggesteur;
use Throwable;

/** Double du suggesteur : garde ce qu'il reçoit, rend une suggestion fixée ; erreur programmable. */
class SuggesteurFactice implements Suggesteur
{
    /** @var list<array<string, mixed>> */
    public array $appels = [];

    public ?Throwable $erreur = null;

    public function suggerer(array $soap, array $signalement, array $antecedents, array $segments): ResultatSuggestion
    {
        $this->appels[] = compact('soap', 'signalement', 'antecedents', 'segments');
        if ($this->erreur) {
            throw $this->erreur;
        }

        return ResultatSuggestion::depuisJson([
            'synthese' => 'Boiterie aiguë du postérieur droit chez un grand chien adulte.',
            'hypotheses' => [
                ['nom' => 'Rupture du ligament croisé crânial (RLCCr)', 'probabilite' => 'elevee', 'pour' => ['Race prédisposée', 'Chaleur au genou'], 'contre' => ['Tiroir non recherché'], 'examens' => ['Test du tiroir', 'Radiographie du grasset']],
                ['nom' => 'Luxation de la rotule', 'probabilite' => 'faible', 'pour' => [], 'contre' => ['Grande race'], 'examens' => []],
            ],
            'alertes' => [['nom' => 'Ostéosarcome', 'raison' => 'Grand chien, douleur osseuse à écarter si la boiterie persiste.']],
            'examens' => [['examen' => 'Radiographie du grasset', 'raison' => 'Épanchement, ostéophytes.']],
            'manques' => ['Depuis quand exactement ?'],
        ], 'claude-opus-5-5', 1200, 900);
    }
}
