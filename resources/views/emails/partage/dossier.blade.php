<x-mail::message>
# {{ $clinique->nom }} vous partage un dossier

{{ $share->created_by_name ?: $clinique->nom }} vous partage le dossier de **{{ $patient->nom }}**. Les pièces sont sur la page ci-dessous, jusqu’au {{ $jusqu }}.

<x-mail::button :url="$url">
Lire le dossier
</x-mail::button>

<small>Rien de médical dans cet e-mail : le lien est personnel, ne le transférez pas. Chaque ouverture est notée au dossier de l’animal.</small>
</x-mail::message>
