<x-mail::message>
# Cloudflare refuse le jeton

Cloudflare a répondu {{ $statut }} à la vérification des domaines des sites des cliniques : le jeton de l'API est expiré ou retiré.

Tant qu'il est refusé, aucun domaine n'est plus vérifié. Les domaines actifs le restent, pour ne couper aucun site par erreur, mais un domaine qui ne pointerait plus chez nous ne serait pas vu.

Remplacez le jeton (`CLOUDFLARE_API_TOKEN`) dans les variables de l'environnement. Cet e-mail repart chaque jour tant que le refus dure.

{{ config('app.name') }}
</x-mail::message>
