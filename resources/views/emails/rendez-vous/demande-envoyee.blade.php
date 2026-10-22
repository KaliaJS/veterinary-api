<x-mail::message>
# Demande envoyée

Votre demande de rendez-vous pour {{ $demande->patient_name }} est arrivée chez {{ $demande->clinique->nom }}.

@if ($demande->acompte_paye_le)
Votre acompte de CHF {{ number_format($demande->acompte_centimes / 100, 2, '.', "'") }} est bien reçu, mais l'heure demandée vient d'être prise. La clinique vous propose une autre heure par e-mail ; si elle ne peut pas vous recevoir, l'acompte vous est rendu.
@elseif ($quand)
Vous avez demandé le **{{ $quand }}**. La clinique vous répond par e-mail : elle confirme cette heure, vous en propose d'autres ou vous explique pourquoi elle ne peut pas.
@else
La clinique vous répond par e-mail : elle vous donne une heure, vous en propose plusieurs ou vous explique pourquoi elle ne peut pas.
@endif

<x-mail::button :url="$lien">
Suivre ma demande
</x-mail::button>

{{ $demande->clinique->nom }}@if ($demande->clinique->tel), {{ $demande->clinique->tel }}@endif

</x-mail::message>
