<?php

namespace App\Listeners;

use App\Auth\CompteRevueApple;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Aucun e-mail ne part vers l'adresse de revue d'Apple (27.10.2026) :
 * personne ne lit cette boîte, et ses e-mails (accusé d'une demande de
 * rendez-vous, notifications…) ne doivent partir nulle part. Coupé au moment
 * de l'envoi, pour tous les e-mails à la fois : un message qui lui est
 * adressé n'est pas envoyé ; en copie, elle est retirée et le reste part.
 */
class PasDeMailAuCompteDeRevue
{
    public function __construct(private CompteRevueApple $revue) {}

    public function handle(MessageSending $evenement): ?bool
    {
        $message = $evenement->message;
        $estDeRevue = fn (Address $adresse): bool => $this->revue->estAdresseDeRevue($adresse->getAddress());

        if (array_filter($message->getTo(), $estDeRevue) !== []) {
            return false;
        }

        foreach (['Cc' => $message->getCc(), 'Bcc' => $message->getBcc()] as $entete => $adresses) {
            $gardees = array_values(array_filter($adresses, fn (Address $adresse) => ! $estDeRevue($adresse)));
            if (count($gardees) === count($adresses)) {
                continue;
            }
            // Plus personne en copie : l'en-tête part, plutôt que de rester vide
            $gardees === []
                ? $message->getHeaders()->remove($entete)
                : $message->getHeaders()->get($entete)?->setBody($gardees);
        }

        return null;
    }
}
