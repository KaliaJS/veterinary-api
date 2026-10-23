<?php

namespace Tests\Feature\Diagnostic;

use App\Services\Diagnostic\SuggesteurClaude;
use App\Services\Diagnostic\SuggestionImpossible;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Claude, fournisseur des suggestions : requête (effort, schéma, contexte sans nom) et réponse nettoyée. */
class SuggesteurClaudeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'cle-anthropic', 'services.anthropic.modele' => 'claude-opus-5-5']);
    }

    private function suggerer(): mixed
    {
        return (new SuggesteurClaude)->suggerer(
            ['s' => 'Boite depuis trois jours.', 'o' => 'Chaleur au genou droit.', 'a' => '', 'p' => ''],
            ['Espèce' => 'Chien', 'Race' => 'Bouvier bernois'],
            [['date' => '12.07.2026', 'motif' => 'Vaccin', 'a' => 'Bonne santé.', 'p' => '']],
            [['partie' => 1, 'voix' => 0, 'debut' => 12.4, 'texte' => 'Il boite depuis lundi.']],
        );
    }

    public function test_claude_raisonne_avec_un_effort_eleve_en_json_impose(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 2100, 'output_tokens' => 3400],
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => json_encode([
                    'synthese' => ' Boiterie aiguë. ',
                    'hypotheses' => [
                        ['nom' => 'Rupture du ligament croisé crânial', 'probabilite' => 'elevee', 'pour' => ['Grande race', ' '], 'contre' => [], 'examens' => ['Test du tiroir']],
                        ['nom' => ' ', 'probabilite' => 'faible', 'pour' => [], 'contre' => [], 'examens' => []],
                        ['nom' => 'Panostéite', 'probabilite' => 'inconnue', 'pour' => [], 'contre' => ['Âge'], 'examens' => []],
                    ],
                    'alertes' => [['nom' => 'Ostéosarcome', 'raison' => 'À écarter.'], ['nom' => '', 'raison' => 'Vide.']],
                    'examens' => [['examen' => 'Radiographie', 'raison' => 'Épanchement.']],
                    'manques' => ['Depuis quand ?', ''],
                ])],
            ],
        ])]);

        $resultat = $this->suggerer();

        $this->assertSame('Boiterie aiguë.', $resultat->contenu['synthese']);
        $this->assertCount(2, $resultat->contenu['hypotheses']);
        $this->assertSame(['Grande race'], $resultat->contenu['hypotheses'][0]['pour']);
        // Probabilité inconnue : moyenne
        $this->assertSame('moyenne', $resultat->contenu['hypotheses'][1]['probabilite']);
        $this->assertSame([['nom' => 'Ostéosarcome', 'raison' => 'À écarter.']], $resultat->contenu['alertes']);
        $this->assertSame(['Depuis quand ?'], $resultat->contenu['manques']);
        $this->assertSame([2100, 3400], [$resultat->jetonsEntree, $resultat->jetonsSortie]);

        Http::assertSent(function (Request $requete): bool {
            $message = $requete['messages'][0]['content'];
            $schema = $requete['output_config']['format']['schema'];

            return $requete->hasHeader('x-api-key', 'cle-anthropic')
                && $requete['model'] === 'claude-opus-5-5'
                && $requete['output_config']['effort'] === 'high'
                && $requete['max_tokens'] === 16000
                && str_contains($requete['system'], 'Ne propose ni traitement ni posologie')
                && $schema['additionalProperties'] === false
                && $schema['required'] === ['synthese', 'hypotheses', 'alertes', 'examens', 'manques']
                && $schema['properties']['hypotheses']['items']['properties']['probabilite']['enum'] === ['elevee', 'moyenne', 'faible']
                && str_contains($message, "Signalement de l'animal :\nEspèce : Chien\nRace : Bouvier bernois")
                && str_contains($message, "12.07.2026, motif : Vaccin\nDiagnostic : Bonne santé.")
                && str_contains($message, "A, diagnostic du vétérinaire :\n(vide)")
                && str_contains($message, "Partie 1\n[0:12] Voix 1 : Il boite depuis lundi.");
        });
    }

    public function test_refus_definitif_ou_surcharge_passagere(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error']], 400)
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529)
            ->push(['stop_reason' => 'max_tokens', 'content' => []])]);

        $this->assertLance(SuggestionImpossible::class);
        $this->assertLance(RuntimeException::class, fn ($e) => ! $e instanceof SuggestionImpossible);
        $this->assertLance(SuggestionImpossible::class);
    }

    private function assertLance(string $classe, ?callable $verifier = null): void
    {
        try {
            $this->suggerer();
            $this->fail('Une exception était attendue.');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf($classe, $e);
            $this->assertTrue($verifier ? $verifier($e) : true);
        }
    }
}
