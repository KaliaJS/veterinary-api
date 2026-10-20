<x-mail::message>
# Votre clinique est vérifiée

Bonne nouvelle : {{ $organisation->nom }} est vérifiée. Il ne reste qu'à choisir votre formule pour ouvrir l'application à votre équipe.

<x-mail::button :url="$lien">
Choisir ma formule
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
