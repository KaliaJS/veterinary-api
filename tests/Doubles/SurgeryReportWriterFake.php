<?php

namespace Tests\Doubles;

use App\Services\Bloc\SurgeryReportImpossible;
use App\Services\Bloc\SurgeryReportWriter;
use Throwable;

/**
 * Double du rédacteur du rapport de bloc : garde les sources reçues, rend
 * un brouillon fixé (`result`) ; erreur programmable.
 */
class SurgeryReportWriterFake implements SurgeryReportWriter
{
    /** @var list<list<array{id: string, kind: string, label: string, text: string}>> */
    public array $calls = [];

    public ?Throwable $error = null;

    /** @var array{sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>} */
    public array $result = [
        'sections' => [
            'intervention' => [['text' => 'Orchiectomie chez Filou, par la Dre Anna Meyer.', 'sources' => ['E1']]],
            'anaesthesia' => [['text' => 'Induction au propofol 2 mg/kg IV.', 'sources' => ['E3']]],
            'technique' => [['text' => 'Abord préscrotal — technique fermée.', 'sources' => ['N1']]],
            'material' => [],
            'complications' => [],
            'aftercare' => [['text' => 'Collerette pendant 10 jours.', 'sources' => ['N2']]],
            'implants' => [],
        ],
        'doubts' => [],
    ];

    public function write(array $sources, array $context): array
    {
        $this->calls[] = $sources;
        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

    public static function refusal(): SurgeryReportImpossible
    {
        return new SurgeryReportImpossible('Refus.');
    }
}
