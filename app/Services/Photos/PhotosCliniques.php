<?php

namespace App\Services\Photos;

use App\Models\Clinique;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos des cliniques (annuaire) : à l'envoi, l'on tire de la photo des
 * versions WebP de quelques largeurs (le navigateur prend celle qu'il
 * faut) et un aperçu minuscule, flouté à l'écran le temps que la vraie
 * photo arrive (comme le foodtruck). L'originale n'est plus gardée
 * (23.10.2026) : elle portait la position GPS de l'appareil. GD suffit,
 * sans paquet.
 */
final class PhotosCliniques
{
    /** Largeurs des versions WebP servies (vignette de la liste, bandeau de la fiche, écrans denses). */
    public const array LARGEURS = [480, 1200];

    public const int QUALITE = 80;

    public const int APERCU_LARGEUR = 24;

    public const int APERCU_QUALITE = 30;

    private const string DISQUE = 'prive';

    /**
     * Range la photo déposée et ses versions.
     *
     * @return array{chemin: string, apercu: string}
     */
    public function enregistrer(UploadedFile $fichier, int $organisationId): array
    {
        $image = ImageReencodee::lire($fichier);
        // Le chemin de base : seules ses versions existent sur le disque
        $chemin = 'photos-cliniques/'.$organisationId.'/'.Str::random(40).'.webp';

        foreach (self::LARGEURS as $largeur) {
            // Bornée sur son plus grand côté (24.10.2026) : une photo très
            // haute ne garde pas ses 8000 pixels de hauteur
            $version = ImageReencodee::reduire($image, $largeur);
            Storage::disk(self::DISQUE)->put(self::version($chemin, $largeur), ImageReencodee::webp($version, self::QUALITE));
        }

        $petite = imagescale($image, self::APERCU_LARGEUR);
        $apercu = 'data:image/webp;base64,'.base64_encode(ImageReencodee::webp($petite ?: $image, self::APERCU_QUALITE));

        return ['chemin' => $chemin, 'apercu' => $apercu];
    }

    /** Efface l'originale et ses versions. */
    public function supprimer(?string $chemin): void
    {
        if ($chemin === null) {
            return;
        }

        Storage::disk(self::DISQUE)->delete([
            $chemin,
            ...array_map(fn (int $largeur): string => self::version($chemin, $largeur), self::LARGEURS),
        ]);
    }

    /** Chemin de la version WebP d'une largeur, à côté de l'originale. */
    public static function version(string $chemin, int $largeur): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $chemin).'-'.$largeur.'.webp';
    }

    /**
     * Ce que l'écran affiche : la grande version, toutes les largeurs pour
     * `srcset`, et l'aperçu flou.
     *
     * @return array{url: string, srcset: string, apercu: ?string}|null
     */
    public static function pour(Clinique $clinique): ?array
    {
        if ($clinique->photo_chemin === null) {
            return null;
        }

        $url = fn (int $largeur): string => route('clinique.photo', ['fichier' => basename(self::version($clinique->photo_chemin, $largeur))]);

        return [
            'url' => $url(max(self::LARGEURS)),
            'srcset' => implode(', ', array_map(fn (int $largeur): string => $url($largeur).' '.$largeur.'w', self::LARGEURS)),
            'apercu' => $clinique->photo_apercu,
        ];
    }
}
