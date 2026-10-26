<x-mail::message>
# Stripe refuse la clé de la plateforme

@if ($statut === 0)
Aucune clé de l'API Stripe n'est configurée : un prélèvement de la plateforme n'a pas pu partir.
@else
Stripe a répondu {{ $statut }} à un prélèvement de la plateforme : la clé secrète de l'API est expirée, retirée ou n'a pas le droit de prélever.
@endif

Tant qu'elle est refusée, rien n'est prélevé : ni abonnements, ni packs, ni objets de planète, ni badges. Les abonnés ne reçoivent aucun e-mail de refus et gardent leur accès ; les renouvellements sont retentés chaque heure. Pour les organisations, l'accès se ferme quand même au bout du délai de grâce de sept jours après l'échéance.

Remplacez la clé (`STRIPE_SECRET`) dans les variables de l'environnement. Cet e-mail repart chaque jour tant que le refus dure.

{{ config('app.name') }}
</x-mail::message>
