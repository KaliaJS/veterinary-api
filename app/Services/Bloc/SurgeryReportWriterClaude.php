<?php

namespace App\Services\Bloc;

use App\Models\SurgeryReport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (API Messages) rédige le brouillon du rapport de bloc (09.11.2026),
 * comme le brouillon SOAP des comptes rendus : même clé, même modèle, même
 * budget, sortie JSON imposée par un schéma. Il ne réécrit que les sources
 * reçues, chaque phrase avec leurs numéros. Une erreur temporaire (429,
 * 5xx, surcharge) remonte pour être réessayée. Rien n'est journalisé.
 */
final class SurgeryReportWriterClaude implements SurgeryReportWriter
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string INSTRUCTIONS = <<<'TXT'
Tu aides un chirurgien vétérinaire à mettre au propre le rapport de bloc d'une intervention qu'il vient de faire. Tu reçois des sources numérotées : E pour ce que l'écran du bloc sait déjà (l'intervention, le déroulé avec ses heures, le protocole anesthésique, la checklist), N pour les lignes de sa note opératoire tapée en mots-clés, D pour les passages de sa dictée transcrite automatiquement. Tu réécris ces sources en rapport clair, que le chirurgien relira, corrigera et validera.

Règles absolues :
1. N'invente rien et n'interprète rien. Chaque phrase dit seulement ce qu'une ou plusieurs sources disent. Aucun diagnostic, geste, matériel, dose, durée, complication, pronostic ou consigne supposé, même habituel.
2. Chaque phrase cite les numéros de toutes ses sources (par exemple ["N2"] ou ["E4", "D3"]). Une phrase sans source n'existe pas.
3. Une rubrique dont aucune source ne parle reste vide (liste vide). Ne déduis jamais une rubrique d'une autre : « pas de saignement » ne veut pas dire « aucune complication ». Un rapport incomplet vaut mieux qu'un rapport faux.
4. Garde les heures, les doses, les tailles de fil et les noms tels qu'ils sont écrits. Quand la note et la dictée disent la même chose, une seule phrase avec les deux sources.
5. La dictée peut mal reconnaître un mot (noms de fils, de médicaments, chiffres). Écris ce que tu comprends le plus probablement, et ajoute un doute : rubrique ; passage, seulement les mots corrigés (un à trois), recopiés exactement comme dans ta phrase ; entendu, les mots de la transcription tels quels ; raison, en une phrase courte. Une information de la dictée ambiguë sans mot corrigé est aussi un doute, avec passage et entendu vides. N'en ajoute que si cela compte vraiment.
6. Les informations de contexte servent seulement à comprendre ; ne les recopie jamais.
7. Une information va dans une seule rubrique : les fils, ligatures et sutures dans « material », pas aussi dans « technique ».
8. La checklist de sécurité est déjà au dossier : ne la recopie pas point par point. Un point coché dit seulement qu'il a été vérifié, jamais qu'un geste ou un médicament a été donné. Cite-la seulement si elle apporte une information absente ailleurs (par exemple le compte des compresses).

Rubriques :
intervention : l'acte, l'animal, le chirurgien, la salle, les heures du déroulé.
anaesthesia : le protocole anesthésique et ce qui est dit de l'anesthésie.
technique : l'abord, la technique, les gestes et les constatations.
material : les fils, ligatures, sutures et le matériel utilisé.
complications : seulement ce que le chirurgien appelle lui-même complication ou incident, ou leur absence s'il la dit. Un constat (un saignement, l'aspect d'un organe) va dans « technique », même s'il pourrait passer pour un incident.
aftercare : les consignes après l'opération, les soins, les contrôles prévus.
implants : le matériel laissé en place (plaque, vis, broche, drain, maille), seulement s'il est dit.

Style : français, langage chirurgical vétérinaire, phrases courtes et complètes, une information par phrase. Pas de titre, pas de Markdown, pas de liste à puces, pas de tiret de ponctuation.
TXT;

    public function write(array $sources, array $context): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(240)
            ->post(self::URL, [
                'model' => config('services.anthropic.modele'),
                'max_tokens' => 4096,
                'system' => self::INSTRUCTIONS,
                'messages' => [['role' => 'user', 'content' => self::message($sources, $context)]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ]);

        if (in_array($response->status(), [429, 529], true) || $response->serverError()) {
            throw new RuntimeException('Claude indisponible (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if (! $response->successful()) {
            throw new SurgeryReportImpossible('Claude refuse la requête (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new SurgeryReportImpossible('Rédaction interrompue ('.$response->json('stop_reason').').');
        }

        $text = collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($json) || ! is_array($json['sections'] ?? null)) {
            throw new SurgeryReportImpossible('Réponse illisible.');
        }

        return ['sections' => $json['sections'], 'doubts' => (array) ($json['doubts'] ?? [])];
    }

    /**
     * @param  list<array{id: string, kind: string, label: string, text: string}>  $sources
     * @param  array<string, string>  $context
     */
    private static function message(array $sources, array $context): string
    {
        $lines = array_map(fn (array $s) => $s['id'].' ('.$s['label'].') : '.$s['text'], $sources);
        $about = collect($context)->map(fn (string $v, string $k) => $k.' : '.$v)->implode("\n");

        return "Contexte, pour comprendre seulement :\n".($about ?: 'aucun')."\n\nSources :\n".implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $line = [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Les numéros des sources de la phrase (E1, N2, D3…).'],
            ],
            'required' => ['text', 'sources'],
            'additionalProperties' => false,
        ];
        $sections = collect(SurgeryReport::SECTIONS)->mapWithKeys(fn (string $k) => [$k => ['type' => 'array', 'items' => $line, 'description' => SurgeryReport::SECTION_LABELS[$k].'. Liste vide si aucune source n’en parle.']])->all();

        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'object',
                    'properties' => $sections,
                    'required' => SurgeryReport::SECTIONS,
                    'additionalProperties' => false,
                ],
                'doubts' => [
                    'type' => 'array',
                    'description' => 'Passages incertains de la dictée. Liste vide s’il n’y en a pas.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'section' => ['type' => 'string', 'enum' => SurgeryReport::SECTIONS],
                            'passage' => ['type' => 'string'],
                            'heard' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['section', 'passage', 'heard', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['sections', 'doubts'],
            'additionalProperties' => false,
        ];
    }
}
