<x-mail::message>
# Stripe refuse la clé de la plateforme

@if ($statut === 0)
Aucune clé de l'API Stripe n'est configurée : un abonnement n'a pas pu être prélevé.
@else
Stripe a répondu {{ $statut }} en prélevant un abonnement : la clé secrète de l'API est expirée, retirée ou n'a pas le droit de prélever.
@endif

Tant qu'elle est refusée, aucun abonnement n'est prélevé. Les abonnés ne reçoivent aucun e-mail de refus et gardent leur accès ; le prélèvement est retenté chaque heure. Au bout du délai de grâce de sept jours après l'échéance, leur accès se ferme quand même.

Remplacez la clé (`STRIPE_SECRET`) dans les variables de l'environnement. Cet e-mail repart chaque jour tant que le refus dure.

{{ config('app.name') }}
</x-mail::message>
