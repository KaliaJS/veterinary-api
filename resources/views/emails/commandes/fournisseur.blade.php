<x-mail::message>
# Commande {{ $order->numero }}

Bonjour,

Veuillez trouver ci-joint la commande {{ $order->numero }} de {{ $clinique->nom }}, en PDF. Merci de confirmer la disponibilité et le délai en répondant à cet e-mail.

{{ $clinique->nom }}
</x-mail::message>
