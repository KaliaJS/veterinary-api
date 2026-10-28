<x-mail::message>
# Votre appel vidéo avec {{ $clinique->nom }}

Bonjour,

@if ($patient)
{{ $clinique->nom }} vous attend en vidéo pour {{ $patient->nom }}.
@else
{{ $clinique->nom }} vous attend en vidéo.
@endif
Ouvrez le lien depuis votre téléphone ou votre ordinateur, puis autorisez la caméra et le micro.

<x-mail::button :url="$lien">
Rejoindre l'appel
</x-mail::button>

Ce lien est personnel : ne le transmettez pas. Il ne sert plus une fois l'appel terminé.

@if ($clinique->tel)
Un souci pour vous connecter ? Appelez la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
</x-mail::message>
