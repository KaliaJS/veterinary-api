<x-mail::message>
# Rendez-vous de contrôle de {{ $patient->nom }}

Bonjour,

nous attendons {{ $patient->nom }} le {{ $jour }} à {{ $heure }} chez {{ $clinique->nom }} pour son contrôle.

@if ($clinique->tel)
Un empêchement ? Appelez la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
@if ($clinique->rue)
<br>{{ $clinique->rue }}, {{ $clinique->npa }} {{ $clinique->ville }}
@endif

<small>Vous ne souhaitez plus recevoir ces rappels ? [Ne plus recevoir les rappels]({{ $lienDesabonnement }})</small>
</x-mail::message>
