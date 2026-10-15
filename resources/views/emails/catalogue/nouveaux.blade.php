<x-mail::message>
# Nouveaux vaccins dans le catalogue Swissmedic

L'import mensuel a trouvé {{ count($produits) }} nouveau{{ count($produits) > 1 ? 'x' : '' }} produit{{ count($produits) > 1 ? 's' : '' }} immunologique{{ count($produits) > 1 ? 's' : '' }} autorisé{{ count($produits) > 1 ? 's' : '' }} :

@foreach ($produits as $produit)
- {{ $produit }}
@endforeach

Les valences sont déduites du code ATCvet ; le schéma d'emploi (âge, primo-vaccination, rappel) reste à compléter dans les données curées (`DonneesVaccins`), d'après l'information professionnelle.

{{ config('app.name') }}
</x-mail::message>
