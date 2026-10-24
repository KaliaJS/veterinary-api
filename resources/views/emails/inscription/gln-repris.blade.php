<x-mail::message>
# Votre GLN a été saisi par un autre compte

Le GLN {{ $gln }} figurait sur votre compte Veterinary. Un autre compte vient de le saisir et l'a repris : seule une identité vérifiée réserve un GLN.

Si c'est bien le vôtre, saisissez-le de nouveau dans Réglages, Profil : il reviendra à votre compte, et l'autre compte sera prévenu à son tour. Faites ensuite vérifier votre identité au même endroit (une pièce d'identité et un selfie, pour le badge de vétérinaire vérifié) : une fois votre identité vérifiée, personne ne pourra plus le reprendre.

Si vous ne comprenez pas ce message, répondez-nous : nous regarderons.

<x-mail::button :url="$lien">
Ouvrir Veterinary
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
