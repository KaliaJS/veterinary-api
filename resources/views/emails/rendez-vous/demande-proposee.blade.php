<x-mail::message>
# D'autres horaires pour {{ $demande->patient_name }}

{{ $demande->clinique->nom }} ne peut pas vous recevoir à l'heure demandée et vous propose :

@foreach ($horaires as $horaire)
- {{ $horaire }}
@endforeach

@if ($demande->message)
{{ $demande->message }}
@endif

Choisissez celui qui vous convient : le rendez-vous est pris aussitôt.

<x-mail::button :url="$lien">
Choisir mon horaire
</x-mail::button>

{{ $demande->clinique->nom }}@if ($demande->clinique->tel), {{ $demande->clinique->tel }}@endif

</x-mail::message>
