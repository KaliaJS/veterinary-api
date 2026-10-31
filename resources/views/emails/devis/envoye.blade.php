<x-mail::message>
# Un devis pour {{ $devis->patient?->nom ?? 'votre animal' }}

{{ $devis->clinique->nom }} vous a envoyé le devis {{ $devis->numero }}{{ $devis->motif ? ' : « '.str_replace(['[', ']', '(', ')', '*', '_', '`'], ' ', $devis->motif).' »' : '' }}. Lisez-le dans votre espace Veterinary, puis acceptez-le ou refusez-le{{ $jusquau ? ", jusqu'au ".$jusquau : '' }}.

<x-mail::button :url="$lien">
Lire le devis
</x-mail::button>

@if ($devis->clinique->email)
Une question avant de vous décider ? Répondez simplement à cet e-mail.
@endif

{{ $devis->clinique->nom }}
</x-mail::message>
