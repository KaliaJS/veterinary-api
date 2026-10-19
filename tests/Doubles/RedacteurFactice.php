<?php

namespace Tests\Doubles;

use App\Services\Transcription\BrouillonSoap;
use App\Services\Transcription\Redacteur;
use Throwable;

/** Double du rédacteur : garde segments et contexte reçus, rend un brouillon fixé ; erreur programmable. */
class RedacteurFactice implements Redacteur
{
    /** @var list<array{segments: list<array<string, mixed>>, contexte: array<string, string>}> */
    public array $appels = [];

    public ?Throwable $erreur = null;

    public function rediger(array $segments, array $contexte): BrouillonSoap
    {
        $this->appels[] = ['segments' => $segments, 'contexte' => $contexte];
        if ($this->erreur) {
            throw $this->erreur;
        }

        return new BrouillonSoap('Boiterie depuis 3 jours, surtout le matin.', 'Chaleur au genou droit.', '', 'Repos 10 jours.', [
            ['rubrique' => 'o', 'passage' => 'genou droit', 'entendu' => 'genou droit', 'raison' => 'Côté du genou à confirmer.'],
        ]);
    }
}
