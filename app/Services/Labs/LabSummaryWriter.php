<?php

namespace App\Services\Labs;

/** Le rédacteur du résumé d'une analyse (09.11.2026) : des phrases, chacune avec ses sources */
interface LabSummaryWriter
{
    /**
     * @param  list<array{id: string, kind: string, label: string, text: string}>  $sources
     * @param  array<string, string>  $context
     * @return array{sections: array<string, list<array{text: string, sources: list<string>}>>}
     *
     * @throws LabSummaryImpossible
     */
    public function write(array $sources, array $context): array;
}
