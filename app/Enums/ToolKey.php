<?php

namespace App\Enums;

/**
 * Les outils de la page Outils de l'app (demande de Jim, 04.11.2026) :
 * seules ces clés peuvent être mises en favori. Les valeurs sont les clés
 * du front (`OutilsPage.vue` et `CT_DEFS` du store `tools`).
 */
enum ToolKey: string
{
    case Dicom = 'dicom';
    case Dose = 'dose';
    case Dent = 'dent';
    case Fluido = 'fluido';
    case Anesth = 'anesth';
    case Transfu = 'transfu';
    case Convert = 'convert';
    case Bcs = 'bcs';
    case Gesta = 'gesta';
    case Antidote = 'antidote';
    case Cert = 'cert';
    case Amicus = 'amicus';
}
