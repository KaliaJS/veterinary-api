<x-mail::message>
@if ($estTele)
# Téléconsultation de {{ $patient->nom }} {{ $quand }}

Bonjour,

votre téléconsultation pour {{ $patient->nom }} avec {{ $clinique->nom }} a lieu {{ $quand }}, le {{ $jour }} à {{ $heure }}. Le lien de l’appel vidéo vous arrive par e-mail au moment du rendez-vous.
@else
# Rendez-vous de {{ $patient->nom }} {{ $quand }}

Bonjour,

nous attendons {{ $patient->nom }} {{ $quand }}, le {{ $jour }} à {{ $heure }}, chez {{ $clinique->nom }}.
@endif

@if ($clinique->tel)
Un empêchement ? Appelez la clinique au {{ $clinique->tel }}.
@endif

{{ $clinique->nom }}
@if ($clinique->rue && ! $estTele)
<br>{{ $clinique->rue }}, {{ $clinique->npa }} {{ $clinique->ville }}
@endif

<small>Vous ne souhaitez plus recevoir ces rappels ? [Ne plus recevoir les rappels]({{ $lienDesabonnement }})</small>
</x-mail::message>
