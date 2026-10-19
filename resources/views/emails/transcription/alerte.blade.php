<x-mail::message>
# Transcription des consultations

@if ($seuil >= 100)
La plateforme a transcrit {{ $minutes }} minutes ce mois-ci et atteint son plafond de {{ $plafond }} minutes. Aucun nouvel enregistrement ne peut commencer avant le mois prochain ; les parties déjà enregistrées sont transcrites.
@else
La plateforme a transcrit {{ $minutes }} minutes ce mois-ci, soit {{ $seuil }} % de son plafond de {{ $plafond }} minutes.
@endif

Le plafond se règle par `TRANSCRIPTION_PLAFOND_GLOBAL_MINUTES` dans l'environnement de l'API.

{{ config('app.name') }}
</x-mail::message>
