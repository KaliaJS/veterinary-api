<?php

namespace Tests\Doubles;

use App\Services\Hospitalisation\DailyReportWriter;
use Throwable;

/** Double du rédacteur du rapport de la journée : garde la feuille reçue, rend un texte fixé ; erreur programmable. */
class DailyReportWriterFake implements DailyReportWriter
{
    /** @var list<string> */
    public array $calls = [];

    public ?Throwable $error = null;

    public function write(string $facts): string
    {
        $this->calls[] = $facts;
        if ($this->error) {
            throw $this->error;
        }

        return "Tao a reçu son maropitant à 10:00.\nDouleur sous le seuil en fin de journée.";
    }
}
