<x-mail::message>
# Un devis attend votre réponse

{{ $devis->clinique->nom }} vous a envoyé le devis {{ $devis->numero }}{{ $devis->patient?->nom ? ' pour '.$devis->patient->nom : '' }}. Vous pouvez l'accepter ou le refuser dans votre espace Veterinary{{ $jusquau ? ", jusqu'au ".$jusquau : '' }}.

<x-mail::button :url="$lien">
Voir le devis
</x-mail::button>

{{ $devis->clinique->nom }}
</x-mail::message>
