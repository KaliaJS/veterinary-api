<?php

namespace App\Services\Labs;

use App\Models\LabSummary;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Le message au propriétaire sur une analyse, par Claude (09.11.2026) :
 * mots simples, rien d'inventé, chaque phrase cite ses sources, aucune
 * interprétation ; ce qu'une valeur mesure peut se dire en général. Le sens
 * de l'évolution est donné par les sources T, calculé par le code.
 */
final class LabSummaryWriterClaude implements LabSummaryWriter
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    private const string INSTRUCTIONS = <<<'TXT'
Tu aides un vétérinaire à écrire au propriétaire d'un animal, en mots simples, les résultats d'une analyse de laboratoire validée. Tu reçois des sources numérotées : V pour chaque valeur de cette analyse (avec ses normes et sa position : dans les normes, à la limite, hors normes), T pour l'évolution d'une valeur mesurée au moins trois fois ces deux dernières années (le sens, « en hausse », « en baisse », « variable » ou « stable », est déjà calculé). Le vétérinaire relira, corrigera et signera avant l'envoi.

Règles :
1. Aucun diagnostic, aucune cause, aucun pronostic, aucun conseil, aucun traitement. Tu ne dis pas ce que les résultats signifient pour la santé de l'animal : c'est le vétérinaire qui le fera.
2. Le propriétaire n'y connaît rien : chaque valeur que tu nommes est expliquée en une phrase courte, de façon générale, ce qu'elle est et à quoi elle sert dans le corps (par exemple « La T4 est une hormone fabriquée par la thyroïde, qui règle l'énergie du corps. » ou « La créatinine est un déchet que les reins filtrent du sang. »), sans rien en conclure pour cet animal.
3. Chaque phrase cite les numéros de toutes ses sources (par exemple ["V3"] ou ["V3", "T1"]). Une phrase sans source n'existe pas.
4. Le sens d'une évolution est celui de la source T, mot pour mot ; tu ne le déduis jamais toi-même.
5. Les valeurs hors normes ou à la limite sont nommées une à une, avec leur chiffre et leurs normes, chacune avec sa phrase d'explication. Les valeurs dans les normes : s'il y en a quatre au plus, chacune est nommée avec sa phrase courte ; au-delà, une seule phrase dit que les autres valeurs sont dans les normes, sans les nommer.

La partie :
owner : quelques phrases pour le propriétaire, sans jargon, sans inquiéter ni rassurer.

Style : français, phrases complètes, pas de titre, pas de Markdown, pas de liste à puces, pas de tiret de ponctuation.
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
            throw new LabSummaryImpossible('Claude refuse la requête (HTTP '.$response->status().', '.$response->json('error.type').').');
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new LabSummaryImpossible('Rédaction interrompue ('.$response->json('stop_reason').').');
        }
        $text = collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $json = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($json) || ! is_array($json['sections'] ?? null)) {
            throw new LabSummaryImpossible('Réponse illisible.');
        }

        return ['sections' => $json['sections']];
    }

    /**
     * @param  list<array{id: string, kind: string, label: string, text: string}>  $sources
     * @param  array<string, string>  $context
     */
    private static function message(array $sources, array $context): string
    {
        $lines = array_map(fn (array $s) => $s['id'].' : '.$s['text'], $sources);
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
                'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['text', 'sources'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'object',
                    'properties' => collect(LabSummary::SECTIONS)->mapWithKeys(fn (string $k) => [$k => ['type' => 'array', 'items' => $line, 'description' => LabSummary::SECTION_LABELS[$k].'. Liste vide si aucune source n’en parle.']])->all(),
                    'required' => LabSummary::SECTIONS,
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['sections'],
            'additionalProperties' => false,
        ];
    }
}
