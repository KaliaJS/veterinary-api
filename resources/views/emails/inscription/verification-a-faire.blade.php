<x-mail::message>
# Clinique à vérifier

**{{ $resultat->explication() }}**

<x-mail::table>
| | |
| :-- | :-- |
| Vétérinaire | {{ $user?->name }} |
| E-mail | {{ $user?->email }} |
| GLN | {{ $organisation->verification_gln }} |
| Société | {{ $societe?->nom }}{{ $societe?->ide ? ', '.$societe->ide : '' }} |
| Clinique | {{ $clinique?->nom }} |
| Adresse | {{ $clinique?->rue }}, {{ $clinique?->npa }} {{ $clinique?->ville }} |
@if ($clinique?->tel)
| Téléphone | {{ $clinique->tel }} |
@endif
@if ($clinique?->email)
| E-mail de la clinique | {{ $clinique->email }} |
@endif
</x-mail::table>

@if ($resultat->fiche)
Dans MedReg, ce GLN est celui de **{{ $resultat->fiche->prenom }} {{ $resultat->fiche->nom }}**, vétérinaire{{ $resultat->fiche->diplome ? ' ('.$resultat->fiche->diplome.')' : '' }}{{ $resultat->fiche->cantons ? ', autorisé à pratiquer : '.implode(', ', $resultat->fiche->cantons) : '' }}.
@endif

La clinique reste fermée jusqu'à votre décision.

<x-mail::button :url="$lienValider" color="success">
Valider
</x-mail::button>

<x-mail::button :url="$lienRefuser" color="error">
Refuser
</x-mail::button>

Chaque lien ouvre une page de confirmation. Ils restent valables 30 jours.

{{ config('app.name') }}
</x-mail::message>
