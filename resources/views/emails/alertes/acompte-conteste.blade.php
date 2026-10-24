<x-mail::message>
# Acompte contesté

L'acompte de {{ $montant }} payé en ligne pour {{ $demande->patient_name }} ({{ $demande->clinique->nom }}) est contesté par le titulaire de la carte auprès de sa banque. Stripe a retenu le montant et les frais du litige sur le compte de la société.

@if ($echeance)
Répondez avant le **{{ $echeance }}** : dans l'application, Réglages, Paiements, ouvrez le compte puis le paiement, et joignez vos preuves (demande de rendez-vous, conditions d'annulation).
@else
Répondez au plus vite : dans l'application, Réglages, Paiements, ouvrez le compte puis le paiement, et joignez vos preuves (demande de rendez-vous, conditions d'annulation).
@endif

<x-mail::button :url="$lien">
Ouvrir la caisse
</x-mail::button>

Ces e-mails se coupent dans les réglages de l'application, onglet Notifications.

{{ config('app.name') }}
</x-mail::message>
