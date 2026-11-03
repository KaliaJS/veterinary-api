<x-mail::message>
# Import du catalogue {{ ucfirst($import->source) }} : {{ $import->statut === 'echec' ? 'en échec' : 'avec un avertissement' }}

{{ $import->statut === 'echec' ? "L'import n'a rien modifié." : "L'import a réussi ; un point est à regarder." }}

{{ $import->message }}

Journal : import n° {{ $import->id }}, le {{ $import->created_at?->timezone('Europe/Zurich')->format('d.m.Y à H:i') }}. Une seule alerte est envoyée par jour.

{{ config('app.name') }}
</x-mail::message>
