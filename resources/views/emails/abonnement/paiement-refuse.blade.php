<x-mail::message>
# Paiement de votre abonnement

Le renouvellement de votre abonnement (facture {{ $facture->numero }}, {{ number_format((float) $facture->montant, 2, '.', "'") }} CHF) n'a pas pu être prélevé : {{ $raison }}

@if ($prochaineTentative)
Nous réessaierons le {{ $prochaineTentative->timezone('Europe/Zurich')->format('d.m.Y') }}. D'ici là, votre accès reste ouvert. Vous pouvez régler la facture ou changer de carte dans l'application, {{ $pourClinique ? 'Réglages, Facturation' : 'Réglages, Abonnement' }}.
@else
Après plusieurs tentatives, l'abonnement a pris fin. Vous pouvez régler la facture et en reprendre un à tout moment dans l'application, {{ $pourClinique ? 'Réglages, Facturation' : 'Réglages, Abonnement' }}.
@endif

{{ config('app.name') }}
</x-mail::message>
