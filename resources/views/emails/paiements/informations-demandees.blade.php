<x-mail::message>
# Compte de paiement de {{ $societe->nom }}

{{ $coupe ? 'Les paiements par carte, TWINT et les acomptes en ligne sont suspendus jusqu’à ce que ces informations soient fournies.' : 'Les paiements continuent d’être encaissés ; quelques informations sont à fournir pour continuer à recevoir les virements sans interruption.' }}

Elles se complètent en quelques minutes dans les réglages de l'application, onglet Paiements.

<x-mail::button :url="$lien">
Compléter les informations
</x-mail::button>

Ces e-mails se coupent dans les réglages de l'application, onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
