<?php

namespace App\Http\Requests\V1\Messages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Un message à pièces jointes (décisions de Jim, 08.11.2026) : dix fichiers
 * au plus, photos et PDF, 20 Mo chacun, avec une légende facultative. Côté
 * clinique, dans le fil d'un client, des documents de son dossier peuvent
 * s'y ajouter (`document_ids`), dans la même limite de dix. Le genre de
 * chaque fichier se lit dans ses octets (MessageAttachmentService).
 */
class SendMessageAttachmentsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = (int) config('messages.attachments.max_files');

        return [
            'texte' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array', 'max:'.$max],
            'files.*' => ['required', 'file', 'max:'.(int) config('messages.attachments.max_kb')],
            'document_ids' => ['nullable', 'array', 'max:'.$max],
            'document_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $count = count($this->uploads()) + count($this->documentIds());
            if ($count === 0) {
                $validator->errors()->add('files', 'Choisissez au moins une photo ou un document.');
            } elseif ($count > (int) config('messages.attachments.max_files')) {
                $validator->errors()->add('files', sprintf('%d fichiers au plus par message.', (int) config('messages.attachments.max_files')));
            } elseif (array_sum(array_map(fn (UploadedFile $f): int => (int) $f->getSize(), $this->uploads())) > self::maxTotalBytes()) {
                $validator->errors()->add('files', self::tooHeavy());
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $mo = intdiv((int) config('messages.attachments.max_kb'), 1024);

        return [
            'files.max' => sprintf('%d fichiers au plus par message.', (int) config('messages.attachments.max_files')),
            'document_ids.max' => sprintf('%d fichiers au plus par message.', (int) config('messages.attachments.max_files')),
            'files.*.max' => 'Un fichier pèse '.$mo.' Mo au plus.',
            'files.*.uploaded' => 'Un fichier n’est pas arrivé en entier (plus de '.$mo.' Mo ?).',
            'files.*.*' => 'Un fichier manque.',
            'document_ids.*.*' => 'Un document du dossier est invalide.',
            'texte.max' => 'La légende fait 2000 caractères au plus.',
        ];
    }

    public static function maxTotalBytes(): int
    {
        return (int) config('messages.attachments.max_total_kb') * 1024;
    }

    public static function tooHeavy(): string
    {
        return intdiv((int) config('messages.attachments.max_total_kb'), 1024).' Mo au plus par message : envoyez le reste dans un autre message.';
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn ($f): bool => $f instanceof UploadedFile));
    }

    /** @return list<int> */
    public function documentIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('document_ids', [])));
    }

    /**
     * Les fichiers envoyés, lus un à un au moment de les ranger.
     *
     * @return list<array{read: \Closure(): string, name: string}>
     */
    public function readers(): array
    {
        return array_map(fn (UploadedFile $f): array => ['read' => fn (): string => (string) file_get_contents((string) $f->getRealPath()), 'name' => $f->getClientOriginalName()], $this->uploads());
    }
}
