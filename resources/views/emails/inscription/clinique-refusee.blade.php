<x-mail::message>
# Inscription de votre clinique

Nous n'avons pas pu vérifier l'inscription de {{ $organisation->nom }} : elle est refusée.

@if ($organisation->verification_note)
{{ $organisation->verification_note }}
@endif

Si c'est une erreur, répondez simplement à cet e-mail avec de quoi vérifier votre inscription (GLN, autorisation de pratiquer).

{{ config('app.name') }}
</x-mail::message>
