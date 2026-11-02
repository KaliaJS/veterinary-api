<?php

namespace App\Enums;

/**
 * Les sortes de notifications du téléphone (choix de Jim, 02.11.2026),
 * chacune coupable dans les réglages de chacun.
 */
enum PushType: string
{
    // Propriétaires
    case ConsentRequest = 'consent_request';
    case Quote = 'quote';
    case ClinicMessage = 'clinic_message';
    case Appointment = 'appointment';
    // Équipe
    case ConsentSigned = 'consent_signed';
    case Emergency = 'emergency';
    case TeamMessage = 'team_message';

    public function label(): string
    {
        return match ($this) {
            self::ConsentRequest => 'Consentement à signer',
            self::Quote => 'Devis reçu',
            self::ClinicMessage => 'Message de la clinique',
            self::Appointment => 'Rendez-vous confirmé, déplacé ou annulé',
            self::ConsentSigned => 'Consentement signé par le propriétaire',
            self::Emergency => 'Nouvelle urgence',
            self::TeamMessage => 'Message d’un collègue ou d’une autre clinique',
        };
    }

    public function forStaff(): bool
    {
        return in_array($this, [self::ConsentSigned, self::Emergency, self::TeamMessage], true);
    }
}
