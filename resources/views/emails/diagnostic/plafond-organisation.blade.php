<x-mail::message>
# Suggestions diagnostiques

@php($chf = fn (float $v) => number_format($v, 2, '.', "'").' CHF')
@if ($seuil >= 100)
{{ $organisation }} a utilisé {{ $chf($montant) }} de suggestions diagnostiques sur la période d'abonnement en cours et atteint son plafond de {{ $chf($plafond) }}. Aucune nouvelle suggestion ne peut être demandée avant le renouvellement de l'abonnement.
@else
{{ $organisation }} a utilisé {{ $chf($montant) }} de suggestions diagnostiques sur la période d'abonnement en cours, soit {{ $seuil }} % de son plafond de {{ $chf($plafond) }}.
@endif

Le plafond se règle dans les réglages de l'application, onglet Diagnostic IA. Ces e-mails se coupent dans l'onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
