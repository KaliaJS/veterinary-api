<x-mail::message>
# Votre carte expire bientôt

La carte enregistrée pour votre abonnement ({{ $abonne->moyen_paiement }}) expire fin {{ $abonne->carte_expire_le->format('m.Y') }}, avant le prochain prélèvement du {{ $echeance->format('d.m.Y') }}.

Ajoutez votre nouvelle carte dans l'application, {{ $pourClinique ? 'Réglages, Facturation' : 'Réglages, Abonnement' }}. Si votre banque vous a déjà envoyé une nouvelle carte, elle est peut-être mise à jour d'elle-même : vérifiez la date affichée.

{{ config('app.name') }}
</x-mail::message>
