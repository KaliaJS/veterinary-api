<?php

namespace App\Http\Requests\V1\Espace;

use App\Http\Requests\V1\Messages\SendMessageMediaRequest;

/** Un message audio ou vidéo du propriétaire, à l'une de ses cliniques (07.11.2026). */
class SendOwnerMessageMediaRequest extends SendMessageMediaRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            ...parent::rules(),
        ];
    }
}
