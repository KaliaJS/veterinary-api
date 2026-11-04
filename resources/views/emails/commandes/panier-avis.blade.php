<x-mail::message>
# Le panier {{ $basket->fournisseur }} part {{ $quand }}

@if ($pourquoi)
{{ $pourquoi }}
@endif
Total {{ $total }}@if ($minimum) (minimum {{ $minimum }})@endif.
@if ($sousMinimum)
{{ $sousMinimum }}
@endif

@foreach ($lignes as $ligne)
- {{ $ligne->produit?->nom }} × {{ $ligne->quantite }}
@endforeach

Vous pouvez le modifier ou le mettre en pause d'ici là.

<x-mail::button :url="$lien">
Ouvrir les commandes
</x-mail::button>

Ces e-mails se coupent dans les réglages de l'application, onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
