<?php

namespace Tests\Doubles;

use App\Services\Labs\LabSummaryWriter;
use Throwable;

/** Double du rédacteur du résumé d'analyse : garde les sources reçues, rend un brouillon fixé ; erreur programmable. */
class LabSummaryWriterFake implements LabSummaryWriter
{
    /** @var list<list<array{id: string, kind: string, label: string, text: string}>> */
    public array $calls = [];

    public ?Throwable $error = null;

    /** @var array{sections: array<string, list<array{text: string, sources: list<string>}>>} */
    public array $result = [
        'sections' => [
            'owner' => [
                ['text' => 'La créatinine, qui reflète le travail des reins, est plus haute que la normale.', 'sources' => ['V1']],
                ['text' => 'Elle est en hausse sur les trois dernières prises de sang.', 'sources' => ['T1']],
                ['text' => 'Diagnostic inventé.', 'sources' => ['X9']],
            ],
        ],
    ];

    public function write(array $sources, array $context): array
    {
        $this->calls[] = $sources;
        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }
}
