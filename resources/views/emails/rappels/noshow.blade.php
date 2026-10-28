<x-mail::message>
# {{ $patient->nom }} n’a pas pu venir au rendez-vous

Bonjour,

le rendez-vous de {{ $patient->nom }} chez {{ $clinique->nom }} était prévu le {{ $jour }} à {{ $heure }}.

@if ($lienReprise)
Pour en reprendre un :

<x-mail::button :url="$lienReprise">
Reprendre rendez-vous
</x-mail::button>

@if ($clinique->tel)
Vous pouvez aussi appeler la clinique au {{ $clinique->tel }}.
@endif
@elseif ($clinique->tel)
Pour en reprendre un, appelez la clinique au {{ $clinique->tel }}.
@else
Pour en reprendre un, contactez la clinique.
@endif

{{ $clinique->nom }}
@if ($clinique->rue)
<br>{{ $clinique->rue }}, {{ $clinique->npa }} {{ $clinique->ville }}
@endif

<small>Vous ne souhaitez plus recevoir ces messages ? [Ne plus les recevoir]({{ $lienDesabonnement }})</small>
</x-mail::message>
