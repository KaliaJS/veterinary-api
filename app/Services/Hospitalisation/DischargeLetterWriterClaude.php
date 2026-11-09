<?php

namespace App\Services\Hospitalisation;

use App\Models\DischargeLetter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (API Messages) rédige le brouillon de la lettre de sortie
 * (09.11.2026), comme le rapport de bloc : même clé, même modèle, même
 * budget, sortie JSON imposée par un schéma. Il ne réécrit que les sources
 * reçues, en mots simples pour le propriétaire, chaque phrase avec leurs
 * numéros. Une erreur temporaire (429, 5xx, surcharge) remonte pour être
 * réessayée. Rien n'est journalisé.
 */
final class DischargeLetterWriterClaude implements DischargeLetterWriter
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string INSTRUCTIONS = <<<'TXT'
Tu aides un vétérinaire à écrire la lettre de sortie d'hospitalisation d'un animal, que son propriétaire emporte à la maison. Tu reçois des sources numérotées : S pour le séjour (l'animal, le motif, les dates, le vétérinaire), T pour les traitements donnés à la clinique, R pour les rapports de la journée déjà relus et signés, B pour les rubriques du rapport de bloc validé, N pour les consignes pour la maison tapées par le vétérinaire en mots-clés, D pour les passages de sa dictée transcrite automatiquement. Tu réécris ces sources en lettre claire, que le vétérinaire relira, corrigera et signera.

Règles absolues :
1. N'invente rien et n'interprète rien. Chaque phrase dit seulement ce qu'une ou plusieurs sources disent. Aucun diagnostic, pronostic, cause, traitement, durée, consigne ou signe d'alerte supposé, même habituel. Ne rassure pas et n'inquiète pas au-delà des sources.
2. Chaque phrase cite les numéros de toutes ses sources (par exemple ["R2"] ou ["S1", "B1"]). Une phrase sans source n'existe pas.
3. Une rubrique dont aucune source ne parle reste vide (liste vide). Ne déduis jamais une rubrique d'une autre. Une lettre incomplète vaut mieux qu'une lettre fausse.
4. Les médicaments à donner à la maison et le rendez-vous de contrôle sont ajoutés à la lettre à part, recopiés de l'ordonnance et du planning : ne les écris pas. Une consigne du vétérinaire sur la façon de donner un médicament reste une consigne (par exemple « avec le repas »), sans nom de médicament ni dose qu'elle ne contient pas.
5. Écris en mots simples pour un propriétaire. Tu peux dire simplement ce qu'une source dit en termes médicaux, sans rien ajouter (« une perfusion » pour un soluté en intraveineuse, « des antidouleurs » pour un analgésique nommé comme tel dans les sources). Pour les traitements donnés à la clinique, pas de dose. Garde les dates et les durées telles qu'elles sont écrites.
6. Pendant le séjour : dans l'ordre des jours, ce que disent les rapports signés, le rapport de bloc et les traitements. Quand deux sources disent la même chose, une seule phrase avec les deux sources.
7. À la maison et Appelez-nous : seulement ce que disent les consignes tapées (N) et la dictée (D). Adresse-toi au propriétaire (« vous »), à l'impératif quand c'est une consigne.
8. La dictée peut mal reconnaître un mot (noms de médicaments, chiffres). Écris ce que tu comprends le plus probablement, et ajoute un doute : rubrique ; passage, seulement les mots corrigés (un à trois), recopiés exactement comme dans ta phrase ; entendu, les mots de la transcription tels quels ; raison, en une phrase courte. Une information de la dictée ambiguë sans mot corrigé est aussi un doute, avec passage et entendu vides. N'en ajoute que si cela compte vraiment.
9. Les informations de contexte servent seulement à comprendre ; ne les recopie jamais.

Rubriques :
stay : une phrase sur le séjour : l'animal, les dates, le vétérinaire, le motif tel qu'il est écrit.
during : ce qui s'est passé pendant le séjour.
home : les consignes pour la maison (activité, alimentation, plaie, collerette, soins).
call : quand appeler la clinique, seulement si le vétérinaire le dit.

Style : français, phrases courtes et complètes, une information par phrase, le nom de l'animal plutôt que « le patient ». Pas de titre, pas de formule de politesse, pas de Markdown, pas de liste à puces, pas de tiret de ponctuation.
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
            throw new DischargeLetterImpossible('Claude refuse la requête (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new DischargeLetterImpossible('Rédaction interrompue ('.$response->json('stop_reason').').');
        }

        $text = collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($json) || ! is_array($json['sections'] ?? null)) {
            throw new DischargeLetterImpossible('Réponse illisible.');
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
                'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Les numéros des sources de la phrase (S1, R2, N3…).'],
            ],
            'required' => ['text', 'sources'],
            'additionalProperties' => false,
        ];
        $sections = collect(DischargeLetter::SECTIONS)->mapWithKeys(fn (string $k) => [$k => ['type' => 'array', 'items' => $line, 'description' => DischargeLetter::SECTION_LABELS[$k].'. Liste vide si aucune source n’en parle.']])->all();

        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'object',
                    'properties' => $sections,
                    'required' => DischargeLetter::SECTIONS,
                    'additionalProperties' => false,
                ],
                'doubts' => [
                    'type' => 'array',
                    'description' => 'Passages incertains de la dictée. Liste vide s’il n’y en a pas.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'section' => ['type' => 'string', 'enum' => DischargeLetter::SECTIONS],
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
