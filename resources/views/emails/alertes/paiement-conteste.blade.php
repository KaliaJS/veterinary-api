<x-mail::message>
# Paiement contesté

Le paiement de CHF {{ number_format((float) $paiement->montant, 2, '.', '’') }} de la facture {{ $paiement->facture->numero }} ({{ $paiement->clinique->nom }}) est contesté par le titulaire de la carte auprès de sa banque. Stripe a retenu le montant et les frais du litige sur le compte de la société.

@if ($echeance)
Répondez avant le **{{ $echeance }}** : dans l'application, Réglages, Paiements, ouvrez le compte puis le paiement, et joignez vos preuves (facture, dossier de la consultation).
@else
Répondez au plus vite : dans l'application, Réglages, Paiements, ouvrez le compte puis le paiement, et joignez vos preuves (facture, dossier de la consultation).
@endif

<x-mail::button :url="$lien">
Ouvrir la caisse
</x-mail::button>

Ces e-mails se coupent dans les réglages de l'application, onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
