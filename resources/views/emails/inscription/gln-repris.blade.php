<x-mail::message>
# Votre GLN a été saisi par un autre compte

Le GLN {{ $gln }} figurait sur votre compte Veterinary. Un autre compte vient de le saisir et l'a repris : seule une identité vérifiée réserve un GLN.

Si c'est bien le vôtre, faites vérifier votre identité dans Réglages, Profil : une pièce d'identité et un selfie suffisent. Le GLN reviendra alors à votre compte, et personne ne pourra plus le reprendre.

Si vous ne comprenez pas ce message, répondez-nous : nous regarderons.

<x-mail::button :url="$lien">
Ouvrir Veterinary
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
