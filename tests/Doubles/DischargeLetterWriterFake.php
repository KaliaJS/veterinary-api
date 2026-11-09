<?php

namespace Tests\Doubles;

use App\Services\Hospitalisation\DischargeLetterImpossible;
use App\Services\Hospitalisation\DischargeLetterWriter;
use Throwable;

/**
 * Double du rédacteur de la lettre de sortie : garde les sources reçues,
 * rend un brouillon fixé (`result`) ; erreur programmable.
 */
class DischargeLetterWriterFake implements DischargeLetterWriter
{
    /** @var list<list<array{id: string, kind: string, label: string, text: string}>> */
    public array $calls = [];

    public ?Throwable $error = null;

    /** @var array{sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>} */
    public array $result = [
        'sections' => [
            'stay' => [['text' => 'Bandit a été hospitalisé du jeudi 5 au lundi 9 novembre.', 'sources' => ['S1']]],
            'during' => [
                ['text' => 'Le jeudi 5 novembre, Bandit a été opéré pour retirer un noyau de pêche.', 'sources' => ['B1']],
                ['text' => 'Il a recommencé à manger le vendredi 6 au soir.', 'sources' => ['R1']],
            ],
            'home' => [['text' => 'Bandit garde sa collerette jusqu’au contrôle.', 'sources' => ['N1']]],
            'call' => [],
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

    public static function refusal(): DischargeLetterImpossible
    {
        return new DischargeLetterImpossible('Refus.');
    }
}
