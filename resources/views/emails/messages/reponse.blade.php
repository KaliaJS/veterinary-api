<x-mail::message>
# {{ $clinique->nom }} vous a écrit

{{ $message->texte }}

<x-mail::button :url="$lien">
Lire et répondre
</x-mail::button>

@if ($estPremier)
Vous répondez depuis votre espace client Veterinary : connectez-vous avec cette adresse e-mail, un code vous est envoyé, sans mot de passe.
@endif

<small>Vous recevez cet e-mail parce que « Réponses de la clinique » est activé dans vos réglages.</small>
</x-mail::message>
