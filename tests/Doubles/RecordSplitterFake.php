<?php

namespace Tests\Doubles;

use App\Services\Imports\RecordSplitter;
use Throwable;

/** Double du découpeur : garde les pages reçues, rend une découpe fixée (`result`) ; erreur programmable. */
class RecordSplitterFake implements RecordSplitter
{
    /** @var list<list<array{n: int, text: string, image: ?string}>> */
    public array $calls = [];

    public ?Throwable $error = null;

    /** @var list<array<string, mixed>> */
    public array $result = [
        ['from' => 1, 'to' => 2, 'title' => 'Hématologie et biochimie', 'kind' => 'laboratoire', 'date' => '2026-10-02', 'source' => 'Laboratoire Vetlab'],
        ['from' => 3, 'to' => 3, 'title' => 'Rapport de radiographie du genou', 'kind' => 'imagerie', 'date' => '2026-10-05', 'source' => null],
    ];

    public function split(array $pages): array
    {
        $this->calls[] = $pages;
        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }
}
