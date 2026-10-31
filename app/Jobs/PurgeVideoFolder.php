<?php

namespace App\Jobs;

use App\Services\Videos\VideoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Le dossier d'un brouillon supprimé pendant son envoi (31.10.2026,
 * sixième vérification) : les adresses d'envoi déjà données écrivent
 * encore 15 minutes. On efface le dossier une fois toutes expirées, sinon
 * ces fichiers resteraient au bucket sans vidéo ni stockage qui les compte.
 */
class PurgeVideoFolder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [300, 900, 2700, 7200];

    public function __construct(public string $folder) {}

    public function handle(VideoStorage $storage): void
    {
        // Le disque ne lève rien sur une panne : un effacement raté se dit, et la file réessaie
        if (! $storage->deleteFolder($this->folder)) {
            throw new RuntimeException('Dossier de vidéo pas effacé : '.$this->folder);
        }
    }
}
