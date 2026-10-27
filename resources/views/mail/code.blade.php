{{--
    Code à usage unique (connexion, inscription, vérification d'adresse).
    Identité de la modale de connexion du site : fond nuit, carte vitrée,
    wordmark manuscrit en dégradé, cadre au liseré dégradé. HTML d'e-mail :
    tableaux et styles en ligne ; images intégrées (CID), pas de SVG.
    Mail de macOS en sombre (27.10.2026) : la taille du wordmark va dans
    le style (il ignore l'attribut width), et le bouton n'est jamais en
    blanc pur (#ffffff), qu'il assombrit sans éclaircir le texte.
--}}
@php
    $wordmark = $message->embed(resource_path('mail/wordmark.png'));
    $police = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
    // Textes réglables (suppression du compte, 27.10.2026) ; par défaut, la connexion
    $titre ??= 'Votre code de connexion';
    $consigne ??= 'Saisissez ce code dans Veterinary pour continuer.';
    $avertissement ??= 'Vous n’êtes pas à l’origine de cette demande ? Ignorez cet e-mail : personne ne peut se connecter sans ce code.';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Votre code Veterinary</title>
    <style>
        @media (max-width: 480px) {
            .carte { padding: 34px 20px 30px !important; border-radius: 22px !important; }
            .code { font-size: 27px !important; letter-spacing: 4px !important; padding: 14px 20px !important; }
            .titre { font-size: 23px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#08070d;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">Votre code : {{ $code }}, valable {{ $validiteMinutes }} minutes.</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#08070d" style="background-color:#08070d; background-image:radial-gradient(ellipse 80% 50% at 50% 0%, #2a1740 0%, #08070d 70%);">
        <tr>
            <td align="center" style="padding:48px 16px 40px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
                    {{-- Marque --}}
                    <tr>
                        <td align="center" style="padding-bottom:26px;">
                            <img src="{{ $wordmark }}" width="168" height="43" alt="veterinary" style="display:block; border:0; width:168px; max-width:168px; height:43px;">
                        </td>
                    </tr>

                    {{-- Carte --}}
                    <tr>
                        <td class="carte" bgcolor="#13111b" style="background-color:#13111b; border:1px solid #27232f; border-radius:28px; padding:44px 40px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="titre" align="center" style="font-family:{{ $police }}; font-size:26px; line-height:32px; font-weight:700; letter-spacing:-0.4px; color:#ffffff;">
                                        {{ $titre }}
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:12px 8px 0; font-family:{{ $police }}; font-size:15px; line-height:23px; color:#a39cb4;">
                                        {{ $consigne }}
                                    </td>
                                </tr>

                                {{-- Code dans un cadre au liseré dégradé --}}
                                <tr>
                                    <td align="center" style="padding:30px 0 10px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td bgcolor="#ee42a1" style="background-color:#ee42a1; background-image:linear-gradient(92deg, #ff9d6b, #ff5fa2 48%, #a06bff); border-radius:20px; padding:2px;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td class="code" bgcolor="#0b0a10" style="background-color:#0b0a10; border-radius:18px; padding:16px 26px; font-family:{{ $police }}; font-size:30px; line-height:36px; font-weight:700; letter-spacing:5px; color:#ffffff; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ substr($code, 0, 4) }}<span style="padding-left:12px;">{{ substr($code, 4) }}</span></td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                @if ($lien)
                                    {{-- Séparateur « ou » : le code OU le bouton --}}
                                    <tr>
                                        <td align="center" style="padding:20px 0 0;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td width="56" valign="middle"><div style="height:1px; line-height:1px; font-size:1px; background-color:#34303f;">&nbsp;</div></td>
                                                    <td style="padding:0 14px; font-family:{{ $police }}; font-size:13px; line-height:16px; color:#7d7690;">ou</td>
                                                    <td width="56" valign="middle"><div style="height:1px; line-height:1px; font-size:1px; background-color:#34303f;">&nbsp;</div></td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td align="center" style="padding:18px 0 4px;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td class="bouton" align="center" bgcolor="#fbfaff" style="background-color:#fbfaff; border-radius:999px;">
                                                        <a href="{{ $lien }}" target="_blank" style="display:inline-block; padding:14px 30px; font-family:{{ $police }}; font-size:15px; line-height:20px; font-weight:700; color:#13111b; text-decoration:none; border-radius:999px;">Me connecter en un clic</a>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td align="center" style="padding:14px 8px 0; font-family:{{ $police }}; font-size:14px; line-height:21px; color:#a39cb4;">
                                        Valable <strong style="color:#ffffff; font-weight:600;">{{ $validiteMinutes }} minutes</strong>, à usage unique.
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-top:32px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="border-top:1px solid #27232f; padding-top:22px; font-family:{{ $police }}; font-size:13px; line-height:20px; color:#7d7690; text-align:center;">
                                                    {{ $avertissement }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Pied --}}
                    <tr>
                        <td align="center" style="padding:26px 12px 0; font-family:{{ $police }}; font-size:12px; line-height:19px; color:#5d566e;">
                            Veterinary · Cliniques vétérinaires en Suisse<br>
                            E-mail automatique, merci de ne pas y répondre.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
