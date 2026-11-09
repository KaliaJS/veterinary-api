<?php

namespace App\Services\Imports;

use App\Models\RecordImport;
use Carbon\CarbonImmutable;

/**
 * La proposition de découpe remise en ordre (09.11.2026) : des pièces qui
 * se suivent sans trou ni chevauchement et couvrent toutes les pages ; une
 * sorte connue ; une date lisible et pas dans le futur ; un titre court. Ce
 * qui manque reste vide : le vétérinaire complète.
 */
final class RecordImports
{
    /**
     * @param  array<int, mixed>  $documents  (la réponse de Claude, à vérifier)
     * @return list<array{from: int, to: int, title: string, kind: string, date: ?string, source: ?string}>
     */
    public static function normalize(array $documents, int $pagesCount): array
    {
        $sorted = collect($documents)
            ->map(fn ($d) => is_array($d) ? $d : [])
            ->filter(fn (array $d) => isset($d['from']) && is_numeric($d['from']))
            ->sortBy(fn (array $d) => (int) $d['from'])
            ->values();
        $out = [];
        $next = 1;
        foreach ($sorted as $i => $d) {
            if ($next > $pagesCount) {
                break;
            }
            // Chaque pièce commence où la précédente s'arrête ; elle va jusqu'à la page avant la suivante
            $following = $sorted->get($i + 1);
            $to = $following !== null ? max($next, min($pagesCount, (int) $following['from'] - 1)) : $pagesCount;
            $to = min($to, max($next, (int) ($d['to'] ?? $to)));
            if ($following === null) {
                $to = $pagesCount;
            }
            $out[] = self::piece($next, $to, $d);
            $next = $to + 1;
        }
        if ($out === [] && $pagesCount > 0) {
            $out[] = self::piece(1, $pagesCount, []);
        } elseif ($next <= $pagesCount) {
            $out[count($out) - 1]['to'] = $pagesCount;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{from: int, to: int, title: string, kind: string, date: ?string, source: ?string}
     */
    private static function piece(int $from, int $to, array $d): array
    {
        $title = trim(preg_replace('/\s+/', ' ', (string) ($d['title'] ?? '')) ?? '');
        $kind = in_array($d['kind'] ?? null, RecordImport::KINDS, true) ? (string) $d['kind'] : 'autre';
        $date = null;
        if (is_string($d['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'])) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $d['date']);
                $date = $parsed !== null && $parsed->format('Y-m-d') === $d['date'] && ! $parsed->isFuture() ? $d['date'] : null;
            } catch (\Throwable) {
                $date = null;
            }
        }
        $source = trim((string) ($d['source'] ?? '')) ?: null;

        return [
            'from' => $from,
            'to' => $to,
            'title' => mb_substr($title !== '' ? $title : ($from === $to ? "Page {$from}" : "Pages {$from} à {$to}"), 0, 120),
            'kind' => $kind,
            'date' => $date,
            'source' => $source !== null ? mb_substr($source, 0, 120) : null,
        ];
    }
}
