<?php

namespace App\Services\Hospitalisation;

/**
 * Rédige le brouillon de la lettre de sortie depuis des sources numérotées
 * (09.11.2026). Rend, par rubrique, des phrases avec les numéros de leurs
 * sources, et les passages douteux de la dictée. Une erreur temporaire
 * remonte pour être réessayée ; un refus définitif lève
 * DischargeLetterImpossible.
 */
interface DischargeLetterWriter
{
    /**
     * @param  list<array{id: string, kind: string, label: string, text: string, recording_id?: int}>  $sources
     * @param  array<string, string>  $context
     * @return array{sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>}
     */
    public function write(array $sources, array $context): array;
}
