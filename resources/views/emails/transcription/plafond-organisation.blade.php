<x-mail::message>
# Transcription des consultations

@php($chf = fn (float $v) => number_format($v, 2, '.', "'").' CHF')
@if ($seuil >= 100)
{{ $organisation }} a utilisé {{ $chf($montant) }} HT de transcription ce mois-ci et atteint son plafond de {{ $chf($plafond) }}. Aucun nouvel enregistrement ne peut commencer avant le mois prochain ; les consultations en cours se terminent normalement.
@else
{{ $organisation }} a utilisé {{ $chf($montant) }} HT de transcription ce mois-ci, soit {{ $seuil }} % de son plafond de {{ $chf($plafond) }}.
@endif

Le plafond se règle dans les réglages de l'application, onglet Transcription.

{{ config('app.name') }}
</x-mail::message>
