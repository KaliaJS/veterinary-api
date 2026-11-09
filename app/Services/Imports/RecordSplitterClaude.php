<?php

namespace App\Services\Imports;

use App\Models\RecordImport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La découpe d'un gros PDF reçu par Claude Haiku 4.5 (choix de Jim,
 * 09.11.2026 : peu coûteux) : le texte de chaque page, l'image des seules
 * pages scannées. Rien d'inventé : nom, sorte, date et auteur sont lus dans
 * le PDF, sinon laissés vides ; le vétérinaire corrige et valide.
 */
final class RecordSplitterClaude implements RecordSplitter
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const int TEXT_PER_PAGE = 1800;

    /* Le texte de toutes les pages ensemble, pour tenir dans ce que Haiku lit (200 000 jetons) */
    private const int TEXT_TOTAL = 360000;

    private const string INSTRUCTIONS = <<<'TXT'
Tu aides un vétérinaire à ranger un dossier reçu d'une autre clinique : un seul PDF où plusieurs pièces se suivent (comptes rendus, analyses de laboratoire, rapports d'imagerie, ordonnances, carnets de vaccination, lettres). Tu reçois chaque page, numérotée, avec son texte, ou son image si la page est scannée. Tu proposes la découpe en pièces ; le vétérinaire la corrigera et la validera.

Règles :
1. Les pièces se suivent dans l'ordre, sans trou ni chevauchement, et couvrent toutes les pages, de la première à la dernière.
2. Une nouvelle pièce commence quand l'en-tête, le titre, la date, l'expéditeur ou la sorte de document changent. Une page de suite (même en-tête, « page 2/3 », tableau qui continue) reste dans la même pièce.
3. N'invente rien. Le titre reprend ce que la pièce dit d'elle-même (par exemple « Hématologie et biochimie », « Rapport de radiographie du genou », « Compte rendu de consultation »), en quelques mots, sans le nom de l'animal. La date est celle du document (examen, consultation, prélèvement), au format AAAA-MM-JJ, seulement si elle est écrite ; sinon null. La source est la clinique, le laboratoire ou le vétérinaire qui a établi la pièce, seulement s'il est écrit ; sinon null.
4. La sorte : compte_rendu (consultation), rapport (chirurgie, hospitalisation, sortie), laboratoire (analyses), imagerie (radiographie, échographie, scanner, IRM), specialiste (avis d'un spécialiste), ordonnance, vaccination, lettre (lettre de référence ou de sortie), autre.
5. Une page vide ou une page de garde rejoint la pièce qui la suit.
TXT;

    public function split(array $pages): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(240)
            ->post(self::URL, [
                'model' => config('services.anthropic.modele_decoupe'),
                'max_tokens' => 16000,
                'system' => self::INSTRUCTIONS,
                'messages' => [['role' => 'user', 'content' => self::content($pages)]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ]);

        if (in_array($response->status(), [429, 529], true) || $response->serverError()) {
            throw new RuntimeException('Claude indisponible (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if (! $response->successful()) {
            throw new RecordSplitImpossible('Claude refuse la requête (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new RecordSplitImpossible('Découpe interrompue ('.$response->json('stop_reason').').');
        }
        $text = collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($json) || ! is_array($json['documents'] ?? null)) {
            throw new RecordSplitImpossible('Réponse illisible.');
        }

        return array_values($json['documents']);
    }

    /**
     * Le texte des pages, et l'image des seules pages scannées
     *
     * @param  list<array{n: int, text: string, image: ?string}>  $pages
     * @return list<array<string, mixed>>
     */
    private static function content(array $pages): array
    {
        $blocks = [['type' => 'text', 'text' => 'Le dossier compte '.count($pages).' pages.']];
        // Une image compte comme environ 4000 caractères de texte : le tout tient dans ce que Haiku lit
        $images = count(array_filter($pages, fn (array $p) => ! empty($p['image'])));
        $perPage = max(300, min(self::TEXT_PER_PAGE, intdiv(self::TEXT_TOTAL - 4000 * $images, max(1, count($pages)))));
        foreach ($pages as $page) {
            $text = trim(mb_substr((string) $page['text'], 0, $perPage));
            if (! empty($page['image'])) {
                $blocks[] = ['type' => 'text', 'text' => "Page {$page['n']} (scannée) :"];
                $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $page['image']]];
            } else {
                $blocks[] = ['type' => 'text', 'text' => "Page {$page['n']} :\n".($text !== '' ? $text : '(page vide)')];
            }
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'documents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'integer', 'description' => 'Première page de la pièce.'],
                            'to' => ['type' => 'integer', 'description' => 'Dernière page de la pièce.'],
                            'title' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => RecordImport::KINDS],
                            'date' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-JJ, seulement si écrite dans la pièce.'],
                            'source' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['from', 'to', 'title', 'kind', 'date', 'source'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['documents'],
            'additionalProperties' => false,
        ];
    }
}
