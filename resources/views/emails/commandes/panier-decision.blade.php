<x-mail::message>
# Panier automatique {{ $basket->fournisseur }}

{{ $why }}

<x-mail::button :url="$lien">
Ouvrir les commandes
</x-mail::button>

Ces e-mails se coupent dans les réglages de l'application, onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
