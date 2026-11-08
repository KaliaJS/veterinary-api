<?php

namespace App\Http\Requests\V1\Messages;

use App\Services\Messages\MediaDuration;
use App\Services\Messages\MessageMediaService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Un message audio ou une vidéo ronde (07.11.2026), envoyé d'un coup : le
 * fichier enregistré par l'app, sa durée (vérifiée dans le fichier) et son onde. Le type se lit dans
 * les premiers octets du fichier, jamais dans son nom ni dans le type
 * annoncé par le navigateur : WebM (Chrome, Firefox), MP4 (Safari, app
 * iPhone), Ogg pour l'audio seulement.
 */
class SendMessageMediaRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $limits = $this->limits();

        return [
            'kind' => ['required', 'string', 'in:audio,video'],
            'file' => ['required', 'file', 'max:'.$limits['max_kb'], function (string $attribute, mixed $file, Closure $fail): void {
                $head = $file instanceof UploadedFile ? (string) @file_get_contents((string) $file->getRealPath(), false, null, 0, 64) : '';
                if (MessageMediaService::mime((string) $this->input('kind'), $head) === null) {
                    $fail($this->input('kind') === 'video' ? 'Ce fichier n’est pas une vidéo enregistrée par l’app.' : 'Ce fichier n’est pas un enregistrement audio.');
                }
            }],
            'duration_ms' => ['required', 'integer', 'min:'.(int) config('messages.media.min_ms'), 'max:'.($limits['max_seconds'] * 1000 + (int) config('messages.media.tolerance_ms'))],
            'peaks' => ['required', 'array', 'min:1', 'max:'.(int) config('messages.media.peaks_max')],
            // La première image d'une vidéo (08.11.2026) : petite, et seulement pour une vidéo
            'poster' => ['nullable', 'prohibited_unless:kind,video', 'file', 'max:300'],
            'peaks.*' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $video = $this->input('kind') === 'video';

        return [
            'kind.*' => 'Un message est audio ou vidéo.',
            'file.required' => 'Le fichier de l’enregistrement manque.',
            'file.file' => 'Le fichier de l’enregistrement manque.',
            'file.uploaded' => 'Le fichier de l’enregistrement n’est pas arrivé en entier.',
            'file.max' => sprintf($video ? 'Cette vidéo est trop lourde (%s Mo au plus).' : 'Ce message audio est trop lourd (%s Mo au plus).', self::megabytes($this->limits()['max_kb'])),
            'duration_ms.max' => self::tooLong($video),
            'duration_ms.min' => 'Cet enregistrement est trop court.',
            'duration_ms.*' => 'La durée de l’enregistrement manque.',
            'peaks.max' => 'L’onde de l’enregistrement est trop longue.',
            // Une barre de l'onde (« peaks.3.between ») avant l'onde entière
            'peaks.*.*' => 'L’onde de l’enregistrement est invalide.',
            'peaks.*' => 'L’onde de l’enregistrement manque.',
        ];
    }

    private static function megabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1, ',', ''), '0'), ',');
    }

    private static function tooLong(bool $video): string
    {
        return $video ? 'Une vidéo dure deux minutes au plus.' : 'Un message audio dure deux minutes au plus.';
    }

    /** La durée lue dans le fichier (vérification du 07.11.2026), en millisecondes */
    private ?int $realDurationMs = null;

    /**
     * La vraie durée, lue dans le fichier sans ffprobe : elle doit se lire,
     * tenir dans la limite du genre et ne pas trop s'écarter de celle
     * annoncée (un fichier « d'une seconde » qui dure des heures ne passe
     * pas). C'est elle qui est gardée et servie.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $file = $this->file('file');
            // Lu par position, jamais chargé en entier (quatrième vérification)
            $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : '';
            $head = $path !== '' ? (string) @file_get_contents($path, false, null, 0, 64) : '';
            $container = (string) MessageMediaService::container($head);
            $octets = $path !== '' ? (int) @filesize($path) : 0;
            $seconds = $path !== '' ? MediaDuration::secondsOfFile($path, $container) : null;
            if ($seconds === null) {
                $validator->errors()->add('file', 'La durée de cet enregistrement ne se lit pas.');

                return;
            }
            $real = (int) round($seconds * 1000);
            $declared = (int) $this->input('duration_ms');
            $limits = $this->limits();
            if (abs($real - $declared) > max((int) config('messages.media.gap_ms'), (int) ($declared * (float) config('messages.media.gap_ratio')))) {
                $validator->errors()->add('duration_ms', 'La durée annoncée ne correspond pas à l’enregistrement.');

                return;
            }
            if ($real > $limits['max_seconds'] * 1000 + (int) config('messages.media.tolerance_ms')) {
                $validator->errors()->add('duration_ms', self::tooLong($this->input('kind') === 'video'));

                return;
            }
            // Plus lourd que ne le permet sa durée : une durée forgée cache un long enregistrement
            $kind = $this->input('kind') === 'video' ? 'video' : 'audio';
            // Le débit permis selon le format : l'AAC de Safari (MP4) plus haut que l'Opus de Chrome et Firefox
            $bitrate = $kind === 'audio' && $container === 'mp4' ? 'audio_mp4' : $kind;
            // Plus une marge qui suit la durée pour le MP4 : sa table stsz (4 octets par trame AAC)
            $allowed = (int) config('messages.media.max_bitrate.'.$bitrate) / 8 * $seconds
                + (int) config('messages.media.bitrate_slack_kb.'.$kind) * 1024
                + (int) config('messages.media.slack_bytes_per_second.'.$bitrate, 0) * $seconds;
            if ($octets > $allowed) {
                $validator->errors()->add('file', 'Ce fichier est trop lourd pour sa durée.');

                return;
            }
            // Dans la tolérance mais au-delà de la limite (un onglet caché a retardé l'arrêt) : gardée à la limite,
            // la bulle dit 2:00 et non 2:01 (vérification du 08.11.2026)
            $this->realDurationMs = max((int) config('messages.media.min_ms'), min($real, $limits['max_seconds'] * 1000));
        }];
    }

    /** La durée à garder : celle du fichier */
    public function durationMs(): int
    {
        return $this->realDurationMs ?? (int) $this->validated('duration_ms');
    }

    /** @return array{max_seconds: int, max_kb: int} */
    private function limits(): array
    {
        $kind = $this->input('kind') === 'video' ? 'video' : 'audio';

        return config('messages.media.'.$kind);
    }
}
