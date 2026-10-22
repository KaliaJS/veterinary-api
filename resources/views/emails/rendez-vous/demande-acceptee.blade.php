<x-mail::message>
# Rendez-vous confirmé

{{ $demande->clinique->nom }} vous attend avec {{ $demande->patient_name }} le **{{ $quand }}**@if ($demande->teleconsultation), en téléconsultation vidéo@endif.

@if ($demande->clinique->rue)
{{ $demande->clinique->rue }}, {{ $demande->clinique->npa }} {{ $demande->clinique->ville }}
@endif

@if ($demande->acompte_paye_le)
Votre acompte de CHF {{ number_format($demande->acompte_centimes / 100, 2, '.', "'") }} est payé : il sera déduit de la consultation. Il vous est remboursé si vous annulez au moins {{ $demande->clinique->acompte_delai_heures }} heures avant le rendez-vous.

@endif
Un empêchement ? Prévenez la clinique@if ($demande->clinique->tel) au {{ $demande->clinique->tel }}@endif@if ($demande->user_id), ou annulez depuis votre espace Veterinary@endif.

{{ $demande->clinique->nom }}
</x-mail::message>
