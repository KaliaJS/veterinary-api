<?php

namespace App\Services\Hospitalisation;

use App\Services\Bloc\SurgeryReportDrafts;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (API Messages) rédige le rapport de la journée depuis la feuille,
 * comme le brouillon SOAP des comptes rendus (même clé, même modèle, même
 * budget). Une erreur temporaire (429, 5xx, surcharge) remonte pour être
 * réessayée ; un refus est définitif. Rien de la feuille n'est journalisé.
 */
final class DailyReportWriterClaude implements DailyReportWriter
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string INSTRUCTIONS = <<<'TXT'
Tu aides l'équipe d'une clinique vétérinaire à rédiger le rapport de la journée d'un animal hospitalisé. Tu reçois la feuille du jour : les médicaments prévus, donnés ou non donnés et par qui, les soins, la douleur sur l'échelle de l'espèce avec son seuil de traitement, ce que l'animal a mangé et bu, ses éliminations, ses constantes et les notes de l'équipe.

Règles absolues :
1. N'invente rien. Chaque phrase du rapport doit venir de la feuille. Aucun diagnostic, examen, dose, traitement, pronostic ou conseil qui n'y figure pas.
2. Ce qui n'est pas noté sur la feuille n'est pas dit. N'écris jamais qu'une chose a été faite si la feuille ne le dit pas.
3. Garde les heures, les doses, les scores et les noms des personnes tels qu'ils sont écrits.
4. Signale clairement ce qui n'a pas été donné ou fait, et une douleur au-dessus du seuil de traitement. Ce qui est « à venir » n'est pas un oubli : dis seulement ce qui reste prévu ensuite.

Style : français, langage vétérinaire simple, phrases courtes, quatre à huit phrases, en paragraphes séparés par une ligne vide (alimentation et éliminations, médicaments et soins, douleur, constantes et remarques). Pas de titre, pas de mise en forme Markdown, pas de liste à puces, pas de tiret de ponctuation. Le rapport sera relu et signé par l'équipe, puis peut être envoyé au propriétaire : reste factuel et mesuré.
TXT;

    public function write(string $facts): string
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(180)
            ->post(self::URL, [
                'model' => config('services.anthropic.modele'),
                'max_tokens' => 1500,
                'system' => self::INSTRUCTIONS,
                'messages' => [['role' => 'user', 'content' => "Feuille du jour :\n".$facts]],
            ]);

        if (in_array($response->status(), [429, 529], true) || $response->serverError()) {
            throw new RuntimeException('Claude indisponible (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if (! $response->successful()) {
            throw new DailyReportImpossible('Claude refuse la requête (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new DailyReportImpossible('Rédaction interrompue ('.$response->json('stop_reason').').');
        }

        $text = collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new DailyReportImpossible('Réponse vide.');
        }

        // Jamais de tiret de ponctuation dans un texte affiché (règle de Jim)
        // Un tiret entre deux nombres est un intervalle (« 1,5–2 % » : « 1,5 à 2 % »)
        return trim(SurgeryReportDrafts::undash($text));
    }
}
