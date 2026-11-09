<?php

namespace Tests\Doubles;

use App\Services\Referrals\ReferralLetterImpossible;
use App\Services\Referrals\ReferralLetterWriter;
use Throwable;

/**
 * Double du rédacteur de la lettre de référence : garde les sources reçues,
 * rend un brouillon fixé (`result`) ; erreur programmable.
 */
class ReferralLetterWriterFake implements ReferralLetterWriter
{
    /** @var list<list<array{id: string, kind: string, label: string, text: string}>> */
    public array $calls = [];

    public ?Throwable $error = null;

    /** @var array{sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>} */
    public array $result = [
        'sections' => [
            'reason' => [['text' => 'Suspicion de rupture du ligament croisé crânial gauche.', 'sources' => ['N1']]],
            'history' => [['text' => 'Boiterie postérieure gauche depuis trois semaines.', 'sources' => ['P1']]],
            'exams' => [],
            'request' => [['text' => 'Nous vous remercions de votre avis chirurgical.', 'sources' => ['N2']]],
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

    public static function refusal(): ReferralLetterImpossible
    {
        return new ReferralLetterImpossible('Refus.');
    }
}
