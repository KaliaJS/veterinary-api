<x-mail::message>
# Votre commande est prête

Votre commande {{ $commande->numero }} vous attend chez {{ $commande->clinique->nom }}.

@if ($commande->clinique->rue)
{{ $commande->clinique->rue }}, {{ $commande->clinique->npa }} {{ $commande->clinique->ville }}
@endif

@if ($commande->clinique->tel)
Une question ? Appelez le {{ $commande->clinique->tel }}.
@endif

{{ $commande->clinique->nom }}
</x-mail::message>
