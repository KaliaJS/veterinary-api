<x-mail::message>
# {{ $patient->nom }} n’a pas pu venir aujourd’hui

Bonjour,

{{ $patient->nom }} était attendu aujourd’hui à {{ $heure }} chez {{ $clinique->nom }}. Pour reprendre rendez-vous :

<x-mail::button :url="$lienReprise">
Reprendre rendez-vous
</x-mail::button>

@if ($clinique->tel)
Vous pouvez aussi appeler la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
@if ($clinique->rue)
<br>{{ $clinique->rue }}, {{ $clinique->npa }} {{ $clinique->ville }}
@endif

<small>Vous ne souhaitez plus recevoir ces messages ? [Ne plus les recevoir]({{ $lienDesabonnement }})</small>
</x-mail::message>
