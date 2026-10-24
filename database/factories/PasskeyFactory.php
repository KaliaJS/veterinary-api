<?php

namespace Database\Factories;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Passkey enregistrée d'un compte : un CredentialRecord WebAuthn
 * plausible (attestation « none »), stocké comme le fait PasskeyService.
 * Ne prouve aucune signature : les tests de connexion simulent
 * PasskeyAuthService. Le modèle n'a pas HasFactory : `PasskeyFactory::new()`.
 *
 * @extends Factory<Passkey>
 */
class PasskeyFactory extends Factory
{
    protected $model = Passkey::class;

    public function definition(): array
    {
        return $this->attributs(32, ['internal']);
    }

    /**
     * Identifiant de `$octets` octets et transports donnés (une passkey du
     * trousseau d'Apple : 20 octets, ['hybrid', 'internal']).
     *
     * @param  list<string>  $transports
     */
    public function forme(int $octets, array $transports): static
    {
        return $this->state(fn (): array => $this->attributs($octets, $transports));
    }

    /**
     * @param  list<string>  $transports
     * @return array<string, mixed>
     */
    private function attributs(int $octets, array $transports): array
    {
        $identifiant = random_bytes($octets);

        return [
            'user_id' => User::factory(),
            'nom' => 'MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($identifiant),
            'donnees' => fn (array $attributs): CredentialRecord => CredentialRecord::create(
                $identifiant,
                'public-key',
                $transports,
                'none',
                EmptyTrustPath::create(),
                Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                random_bytes(77),
                // Comme PasskeyRegisterService : opaque, jamais l'id en clair
                hash_hmac('sha256', 'passkey-user|'.$attributs['user_id'], (string) config('app.key'), binary: true),
                0,
            ),
            'dernier_usage_le' => null,
        ];
    }
}
