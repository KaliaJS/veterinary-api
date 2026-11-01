<x-mail::message>
@if ($estEnRetard)
# Le vaccin de {{ $patient->nom }} est en retard

Bonjour,

le rappel du vaccin {{ $vaccination->nom }} de {{ $patient->nom }} était prévu pour le {{ $echeance }}. Prenez rendez-vous chez {{ $clinique->nom }} pour que sa protection reprenne.
@else
# Le vaccin de {{ $patient->nom }} arrive à échéance

Bonjour,

le rappel du vaccin {{ $vaccination->nom }} de {{ $patient->nom }} @if ($echeance) est prévu pour le {{ $echeance }}. @else est à prévoir. @endif Prenez rendez-vous chez {{ $clinique->nom }} pour que sa protection continue.
@endif

<x-mail::button :url="$lienRendezVous">
Prendre rendez-vous
</x-mail::button>

@if ($clinique->tel)
Vous pouvez aussi appeler la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
@if ($clinique->rue)
<br>{{ $clinique->rue }}, {{ $clinique->npa }} {{ $clinique->ville }}
@endif

<small>Vous ne souhaitez plus recevoir ces rappels ? [Ne plus recevoir les rappels]({{ $lienDesabonnement }})</small>
</x-mail::message>
