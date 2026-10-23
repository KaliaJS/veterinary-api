<x-mail::message>
@php($decision = $demande->annulation_decision?->value)
@if ($decision === 'refusee')
# Rendez-vous maintenu

{{ $demande->clinique->nom }} ne peut pas annuler le rendez-vous de {{ $demande->patient_name }}{{ $quand ? ' du ' : '' }}@if ($quand)**{{ $quand }}**@endif : il est maintenu, et l'acompte de {{ $acompte }} sera déduit de la consultation.
@else
# Rendez-vous annulé

{{ $demande->clinique->nom }} accepte d'annuler le rendez-vous de {{ $demande->patient_name }}{{ $quand ? ' du ' : '' }}@if ($quand)**{{ $quand }}**@endif.

@if ($decision === 'complet')
Votre acompte de {{ $acompte }} vous est rendu en entier.
@elseif ($decision === 'hors_frais' && $demande->acompte_rembourse_le)
{{ $rendu }} vous sont rendus. Les frais du paiement, {{ $garde }}, ne sont pas remboursables.
@else
L'acompte de {{ $acompte }} reste acquis à la clinique.
@endif
@if ($demande->acompte_rembourse_le)

Le remboursement apparaît sur votre relevé d'ici 5 à 10 jours.
@endif
@endif

Une question ? Écrivez à la clinique en répondant à cet e-mail{{ $demande->clinique->tel ? ', ou appelez le '.$demande->clinique->tel : '' }}.

{{ $demande->clinique->nom }}
</x-mail::message>
