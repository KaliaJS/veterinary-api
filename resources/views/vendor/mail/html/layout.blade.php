{{--
    Gabarit de tous les e-mails Markdown (23.10.2026), dans le style du code
    de connexion (mail/code) : fond nuit, wordmark manuscrit, carte sombre,
    pied discret. HTML d'e-mail : tableaux, styles en ligne (le thème
    themes/default.css est aussi mis en ligne par Laravel). Le wordmark est
    joint à l'envoi (cid:logo@veterinary, App\Listeners\LogoDansLesMails).
--}}
@php($police = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif")
<!DOCTYPE html>
<html lang="fr">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<style>
@media only screen and (max-width: 480px) {
.carte { padding: 32px 22px 28px !important; border-radius: 22px !important; }
.button { width: 100% !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body style="margin:0; padding:0; background-color:#08070d;">
<table class="wrapper" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#08070d" style="background-color:#08070d; background-image:radial-gradient(ellipse 80% 50% at 50% 0%, #2a1740 0%, #08070d 70%);">
<tr>
<td align="center" style="padding:48px 16px 40px;">
<table class="content" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
{!! $header ?? '' !!}

<tr>
<td class="carte" bgcolor="#13111b" style="background-color:#13111b; border:1px solid #27232f; border-radius:28px; padding:40px 36px 36px; font-family:{{ $police }};">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
