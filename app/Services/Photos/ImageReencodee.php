<?php

namespace App\Services\Photos;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Une photo déposée n'est jamais gardée telle quelle (23.10.2026) : elle
 * est relue, redressée, réduite et réécrite en WebP. L'image réécrite ne
 * porte plus rien de l'appareil (EXIF, position GPS, date) ; son nom est
 * imprévisible. GD suffit, sans paquet.
 */
final class ImageReencodee
{
    public const int QUALITE = 80;

    /** Lit l'image déposée, redressée selon son orientation EXIF. */
    public static function lire(UploadedFile $fichier): GdImage
    {
        // Une photo d'appareil de 8 Mo se décompresse en bien plus en mémoire
        ini_set('memory_limit', '512M');

        $image = @imagecreatefromstring((string) file_get_contents($fichier->getRealPath()));
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Image illisible.');
        }

        $orientation = function_exists('exif_read_data') ? (@exif_read_data($fichier->getRealPath())['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => 270, 8 => 90][$orientation] ?? 0;

        return $angle ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }

    /** Réduite à `$cote` pixels sur son plus grand côté (jamais agrandie). */
    public static function reduire(GdImage $image, int $cote): GdImage
    {
        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        if (max($largeur, $hauteur) <= $cote) {
            return $image;
        }

        return $largeur >= $hauteur
            ? (imagescale($image, $cote) ?: $image)
            : (imagescale($image, (int) round($largeur * $cote / $hauteur), $cote) ?: $image);
    }

    public static function webp(GdImage $image, int $qualite = self::QUALITE): string
    {
        ob_start();
        imagewebp($image, null, $qualite);

        return (string) ob_get_clean();
    }

    /**
     * Un logo réécrit (24.10.2026) : comme une photo, sans ses métadonnées
     * et borné à `$cote` pixels, mais sa transparence gardée.
     */
    public static function logo(UploadedFile $fichier, string $dossier, int $cote): string
    {
        $source = self::lire($fichier);
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $echelle = min(1, $cote / max($largeur, $hauteur));
        $l = max(1, (int) round($largeur * $echelle));
        $h = max(1, (int) round($hauteur * $echelle));

        $logo = imagecreatetruecolor($l, $h);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagefill($logo, 0, 0, (int) imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagecopyresampled($logo, $source, 0, 0, 0, 0, $l, $h, $largeur, $hauteur);

        $chemin = trim($dossier, '/').'/'.Str::random(40).'.webp';
        Storage::disk('prive')->put($chemin, self::webp($logo, 90));

        return $chemin;
    }

    /**
     * Range la photo réécrite dans `$dossier` du disque privé ; rend son
     * chemin. Le nom du fichier (sans dossier) sert à la retrouver.
     */
    public static function enregistrer(UploadedFile $fichier, string $dossier, int $cote): string
    {
        $chemin = trim($dossier, '/').'/'.Str::random(40).'.webp';
        Storage::disk('prive')->put($chemin, self::webp(self::reduire(self::lire($fichier), $cote)));

        return $chemin;
    }
}
