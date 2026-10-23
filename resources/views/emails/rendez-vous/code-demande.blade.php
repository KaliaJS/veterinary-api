<x-mail::message>
# Votre code

Pour envoyer votre demande de rendez-vous à {{ $clinique->nom }}, saisissez ce code :

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

Il reste valable {{ $minutes }} minutes. Si vous n'avez rien demandé, ignorez simplement cet e-mail : rien ne sera envoyé.

</x-mail::message>
