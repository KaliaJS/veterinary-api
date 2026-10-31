<?php

namespace App\Jobs;

use App\Services\Videos\VideoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

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
        $storage->deleteFolder($this->folder);
    }
}
