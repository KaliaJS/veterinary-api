<x-mail::message>
# Consentement à signer pour {{ $patient->nom }}

Bonjour,

{{ $clinique->nom }} vous demande votre consentement pour l’intervention prévue pour {{ $patient->nom }} : {{ $procedure }}.
Ouvrez le lien, lisez le texte, puis signez du doigt sur votre téléphone, ou à la souris.

<x-mail::button :url="$lien">
Lire et signer
</x-mail::button>

Ce lien est personnel : ne le transmettez pas. Il reste valable 24 heures.

@if ($clinique->tel)
Une question avant de signer ? Appelez la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
</x-mail::message>
