<?php

namespace App\Services\Referrals;

use App\Models\ReferralLetter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (API Messages) rédige le brouillon de la lettre de référence
 * (09.11.2026), comme la lettre de sortie : même clé, même modèle, même
 * budget, sortie JSON imposée par un schéma. Il ne réécrit que les sources
 * reçues, en langage vétérinaire pour le confrère, chaque phrase avec leurs
 * numéros. Une erreur temporaire (429, 5xx, surcharge) remonte pour être
 * réessayée. Rien n'est journalisé.
 */
final class ReferralLetterWriterClaude implements ReferralLetterWriter
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string INSTRUCTIONS = <<<'TXT'
Tu aides un vétérinaire à écrire une lettre de référence : il adresse un animal à un confrère ou à un spécialiste. Tu reçois des sources numérotées : P pour les rubriques des pièces du dossier qu'il joint (comptes rendus, analyses, imagerie, rapports de bloc, hospitalisation, ordonnances), N pour ses notes en mots-clés sur le motif et sa question, D pour les passages de sa dictée transcrite automatiquement. Tu réécris ces sources en lettre claire, en langage vétérinaire, que le vétérinaire relira, corrigera et signera.

Règles absolues :
1. N'invente rien et n'interprète rien. Chaque phrase dit seulement ce qu'une ou plusieurs sources disent. Aucun diagnostic, hypothèse, pronostic, traitement, dose, durée ou demande supposé, même habituel.
2. Chaque phrase cite les numéros de toutes ses sources (par exemple ["P2"] ou ["N1", "D3"]). Une phrase sans source n'existe pas.
3. Une rubrique dont aucune source ne parle reste vide (liste vide). Ne déduis jamais une rubrique d'une autre. Une lettre incomplète vaut mieux qu'une lettre fausse.
4. Garde les valeurs, les unités, les dates, les doses et les noms tels qu'ils sont écrits.
5. L'identité de l'animal, son traitement en cours et la liste des pièces jointes sont ajoutés à part, recopiés du dossier : ne les écris pas.
6. Quand deux sources disent la même chose, une seule phrase avec les deux sources.
7. La dictée peut mal reconnaître un mot (noms de médicaments, termes médicaux, chiffres). Écris ce que tu comprends le plus probablement, et ajoute un doute : rubrique ; passage, seulement les mots corrigés (un à trois), recopiés exactement comme dans ta phrase ; entendu, les mots de la transcription tels quels ; raison, en une phrase courte. N'en ajoute que si cela compte vraiment.
8. Les informations de contexte servent seulement à comprendre ; ne les recopie jamais.

Rubriques :
reason : le motif de la référence, tel que le vétérinaire le dit (N, D).
history : l'anamnèse et l'examen clinique, tirés des comptes rendus et des rapports joints (P).
exams : les examens complémentaires et leurs résultats (analyses, imagerie), tirés des pièces jointes (P).
request : ce que le vétérinaire demande au confrère, et les précisions utiles qu'il donne (N, D).

Style : français, langage vétérinaire, phrases courtes et complètes, une information par phrase, au présent ou au passé composé. Pas de titre, pas de formule de politesse, pas de Markdown, pas de liste à puces, pas de tiret de ponctuation.
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
            throw new ReferralLetterImpossible('Claude refuse la requête (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new ReferralLetterImpossible('Rédaction interrompue ('.$response->json('stop_reason').').');
        }

        $text = collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($json) || ! is_array($json['sections'] ?? null)) {
            throw new ReferralLetterImpossible('Réponse illisible.');
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
                'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Les numéros des sources de la phrase (P1, N2, D3…).'],
            ],
            'required' => ['text', 'sources'],
            'additionalProperties' => false,
        ];
        $sections = collect(ReferralLetter::SECTIONS)->mapWithKeys(fn (string $k) => [$k => ['type' => 'array', 'items' => $line, 'description' => ReferralLetter::SECTION_LABELS[$k].'. Liste vide si aucune source n’en parle.']])->all();

        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'object',
                    'properties' => $sections,
                    'required' => ReferralLetter::SECTIONS,
                    'additionalProperties' => false,
                ],
                'doubts' => [
                    'type' => 'array',
                    'description' => 'Passages incertains de la dictée. Liste vide s’il n’y en a pas.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'section' => ['type' => 'string', 'enum' => ReferralLetter::SECTIONS],
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
