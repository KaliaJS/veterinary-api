<?php

namespace App\Services\Messages;

use App\Models\MessageAttachment;
use Closure;
use GdImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagick;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Les pièces jointes des messages (décisions de Jim, 08.11.2026) : photos et
 * PDF, dans le fil d'un client, entre collègues et entre cliniques, aussi
 * depuis l'espace du propriétaire.
 *
 * Le genre se lit dans les premiers octets, jamais dans le nom ni dans le
 * type annoncé. Une photo (JPEG, PNG, HEIC de l'iPhone) est décodée,
 * tournée selon son orientation, réduite à 2000 px de côté au plus et
 * réencodée : ses métadonnées (position GPS, appareil) ne sont jamais
 * rangées ; son aperçu fait 480 px. Un HEIC devient un JPEG quand le
 * serveur sait le lire. Un PDF est rangé tel quel.
 *
 * Le texte du message est la légende tapée, ou ce que c'est (« Photo »,
 * « 3 photos », « Document : bilan.pdf ») : l'aperçu de la liste, l'e-mail
 * et la notification le lisent tel quel.
 */
final class MessageAttachmentService
{
    /** Les marques d'un fichier HEIF de l'iPhone */
    private const array HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'];

    /** Le genre d'un fichier d'après ses premiers octets : image, pdf, ou null */
    public static function kindOf(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "\xFF\xD8\xFF"), str_starts_with($head, "\x89PNG\r\n\x1A\n"), self::isHeif($head) => 'image',
            default => null,
        };
    }

    private static function isHeif(string $head): bool
    {
        return strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIF_BRANDS, true);
    }

    /** Le serveur sait lire un HEIC (Imagick avec libheif) */
    public static function heifSupported(): bool
    {
        return class_exists(Imagick::class) && Imagick::queryFormats('HEI*') !== [];
    }

    /**
     * Ce que dit le message sans légende
     *
     * @param  Collection<int, MessageAttachment>  $attachments
     */
    public static function label(Collection $attachments): string
    {
        $images = $attachments->where('kind', 'image')->count();
        $pdfs = $attachments->where('kind', 'pdf')->count();

        return match (true) {
            $pdfs === 0 => $images === 1 ? 'Photo' : $images.' photos',
            $images === 0 && $pdfs === 1 => 'Document : '.$attachments->first()?->name,
            $images === 0 => $pdfs.' documents',
            default => ($images + $pdfs).' fichiers',
        };
    }

    /**
     * Le message (créé par `$create`, qui reçoit son texte) et ses pièces,
     * ensemble : les fichiers sont rangés d'abord, et effacés si le message
     * ne s'écrit pas.
     *
     * @template TMessage of Model
     *
     * @param  Closure(string): TMessage  $create
     * @param  string  $foreignKey  message_clientele_id, staff_message_id ou clinic_message_id
     * @param  int  $cliniqueId  la clinique où les fichiers sont rangés
     * @param  list<array{read: Closure(): string, name: string}>  $files  lus un à un (dix fichiers de 20 Mo ne sont jamais en mémoire ensemble)
     * @return TMessage
     */
    public function storeWith(Closure $create, string $foreignKey, int $cliniqueId, array $files, ?string $caption): Model
    {
        $prepared = [];
        try {
            foreach ($files as $i => $file) {
                $prepared[] = ['position' => $i, ...$this->prepare(($file['read'])(), $file['name'], $cliniqueId, 'files.'.$i)];
            }

            return DB::transaction(function () use ($create, $foreignKey, $cliniqueId, $prepared, $caption): Model {
                $rows = new Collection(array_map(fn (array $p): MessageAttachment => new MessageAttachment($p), $prepared));
                $caption = trim((string) $caption);
                $created = $create($caption !== '' ? $caption : self::label($rows));
                $created->setRelation('attachments', new Collection(array_map(
                    fn (array $p): MessageAttachment => MessageAttachment::query()->create([...$p, $foreignKey => $created->getKey(), 'clinique_id' => $cliniqueId]),
                    $prepared,
                )));

                return $created;
            });
        } catch (Throwable $e) {
            $paths = array_merge(...array_map(fn (array $p): array => array_values(array_filter([$p['path'] ?? null, $p['thumb_path'] ?? null])), $prepared));
            if ($paths !== []) {
                Storage::disk('prive')->delete($paths);
            }
            throw $e;
        }
    }

    /**
     * Un fichier vérifié, réencodé s'il s'agit d'une photo, et rangé : les
     * colonnes de sa pièce. Refusé, l'erreur nomme ce fichier (`$field`).
     *
     * @return array<string, mixed>
     */
    public function prepare(string $bytes, string $name, int $cliniqueId, string $field): array
    {
        $kind = self::kindOf(substr($bytes, 0, 32));
        if ($kind === null) {
            throw ValidationException::withMessages([$field => '« '.self::cleanName($name, '').' » n’est ni une photo (JPEG, PNG, HEIC) ni un PDF.']);
        }
        if ($kind === 'pdf') {
            return ['kind' => 'pdf', 'mime' => 'application/pdf', 'name' => self::cleanName($name, 'pdf'), 'size_bytes' => strlen($bytes), 'path' => $this->put($cliniqueId, 'pdf', $bytes)];
        }

        [$image, $mime, $orientation] = $this->decode($bytes, $name, $field);
        // Réduite d'abord, tournée ensuite : la rotation ne porte jamais sur la pleine taille
        $image = self::orient(self::fit($image, (int) config('messages.attachments.image_max_side')), $orientation);
        $isPng = $mime === 'image/png';
        $encoded = self::encode($image, $isPng);
        $thumb = self::encode(self::fit($image, (int) config('messages.attachments.thumb_side')), false);
        $path = $this->put($cliniqueId, $isPng ? 'png' : 'jpg', $encoded);
        try {
            $thumbPath = $this->put($cliniqueId, 'jpg', $thumb);
        } catch (Throwable $e) {
            Storage::disk('prive')->delete($path);
            throw $e;
        }

        return [
            'kind' => 'image', 'mime' => $isPng ? 'image/png' : 'image/jpeg', 'name' => self::cleanName($name, $isPng ? 'png' : 'jpg'),
            'size_bytes' => strlen($encoded), 'width' => imagesx($image), 'height' => imagesy($image),
            'path' => $path, 'thumb_path' => $thumbPath,
        ];
    }

    /**
     * La photo décodée. Un HEIC passe par Imagick, quand le serveur le sait :
     * sa taille lue sans le décoder, réduit avant de devenir un JPEG. Une
     * image qui ne tient pas dans la mémoire est refusée, jamais une erreur.
     *
     * @return array{0: GdImage, 1: string, 2: int} l'image, son type d'origine (PNG gardé en PNG), son orientation EXIF
     */
    private function decode(string $bytes, string $name, string $field): array
    {
        $refus = fn (string $why) => ValidationException::withMessages([$field => '« '.self::cleanName($name, '').' » : '.$why]);
        $maxPixels = (int) config('messages.attachments.max_pixels');
        if (self::isHeif(substr($bytes, 0, 32))) {
            if (! self::heifSupported()) {
                throw $refus('cette photo HEIC ne s’ouvre pas ici. Envoyez-la depuis l’iPhone, ou exportez-la en JPEG.');
            }
            try {
                $ping = new Imagick;
                $ping->pingImageBlob($bytes);
                $pixels = $ping->getImageWidth() * $ping->getImageHeight();
                $ping->clear();
            } catch (Throwable) {
                throw $refus('cette photo HEIC n’a pas pu être lue.');
            }
            // Imagick décode hors de la mémoire de PHP : borné en points, et par sa propre limite
            if ($pixels > $maxPixels) {
                throw $refus('cette photo est trop grande.');
            }
            try {
                $im = new Imagick;
                $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 512 * 1024 * 1024);
                $im->readImageBlob($bytes);
                $im->autoOrient();
                $side = (int) config('messages.attachments.image_max_side');
                if (max($im->getImageWidth(), $im->getImageHeight()) > $side) {
                    $im->thumbnailImage($side, $side, true);
                }
                $im->setImageFormat('jpeg');
                $im->stripImage();
                $bytes = $im->getImageBlob();
                $im->clear();
            } catch (Throwable) {
                throw $refus('cette photo HEIC n’a pas pu être lue.');
            }
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw $refus('cette photo n’a pas pu être lue.');
        }
        // GD tient 4 octets par point, plus la copie réduite
        if ($maxPixels < $size[0] * $size[1] || self::freeMemory() < $size[0] * $size[1] * 5) {
            throw $refus('cette photo est trop grande.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw $refus('cette photo n’a pas pu être lue.');
        }

        return [$image, $size['mime'] === 'image/png' ? 'image/png' : 'image/jpeg', self::orientationOf($bytes)];
    }

    /** La mémoire encore libre pour cette requête */
    private static function freeMemory(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $limit;
        $bytes = match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };

        return max(0, $bytes - memory_get_usage(true));
    }

    /** L'orientation EXIF d'un JPEG (1 : droite) */
    private static function orientationOf(string $bytes): int
    {
        if (! str_starts_with($bytes, "\xFF\xD8\xFF") || ! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /** L'orientation EXIF appliquée aux pixels (les métadonnées, elles, ne sont pas gardées) */
    private static function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    /**
     * Une petite image réencodée en JPEG, sans métadonnées (la première image
     * d'une vidéo ronde, 08.11.2026) ; null si ce n'est pas une image lisible.
     */
    public static function smallJpeg(string $bytes, int $side): ?string
    {
        $head = substr($bytes, 0, 32);
        if (! str_starts_with($head, "\xFF\xD8\xFF") && ! str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > 4_000_000) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);

        return $image === false ? null : self::encode(self::fit($image, $side), false);
    }

    /** L'image réduite pour que son plus grand côté ne dépasse pas `$side` */
    private static function fit(GdImage $image, int $side): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) <= $side) {
            return $image;
        }
        $ratio = $side / max($w, $h);
        $resized = imagescale($image, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)), IMG_BICUBIC);

        return $resized === false ? $image : $resized;
    }

    /** Réencodée : un PNG reste un PNG (transparence gardée), sinon un JPEG, sans aucune métadonnée */
    private static function encode(GdImage $image, bool $isPng): string
    {
        ob_start();
        if ($isPng) {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            // Un aperçu d'une image transparente : sur fond blanc
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagejpeg($flat, null, (int) config('messages.attachments.jpeg_quality'));
        }

        return (string) ob_get_clean();
    }

    private function put(int $cliniqueId, string $extension, string $bytes): string
    {
        $path = 'messages/pieces/'.$cliniqueId.'/'.Str::uuid()->toString().'.'.$extension;
        if (! Storage::disk('prive')->put($path, $bytes)) {
            throw new RuntimeException('La pièce jointe n’a pas pu être rangée.');
        }

        return $path;
    }

    /** Le nom montré : sans chemin ni caractère de contrôle, 120 caractères au plus, avec la bonne extension */
    public static function cleanName(string $name, string $extension): string
    {
        // Sans chemin ; l'extension retirée seulement si c'en est une connue (« Bilan 12.11 » garde son « .11 »)
        $file = basename(str_replace('\\', '/', $name));
        $file = (string) preg_replace('/\.(pdf|jpe?g|png|hei[cf])$/i', '', $file);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $file));
        $base = mb_substr($base !== '' ? $base : ($extension === 'pdf' ? 'Document' : 'Photo'), 0, 120);

        return $extension === '' ? $base : $base.'.'.$extension;
    }

    /**
     * Le fichier servi (ou son aperçu) : son type tel que rangé, sans que le
     * navigateur le devine ; effacé avec le compte, il n'est plus là (410).
     */
    public static function response(MessageAttachment $attachment, bool $isThumb = false): StreamedResponse
    {
        if ($attachment->erased_at !== null || $attachment->path === null) {
            abort(410, 'Ce fichier a été effacé avec le compte de son auteur.');
        }
        $path = $isThumb ? ($attachment->thumb_path ?? abort(404)) : $attachment->path;

        return Storage::disk('prive')->response($path, $attachment->name, [
            'Content-Type' => $isThumb ? 'image/jpeg' : $attachment->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Les pièces d'un message, pour la bulle ; et sa légende, quand le texte
     * n'est pas seulement ce que dit le message sans légende.
     *
     * @param  Collection<int, MessageAttachment>  $attachments
     * @return list<array<string, mixed>>
     */
    public static function payload(Collection $attachments): array
    {
        return $attachments->map(fn (MessageAttachment $a): array => [
            'id' => $a->id,
            'kind' => $a->kind,
            'name' => $a->name,
            'mime' => $a->mime,
            'size_bytes' => $a->size_bytes,
            'width' => $a->width,
            'height' => $a->height,
            'has_thumb' => $a->thumb_path !== null,
            'erased' => $a->erased_at !== null,
        ])->values()->all();
    }

    /**
     * La légende d'un message à pièces jointes : son texte, sauf s'il n'est que ce que dit le message sans légende
     *
     * @param  Collection<int, MessageAttachment>  $attachments
     */
    public static function caption(string $text, Collection $attachments): ?string
    {
        return $attachments->isEmpty() || $text === self::label($attachments) ? null : $text;
    }
}
