<x-mail::message>
# Rejoignez {{ $organisation }}

{{ $invitant ? $invitant.' vous invite' : 'Vous êtes invité' }} à rejoindre l'équipe de {{ $organisation }} sur {{ config('app.name') }}{{ $poste ? ', au poste « '.$poste.' »' : '' }}.

Connectez-vous avec cette adresse e-mail : un code vous sera envoyé, sans mot de passe. Si vous n'avez pas encore de compte, il se crée à ce moment-là.

<x-mail::button :url="$lien">
Voir l'invitation
</x-mail::button>

Le lien est valable {{ $jours }} jours. Si vous ne connaissez pas cette organisation, ignorez simplement cet e-mail.

{{ config('app.name') }}
</x-mail::message>
