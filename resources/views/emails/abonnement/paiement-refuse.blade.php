<x-mail::message>
# Paiement de votre abonnement

Le renouvellement de votre abonnement (facture {{ $facture->numero }}, {{ number_format((float) $facture->montant, 2, '.', "'") }} CHF) n'a pas pu être prélevé : {{ $raison }}

@if ($prochaineTentative)
Nous réessaierons le {{ $prochaineTentative->timezone('Europe/Zurich')->format('d.m.Y') }}, puis chaque jour jusqu'au {{ $fermetureLe?->format('d.m.Y') }}. D'ici là, votre accès reste ouvert. Sans paiement à cette date, {{ $pourClinique ? "l'accès à l'application sera fermé" : 'votre espace repassera en Gratuit' }}.

Vous pouvez régler la facture ou changer de carte dans l'application, {{ $pourClinique ? 'Réglages, Facturation' : 'Réglages, Abonnement' }}.
@elseif ($pourClinique)
Après plusieurs tentatives, l'abonnement a pris fin et l'accès à l'application est fermé. Choisissez une formule dans l'application pour le rouvrir : une nouvelle période commencera le jour du paiement.
@if ($factureTranscription)

Les minutes de transcription déjà utilisées restent dues : facture {{ $factureTranscription->numero }}, {{ number_format((float) $factureTranscription->montant, 2, '.', "'") }} CHF, à régler avant de reprendre une formule.
@endif
@else
Après plusieurs tentatives, l'abonnement Plus a pris fin : votre espace repasse en Gratuit, vos animaux et leurs données restent là. Vous pouvez reprendre Plus à tout moment, dans Réglages, Abonnement.
@endif

{{ config('app.name') }}
</x-mail::message>
