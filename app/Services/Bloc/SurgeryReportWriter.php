<?php

namespace App\Services\Bloc;

/**
 * Rédige le brouillon du rapport de bloc depuis des sources numérotées
 * (09.11.2026). Rend, par rubrique, des phrases avec les numéros de leurs
 * sources, et les passages douteux. Une erreur temporaire remonte pour être
 * réessayée ; un refus définitif lève SurgeryReportImpossible.
 */
interface SurgeryReportWriter
{
    /**
     * @param  list<array{id: string, kind: string, label: string, text: string, recording_id?: int}>  $sources
     * @param  array<string, string>  $context
     * @return array{sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>}
     */
    public function write(array $sources, array $context): array;
}
