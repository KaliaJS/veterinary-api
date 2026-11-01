<x-mail::message>
# Rendez-vous de {{ $patient->nom }} demain

Bonjour,

nous attendons {{ $patient->nom }} demain, le {{ $jour }} à {{ $heure }}, chez {{ $clinique->nom }}.

@if ($clinique->tel)
Un empêchement ? Appelez la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
@if ($clinique->rue)
<br>{{ $clinique->rue }}, {{ $clinique->npa }} {{ $clinique->ville }}
@endif

<small>Vous ne souhaitez plus recevoir ces rappels ? [Ne plus recevoir les rappels]({{ $lienDesabonnement }})</small>
</x-mail::message>
