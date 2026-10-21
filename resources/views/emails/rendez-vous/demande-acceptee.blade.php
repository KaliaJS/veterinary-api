<x-mail::message>
# Rendez-vous confirmé

{{ $demande->clinique->nom }} vous attend avec {{ $demande->patient_name }} le **{{ $quand }}**@if ($demande->teleconsultation), en téléconsultation vidéo@endif.

@if ($demande->clinique->rue)
{{ $demande->clinique->rue }}, {{ $demande->clinique->npa }} {{ $demande->clinique->ville }}
@endif

Un empêchement ? Prévenez la clinique@if ($demande->clinique->tel) au {{ $demande->clinique->tel }}@endif@if ($demande->user_id), ou annulez depuis votre espace Veterinary@endif.

{{ $demande->clinique->nom }}
</x-mail::message>
