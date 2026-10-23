<?php

namespace App\Models\Concerns;

/**
 * Le nom du fichier de la photo (sans dossier ni extension), tenu à côté
 * de son chemin et indexé : la route publique d'une photo la retrouve par
 * égalité, jamais par un `LIKE` sur tout le chemin (23.10.2026).
 */
trait AUnePhoto
{
    public static function bootAUnePhoto(): void
    {
        static::saving(function (self $modele): void {
            if ($modele->isDirty('photo_chemin')) {
                $modele->photo_fichier = $modele->photo_chemin ? pathinfo((string) $modele->photo_chemin, PATHINFO_FILENAME) : null;
            }
        });
    }
}
