<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\CredentialRecord;

/**
 * Enregistrement d'une passkey validée (porté de foodtruck-api).
 * Améliorations : nom choisi par l'utilisateur (au lieu d'un UUID),
 * refus explicite d'un credential déjà enrôlé.
 */
class PasskeyService
{
    public function save(User $user, string $nom, CredentialRecord $credentialRecord): Passkey
    {
        $credentialId = Base64UrlSafe::encodeUnpadded($credentialRecord->publicKeyCredentialId);

        if (Passkey::where('credential_id', $credentialId)->exists()) {
            throw ValidationException::withMessages([
                'credential' => 'Cette passkey est déjà enregistrée.',
            ]);
        }

        return $user->passkeys()->create([
            'nom' => $nom,
            'credential_id' => $credentialId,
            'donnees' => $credentialRecord,
            'dernier_usage_le' => null,
        ]);
    }
}
