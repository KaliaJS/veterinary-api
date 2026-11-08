<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Services\Messages\MessageAttachmentService;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les pièces jointes des messages, côté clinique (08.11.2026) : le fichier
 * et l'aperçu d'une photo. Les lisent, comme les audios et les vidéos :
 * l'équipe de la clinique du fil d'un client, les deux collègues d'une
 * conversation, les équipes des deux cliniques d'un canal. Ailleurs, la
 * pièce n'existe pas.
 */
class MessageAttachmentController extends Controller
{
    public function show(int $attachment): StreamedResponse
    {
        return MessageAttachmentService::response($this->attachment($attachment));
    }

    public function thumb(int $attachment): StreamedResponse
    {
        return MessageAttachmentService::response($this->attachment($attachment), isThumb: true);
    }

    private function attachment(int $id): MessageAttachment
    {
        $contexte = app(ContexteClinique::class);
        $clinique = (int) $contexte->cliniqueId();
        $moi = (int) $contexte->employe()?->id;

        return MessageAttachment::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('message', fn (Builder $client) => $client->where('clinique_id', $clinique))
                ->orWhereHas('staffMessage', fn (Builder $staff) => $staff->where(fn (Builder $deux) => $deux->where('sender_employee_id', $moi)->orWhere('recipient_employee_id', $moi)))
                ->orWhereHas('clinicMessage', fn (Builder $canal) => $canal->where(fn (Builder $deux) => $deux->where('sender_clinic_id', $clinique)->orWhere('recipient_clinic_id', $clinique))))
            ->findOrFail($id);
    }
}
