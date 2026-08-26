<?php

namespace App\Casts;

use App\Support\PasskeyJsonSerializer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Webauthn\CredentialRecord;

/**
 * La colonne `donnees` d'une passkey stocke le CredentialRecord
 * WebAuthn en JSON (pas en objet PHP sérialisé : lisible, migrable
 * et indépendant des versions de classes).
 *
 * @implements CastsAttributes<CredentialRecord, CredentialRecord>
 */
class CredentialRecordCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CredentialRecord
    {
        if ($value === null) {
            return null;
        }

        return PasskeyJsonSerializer::deserialize($value, CredentialRecord::class);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof CredentialRecord) {
            throw new \InvalidArgumentException('La valeur doit être un CredentialRecord.');
        }

        return PasskeyJsonSerializer::serialize($value);
    }
}
