<x-mail::message>
# Transcription des consultations

@if ($seuil >= 100)
La plateforme a transcrit {{ $minutes }} minutes ce mois-ci, pour une dépense Deepgram estimée à {{ number_format($depense, 2, '.', "'") }} $ : le budget de {{ number_format($budget, 2, '.', "'") }} $ est atteint. Aucun nouvel enregistrement ne peut commencer avant le mois prochain ; les parties déjà enregistrées sont transcrites.
@else
La plateforme a transcrit {{ $minutes }} minutes ce mois-ci, pour une dépense Deepgram estimée à {{ number_format($depense, 2, '.', "'") }} $, soit {{ $seuil }} % du budget de {{ number_format($budget, 2, '.', "'") }} $.
@endif

Le budget se règle par `TRANSCRIPTION_BUDGET_DEEPGRAM_USD` dans l'environnement de l'API (vide : illimité), le coût d'une minute par `TRANSCRIPTION_COUT_MINUTE_DEEPGRAM_USD`.

{{ config('app.name') }}
</x-mail::message>
