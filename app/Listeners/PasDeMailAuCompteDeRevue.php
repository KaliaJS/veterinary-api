<?php

namespace App\Listeners;

use App\Auth\CompteRevueApple;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Aucun e-mail ne part vers l'adresse de revue d'Apple (27.10.2026) :
 * personne ne lit cette boîte, et ses e-mails (accusé d'une demande de
 * rendez-vous, notifications…) ne doivent partir nulle part. Coupé au moment
 * de l'envoi, pour tous les e-mails à la fois : elle est retirée des
 * destinataires et des copies, le reste part ; un message qui n'était
 * adressé qu'à elle n'est pas envoyé (ses copies non plus).
 */
class PasDeMailAuCompteDeRevue
{
    public function __construct(private CompteRevueApple $revue) {}

    public function handle(MessageSending $evenement): ?bool
    {
        $message = $evenement->message;
        $retiree = false;

        foreach (['To' => $message->getTo(), 'Cc' => $message->getCc(), 'Bcc' => $message->getBcc()] as $entete => $adresses) {
            $gardees = array_values(array_filter($adresses, fn (Address $adresse) => ! $this->revue->estAdresseDeRevue($adresse->getAddress())));
            if (count($gardees) === count($adresses)) {
                continue;
            }
            // Plus aucun destinataire : le message ne part pas, même vers ses copies
            if ($entete === 'To' && $gardees === []) {
                return false;
            }
            $retiree = true;
            // Plus personne en copie : l'en-tête part, plutôt que de rester vide
            $gardees === []
                ? $message->getHeaders()->remove($entete)
                : $message->getHeaders()->get($entete)?->setBody($gardees);
        }

        // Elle seule en copie, sans destinataire principal : plus personne, rien ne part
        if ($retiree && $message->getTo() === [] && $message->getCc() === [] && $message->getBcc() === []) {
            return false;
        }

        return null;
    }
}
