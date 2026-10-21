<x-mail::message>
# Votre demande de rendez-vous

{{ $demande->clinique->nom }} ne peut pas donner suite à votre demande pour {{ $demande->patient_name }}.

@if ($motif)
{{ $motif }}.
@endif

@if ($demande->message)
{{ $demande->message }}
@endif

@if ($demande->clinique->tel)
Pour en parler, appelez la clinique au {{ $demande->clinique->tel }}.
@endif

{{ $demande->clinique->nom }}
</x-mail::message>
