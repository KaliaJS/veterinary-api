<?php

namespace Tests\Feature\Transcription;

use App\Services\Transcription\RedacteurClaude;
use App\Services\Transcription\RedactionImpossible;
use App\Services\Transcription\TermesTranscription;
use App\Services\Transcription\TranscripteurDeepgram;
use App\Services\Transcription\TranscriptionImpossible;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Les fournisseurs réels, réseau simulé : la requête envoyée (modèle,
 * langue, voix, mots rares, clé) et la lecture des réponses et des
 * erreurs, définitives ou à réessayer.
 */
class FournisseursTranscriptionTest extends TestCase
{
    private string $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audio = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($this->audio, 'AUDIO');
        config([
            'services.deepgram.key' => 'cle-deepgram',
            'services.anthropic.key' => 'cle-anthropic',
            'services.anthropic.modele' => 'claude-opus-5-5',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->audio);
        parent::tearDown();
    }

    public function test_deepgram_recoit_le_fichier_et_rend_les_phrases_par_voix(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response([
            'metadata' => ['request_id' => 'req-1', 'duration' => 125.4],
            'results' => ['utterances' => [
                ['speaker' => 0, 'start' => 0.48, 'end' => 3.2, 'transcript' => 'Il boite depuis trois jours.'],
                ['speaker' => 1, 'start' => 4.0, 'end' => 4.5, 'transcript' => '  '],
                ['speaker' => 1, 'start' => 5.123, 'end' => 9.9, 'transcript' => 'Je lui donne du Metacam.'],
            ]],
        ])]);

        $transcription = (new TranscripteurDeepgram)->transcrire($this->audio, 'audio/mp4', ['Beloc Zok', 'Metacam']);

        $this->assertSame(125.4, $transcription->duree);
        $this->assertSame('req-1', $transcription->requete);
        $this->assertSame([
            ['voix' => 0, 'debut' => 0.48, 'fin' => 3.2, 'texte' => 'Il boite depuis trois jours.'],
            ['voix' => 1, 'debut' => 5.12, 'fin' => 9.9, 'texte' => 'Je lui donne du Metacam.'],
        ], $transcription->segments);

        Http::assertSent(fn (Request $requete) => $requete->hasHeader('Authorization', 'Token cle-deepgram')
            && $requete->hasHeader('Content-Type', 'audio/mp4')
            && $requete->body() === 'AUDIO'
            && str_starts_with($requete->url(), 'https://api.deepgram.com/v1/listen?model=nova-3-medical&language=multi&diarize_model=latest&utterances=true&smart_format=true&punctuate=true&mip_opt_out=true')
            && str_ends_with($requete->url(), '&keyterm=Beloc%20Zok&keyterm=Metacam'));
    }

    public function test_deepgram_distingue_refus_et_panne_passagere(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::sequence()
            ->push(['err_msg' => 'Bad Request: failed to process audio'], 400)
            ->push([], 503)]);

        try {
            (new TranscripteurDeepgram)->transcrire($this->audio, 'audio/mp4', []);
            $this->fail('Refus attendu');
        } catch (TranscriptionImpossible $erreur) {
            $this->assertStringContainsString('failed to process audio', $erreur->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deepgram indisponible');
        (new TranscripteurDeepgram)->transcrire($this->audio, 'audio/mp4', []);
    }

    public function test_claude_redige_en_json_impose_sans_rien_inventer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode([
                's' => ' Boiterie depuis 3 jours. ', 'o' => '', 'a' => '', 'p' => 'Repos.', 'a_verifier' => ['Dose de Metacam à confirmer.', ' '],
            ])]],
        ])]);

        $brouillon = (new RedacteurClaude)->rediger([
            ['partie' => 1, 'voix' => 0, 'debut' => 12.4, 'texte' => 'Il boite depuis trois jours.'],
            ['partie' => 2, 'voix' => 1, 'debut' => 75.0, 'texte' => 'On met au repos.'],
        ], ['Animal' => 'Pacha', 'Espèce' => 'Chien']);

        $this->assertSame(['s' => 'Boiterie depuis 3 jours.', 'o' => '', 'a' => '', 'p' => 'Repos.', 'a_verifier' => ['Dose de Metacam à confirmer.']], $brouillon->toArray());

        Http::assertSent(function (Request $requete): bool {
            $message = $requete['messages'][0]['content'];

            return $requete->hasHeader('x-api-key', 'cle-anthropic')
                && $requete->hasHeader('anthropic-version', '2023-06-01')
                && $requete['model'] === 'claude-opus-5-5'
                && str_contains($requete['system'], 'N\'invente rien')
                && $requete['output_config']['format']['type'] === 'json_schema'
                && $requete['output_config']['format']['schema']['additionalProperties'] === false
                && $requete['output_config']['format']['schema']['required'] === ['s', 'o', 'a', 'p', 'a_verifier']
                && str_contains($message, "Animal : Pacha\nEspèce : Chien")
                && str_contains($message, "Partie 1\n[0:12] Voix 1 : Il boite depuis trois jours.")
                && str_contains($message, "Partie 2\n[1:15] Voix 2 : On met au repos.");
        });
    }

    public function test_claude_refus_definitif_ou_surcharge_passagere(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['stop_reason' => 'refusal', 'content' => []])
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529)]);
        $segments = [['partie' => 1, 'voix' => 0, 'debut' => 0.0, 'texte' => 'Bonjour.']];

        try {
            (new RedacteurClaude)->rediger($segments, []);
            $this->fail('Refus attendu');
        } catch (RedactionImpossible) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overloaded_error');
        (new RedacteurClaude)->rediger($segments, []);
    }

    public function test_mots_rares_sans_doublon_raccourcis_et_dans_le_budget(): void
    {
        $this->assertSame('Metacam', TermesTranscription::nomCourt('Metacam 1,5 mg/ml ad us. vet., suspension orale'));
        $this->assertSame('Beloc Zok', TermesTranscription::nomCourt('Beloc Zok'));
        $this->assertSame('Nobivac DHPPi', TermesTranscription::nomCourt('Nobivac DHPPi (lyophilisat)'));

        $this->assertSame(['Metacam', 'Bouvier bernois'], TermesTranscription::retenir(['Metacam', null, 'ab', 'metacam', ' Bouvier   bernois ']));

        $nombreux = array_map(fn (int $i) => 'Produit numéro '.$i, range(1, 200));
        $retenus = TermesTranscription::retenir($nombreux);
        $this->assertLessThan(200, count($retenus));
        $this->assertLessThanOrEqual(420, array_sum(array_map(fn (string $terme) => (int) ceil(mb_strlen($terme) / 3), $retenus)));
    }
}
