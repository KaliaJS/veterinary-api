<?php

namespace App\Console\Commands;

use App\Models\MessageMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Données personnelles (07.11.2026) : le fichier d'un message audio ou
 * vidéo s'efface avec son message. Effacé par le modèle, il part tout de
 * suite (MessageMedia::booted). Effacé en cascade par la base (clinique,
 * organisation, employé, compte), il reste sur le disque : cette purge,
 * chaque nuit, efface tout fichier de `messages/` qu'aucun message ne
 * porte plus. Un fichier de moins d'une heure attend : il peut appartenir
 * à un envoi en cours, rangé avant que son message ne s'écrive.
 */
class PurgeMessageMedia extends Command
{
    protected $signature = 'messages:purge-media';

    protected $description = 'Efface les fichiers audio et vidéo des messages effacés';

    public function handle(): int
    {
        $disk = Storage::disk('prive');
        $limit = now()->subHour()->getTimestamp();
        $deleted = 0;

        $files = collect($disk->getDriver()->listContents('messages', true))
            ->filter(fn ($item): bool => $item instanceof FileAttributes)
            ->filter(fn (FileAttributes $file): bool => ($file->lastModified() ?? $disk->lastModified($file->path())) < $limit)
            ->map(fn (FileAttributes $file): string => $file->path());

        foreach ($files->chunk(500) as $chunk) {
            $kept = MessageMedia::query()->whereIn('path', $chunk->values()->all())->pluck('path')->all();
            $orphans = array_values(array_diff($chunk->values()->all(), $kept));
            if ($orphans !== []) {
                $disk->delete($orphans);
                $deleted += count($orphans);
            }
        }

        $this->info($deleted.' fichier(s) effacé(s).');

        return self::SUCCESS;
    }
}
