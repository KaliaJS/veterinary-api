<x-mail::message>
# {{ $clinique->nom }} vous a écrit

{{ $message->texte }}

<x-mail::button :url="$lien">
Lire et répondre
</x-mail::button>

<small>Vous recevez cet e-mail parce que « Réponses de la clinique » est activé dans vos réglages.</small>
</x-mail::message>
