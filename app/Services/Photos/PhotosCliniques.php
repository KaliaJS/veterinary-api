<?php

namespace App\Services\Photos;

use App\Models\Clinique;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Photos des cliniques (annuaire) : à l'envoi, l'originale est gardée et
 * l'on en tire des versions WebP de quelques largeurs (le navigateur prend
 * celle qu'il faut) et un aperçu minuscule, flouté à l'écran le temps que
 * la vraie photo arrive (comme le foodtruck). GD suffit, sans paquet.
 */
final class PhotosCliniques
{
    /** Largeurs des versions WebP servies (vignette de la liste, bandeau de la fiche, écrans denses). */
    public const array LARGEURS = [480, 1200];

    public const int QUALITE = 80;

    public const int APERCU_LARGEUR = 24;

    public const int APERCU_QUALITE = 30;

    private const string DISQUE = 'local';

    /**
     * Range la photo déposée et ses versions.
     *
     * @return array{chemin: string, apercu: string}
     */
    public function enregistrer(UploadedFile $fichier, int $organisationId): array
    {
        // Une photo d'appareil de 8 Mo se décompresse en bien plus en mémoire
        ini_set('memory_limit', '512M');

        $image = $this->lire($fichier);
        $chemin = $fichier->store('photos-cliniques/'.$organisationId, self::DISQUE);

        foreach (self::LARGEURS as $largeur) {
            $version = imagesx($image) > $largeur ? imagescale($image, $largeur) : $image;
            Storage::disk(self::DISQUE)->put(self::version($chemin, $largeur), $this->webp($version, self::QUALITE));
        }

        $petite = imagescale($image, self::APERCU_LARGEUR);
        $apercu = 'data:image/webp;base64,'.base64_encode($this->webp($petite, self::APERCU_QUALITE));

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

    private function lire(UploadedFile $fichier): GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($fichier->getRealPath()));

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Image illisible.');
        }

        // Photo de téléphone : redressée selon son orientation EXIF
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($fichier->getRealPath())['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => 270, 8 => 90][$orientation] ?? 0;

        return $angle ? imagerotate($image, $angle, 0) : $image;
    }

    private function webp(GdImage $image, int $qualite): string
    {
        ob_start();
        imagewebp($image, null, $qualite);

        return (string) ob_get_clean();
    }
}
