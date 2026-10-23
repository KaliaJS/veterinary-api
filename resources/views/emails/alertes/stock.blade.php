<x-mail::message>
# Stock de {{ $clinique->nom }}

@foreach ($alertes as $alerte)
**{{ $alerte['titre'] }}**<br>
{{ $alerte['detail'] }}

@endforeach
<x-mail::button :url="$lien">
Ouvrir les stocks
</x-mail::button>

Ces e-mails se coupent dans les réglages de l'application, onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
