<x-mail::message>
# {{ $clinique->nom }} vous réfère {{ $patient->nom }}

{{ $invitation->sent_by_name ?: $clinique->nom }} vous adresse **{{ $patient->nom }}** avec une lettre de référence et les pièces de son dossier. Ouvrez vos messages dans Veterinary pour accepter ou refuser.

<x-mail::button :url="$url">
Ouvrir mes messages
</x-mail::button>

<small>Rien de médical dans cet e-mail : la lettre et les pièces se lisent dans Veterinary, une fois l’invitation acceptée.</small>
</x-mail::message>
