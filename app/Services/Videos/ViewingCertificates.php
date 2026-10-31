<?php

namespace App\Services\Videos;

use App\Enums\VideoFilesStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Le certificat de visionnage (31.10.2026), pour la formation continue :
 * délivré à qui a regardé la vidéo en entier (95 %), figé, et vérifiable
 * par tous grâce à son code.
 */
class ViewingCertificates
{
    /** Sans caractères qui se confondent (0, O, 1, I, L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function issue(Video $video, User $user): ViewingCertificate
    {
        // Pas de certificat de son propre brouillon, ni de sa propre vidéo
        if ($video->published_at === null || $video->isOnSellerSide($user)) {
            throw ValidationException::withMessages(['certificate' => 'Le certificat se délivre pour une vidéo de formation suivie, pas pour la sienne.']);
        }
        $existing = ViewingCertificate::query()->where('video_id', $video->id)->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        // Les fichiers en cours de remplacement ou refusés : ce qu'on avait regardé n'est plus cette vidéo
        if ($video->files_status !== VideoFilesStatus::Ready || $video->duration_seconds === null) {
            throw ValidationException::withMessages(['certificate' => 'Cette vidéo change de fichiers : le certificat se délivre une fois la nouvelle version regardée.']);
        }
        $view = VideoView::query()->where('video_id', $video->id)->where('user_id', $user->id)->first();
        if ($view?->completed_at === null) {
            throw ValidationException::withMessages(['certificate' => 'Le certificat se délivre une fois la vidéo regardée en entier.']);
        }

        return ViewingCertificate::query()->create([
            'code' => $this->code(),
            'video_id' => $video->id,
            'user_id' => $user->id,
            'holder_name' => $user->name,
            'holder_gln' => $user->estVerifie() ? $user->gln : null,
            'video_title' => (string) $video->title,
            'seller_name' => $video->seller_name,
            'duration_seconds' => $video->duration_seconds,
            'watched_at' => $view->completed_at,
        ]);
    }

    private function code(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = implode('-', str_split($raw, 4));
        } while (ViewingCertificate::query()->where('code', $code)->exists());

        return $code;
    }

    /** Le code tel qu'on le tape (minuscules, espaces, sans tirets), remis au format ABCD-EFGH-JKLM. */
    public static function normalize(string $code): string
    {
        $raw = (string) preg_replace('/[^A-Z0-9]/', '', Str::upper($code));

        return strlen($raw) === 12 ? implode('-', str_split($raw, 4)) : Str::upper(trim($code));
    }
}
