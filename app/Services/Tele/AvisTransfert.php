<?php

namespace App\Services\Tele;

use App\Auth\ContexteClinique;
use App\Events\TransfertAppelVideo;
use App\Models\Clinique;
use App\Models\SessionTeleconsultation;
use App\Support\TempsReel;

/**
 * Prévient un compte, sur son canal, d'un transfert d'appel vidéo
 * (28.10.2026) : demandé, annulé, terminé pendant l'attente, ou pris.
 * La clinique de l'appel est dite : un collègue de deux cliniques l'ouvre
 * avant de prendre l'appel.
 */
class AvisTransfert
{
    /** @param  array<string, mixed>  $plus */
    public function envoyer(int $userId, SessionTeleconsultation $session, string $etat, array $plus = []): void
    {
        $donnees = [
            'etat' => $etat,
            'session_id' => $session->id,
            'patient' => $session->patient()->withoutGlobalScopes()->value('nom'),
            'de' => app(ContexteClinique::class)->employe()?->nom,
            'clinique_id' => $session->clinique_id,
            'clinique' => Clinique::withoutGlobalScopes()->whereKey($session->clinique_id)->value('nom'),
            ...$plus,
        ];
        TempsReel::diffuser(fn () => TransfertAppelVideo::dispatch($userId, $donnees));
    }
}
