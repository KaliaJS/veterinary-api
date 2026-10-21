<x-mail::message>
# Vétérinaire à vérifier

{{ $nom }} ({{ $email }}) demande la vérification de son profil.

GLN : {{ $gln }}, {{ $auRegistre ? 'trouvé dans MedReg à son nom' : 'pas trouvé dans MedReg à son nom' }}.

<x-mail::button :url="$lien">
Ouvrir la plateforme
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
