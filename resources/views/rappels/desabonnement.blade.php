<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Rappels par e-mail</title>
    <style>
        body { margin: 0; font: 15px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f4f6; color: #1d1d22; }
        main { max-width: 480px; margin: 48px auto; padding: 28px; background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        h1 { font-size: 20px; margin: 0 0 14px; }
        button { font: inherit; font-weight: 600; padding: 10px 18px; border: 0; border-radius: 8px; color: #fff; background: #6b4fd8; cursor: pointer; }
    </style>
</head>
<body>
<main>
    @if ($fait)
        <h1>C’est fait</h1>
        <p>Vous ne recevrez plus les rappels de vaccins et de contrôles par e-mail. La clinique peut toujours vous appeler.</p>
    @else
        <h1>Ne plus recevoir les rappels</h1>
        <p>Les rappels de vaccins et de contrôles ne vous seront plus envoyés par e-mail.</p>
        <form method="post">
            <button type="submit">Ne plus recevoir les rappels</button>
        </form>
    @endif
</main>
</body>
</html>
