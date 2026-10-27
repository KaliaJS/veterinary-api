<x-mail::message>
# Veterinary est sur invitation

Un code de connexion à Veterinary a été demandé avec cette adresse. Pour l'instant, Veterinary s'ouvre sur invitation seulement : aucun compte n'a été créé.

Votre clinique utilise Veterinary ? Demandez-lui de vous inviter dans son équipe ou de noter votre adresse sur votre fiche client.@if ($contact) Vous pouvez aussi nous écrire à {{ $contact }}.@endif


Vous n'êtes pas à l'origine de cette demande ? Ignorez cet e-mail.

{{ config('app.name') }}
</x-mail::message>
