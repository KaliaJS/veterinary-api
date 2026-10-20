<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $geste === 'valider' ? 'Valider' : 'Refuser' }} {{ $organisation->nom }}</title>
    <style>
        body { margin: 0; font: 15px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f4f6; color: #1d1d22; }
        main { max-width: 520px; margin: 48px auto; padding: 28px; background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        h1 { font-size: 20px; margin: 0 0 18px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0 0 22px; }
        dt { color: #6b6b75; }
        dd { margin: 0; }
        textarea { width: 100%; box-sizing: border-box; min-height: 90px; padding: 10px; border: 1px solid #d6d6dc; border-radius: 8px; font: inherit; margin-bottom: 14px; }
        button { font: inherit; font-weight: 600; padding: 10px 18px; border: 0; border-radius: 8px; color: #fff; cursor: pointer; }
        .valider { background: #1f8a4c; }
        .refuser { background: #c0392b; }
        .etat { padding: 12px 14px; border-radius: 8px; background: #f0f0f3; }
        .raison { margin: -8px 0 18px; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <h1>{{ $organisation->nom }}</h1>
    @if ($organisation->verification_raison)
        <p class="raison">{{ \App\Services\Inscription\ResultatVerification::RAISONS[$organisation->verification_raison] ?? '' }}</p>
    @endif

    <dl>
        <dt>Vétérinaire</dt><dd>{{ $user?->name }}</dd>
        <dt>E-mail</dt><dd>{{ $user?->email }}</dd>
        <dt>GLN</dt><dd>{{ $organisation->verification_gln }}</dd>
        <dt>Société</dt><dd>{{ $societe?->nom }}{{ $societe?->ide ? ', '.$societe->ide : '' }}</dd>
        <dt>Clinique</dt><dd>{{ $clinique?->rue }}, {{ $clinique?->npa }} {{ $clinique?->ville }}</dd>
    </dl>

    @if (! $enAttente)
        <p class="etat">
            @if ($organisation->verification === \App\Enums\VerificationOrganisation::Verifiee)
                Clinique vérifiée{{ $organisation->verifiee_le ? ' le '.$organisation->verifiee_le->timezone('Europe/Zurich')->format('d.m.Y à H:i') : '' }}. Le vétérinaire a reçu un e-mail pour choisir sa formule.
            @else
                Inscription refusée. Le vétérinaire a reçu un e-mail{{ $organisation->verification_note ? ' avec votre motif' : '' }}.
            @endif
        </p>
    @elseif ($geste === 'valider')
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit" class="valider">Valider la clinique</button>
        </form>
    @else
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <label for="note">Motif, envoyé au vétérinaire (facultatif)</label>
            <textarea id="note" name="note" maxlength="1000"></textarea>
            <button type="submit" class="refuser">Refuser l’inscription</button>
        </form>
    @endif
</main>
</body>
</html>
