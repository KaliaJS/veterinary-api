<x-mail::layout>
{{-- Marque --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Corps --}}
{!! $slot !!}

{{-- Note de bas de carte --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Pied --}}
<x-slot:footer>
<x-mail::footer>
Veterinary · Cliniques vétérinaires en Suisse
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
