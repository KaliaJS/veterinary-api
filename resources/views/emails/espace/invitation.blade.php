<x-mail::message>
# Votre espace Veterinary

{{ $clinicName }} a créé votre fiche et vous invite dans votre espace Veterinary. Vous y retrouverez vos animaux, vos rendez-vous et vos documents, et vous y choisirez vos rappels : par e-mail, par SMS, ou pas du tout.

Ouvrez le lien ci-dessous, puis connectez-vous avec cette adresse e-mail : vous recevrez un code de connexion. Aucun mot de passe à retenir. Le lien est valable 30 jours.

<x-mail::button :url="$link">
Ouvrir mon espace
</x-mail::button>

Vous ne connaissez pas cette clinique ? Ignorez cet e-mail : rien ne se passera sans vous.

{{ config('app.name') }}
</x-mail::message>
