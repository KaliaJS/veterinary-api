<x-mail::message>
# Transcription des consultations

@php($chf = fn (float $v) => number_format($v, 2, '.', "'").' CHF')
@if ($seuil >= 100)
{{ $organisation }} a utilisé {{ $chf($montant) }} de transcription sur la période d'abonnement en cours et atteint son plafond de {{ $chf($plafond) }}. Aucun nouvel enregistrement ne peut commencer avant le renouvellement de l'abonnement ; les consultations en cours se terminent normalement.
@else
{{ $organisation }} a utilisé {{ $chf($montant) }} de transcription sur la période d'abonnement en cours, soit {{ $seuil }} % de son plafond de {{ $chf($plafond) }}.
@endif

Le plafond se règle dans les réglages de l'application, onglet Transcription. Ces e-mails se coupent dans l'onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
