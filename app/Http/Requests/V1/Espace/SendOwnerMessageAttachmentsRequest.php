<?php

namespace App\Http\Requests\V1\Espace;

use App\Http\Requests\V1\Messages\SendMessageAttachmentsRequest;

/** Un message à pièces jointes du propriétaire, à l'une de ses cliniques (08.11.2026) : photos et PDF, sans document de dossier. */
class SendOwnerMessageAttachmentsRequest extends SendMessageAttachmentsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            ...parent::rules(),
            'document_ids' => ['prohibited'],
        ];
    }
}
