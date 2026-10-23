<x-mail::message>
# Rendez-vous confirmé

{{ $demande->clinique->nom }} vous attend avec {{ $demande->patient_name }} le **{{ $quand }}**{{ $demande->teleconsultation ? ', en téléconsultation vidéo' : '' }}.

@if ($demande->clinique->rue)
{{ $demande->clinique->rue }}, {{ $demande->clinique->npa }} {{ $demande->clinique->ville }}
@endif

@if ($demande->acompte_paye_le)
Votre acompte de CHF {{ number_format($demande->acompte_centimes / 100, 2, '.', "'") }} est payé : il sera déduit de la consultation. Il n'est pas remboursé si vous ne venez pas.

Un empêchement ? Demandez l'annulation à la clinique{{ $demande->user_id ? ' depuis votre espace Veterinary' : '' }}{{ $demande->clinique->tel ? ', ou appelez le '.$demande->clinique->tel : '' }} : elle décide de rendre l'acompte ou non.
@else
Un empêchement ? Prévenez la clinique{{ $demande->clinique->tel ? ' au '.$demande->clinique->tel : '' }}{{ $demande->user_id ? ', ou annulez depuis votre espace Veterinary' : '' }}.
@endif

{{ $demande->clinique->nom }}
</x-mail::message>
