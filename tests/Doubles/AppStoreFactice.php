<?php

namespace Tests\Doubles;

/**
 * App Store de test : une chaîne de certificats comme celle d'Apple (racine,
 * intermédiaire et feuille avec leurs marqueurs, clés P-256) et des
 * transactions signées en ES256. La racine est posée comme racine de
 * confiance (`services.apple.racines`) : le vrai vérificateur s'exerce.
 */
class AppStoreFactice
{
    private string $dossier;

    /** @var array{0: string, 1: string, 2: string} PEM feuille, intermédiaire, racine */
    private array $chaine;

    private \OpenSSLAsymmetricKey $cleFeuille;

    public function __construct(bool $estDeConfiance = true)
    {
        $this->dossier = sys_get_temp_dir().'/appstore-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
        $conf = $this->dossier.'/openssl.cnf';
        file_put_contents($conf, <<<'CNF'
            [ req ]
            distinguished_name = dn
            [ dn ]
            [ racine ]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            [ intermediaire ]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            1.2.840.113635.100.6.2.1 = ASN1:NULL
            [ feuille ]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,digitalSignature
            1.2.840.113635.100.6.11.1 = ASN1:NULL
            CNF);
        $cle = fn () => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $signer = function (string $nom, $cle, $certSignataire, $cleSignataire, string $section) use ($conf) {
            $options = ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => $section];
            $csr = openssl_csr_new(['commonName' => $nom], $cle, $options);
            $cert = openssl_csr_sign($csr, $certSignataire, $cleSignataire, 3650, $options, random_int(1, PHP_INT_MAX));
            openssl_x509_export($cert, $pem);

            return $pem;
        };

        $cleRacine = $cle();
        $racine = $signer('Racine de test', $cleRacine, null, $cleRacine, 'racine');
        $cleInter = $cle();
        $inter = $signer('Intermédiaire de test', $cleInter, $racine, $cleRacine, 'intermediaire');
        $this->cleFeuille = $cle();
        $feuille = $signer('Feuille de test', $this->cleFeuille, $inter, $cleInter, 'feuille');
        $this->chaine = [$feuille, $inter, $racine];

        if ($estDeConfiance) {
            file_put_contents($this->dossier.'/racine.pem', $racine);
            config(['services.apple.racines' => [$this->dossier.'/racine.pem']]);
        }
    }

    /** Transaction signée (champs d'Apple, complétés par $champs). */
    public function transaction(string $produit, string $compte, array $champs = []): string
    {
        $maintenant = (int) (microtime(true) * 1000);

        return $this->signer(array_merge([
            'transactionId' => (string) random_int(1000000, 9999999),
            'originalTransactionId' => '2000000001',
            'bundleId' => config('services.apple.bundle_id'),
            'productId' => $produit,
            'purchaseDate' => $maintenant,
            'signedDate' => $maintenant,
            'appAccountToken' => $compte,
            'environment' => 'Sandbox',
            'type' => str_ends_with($produit, '.plus') ? 'Auto-Renewable Subscription' : 'Non-Consumable',
        ], $champs));
    }

    /** Notification serveur V2 portant une transaction (et un renouvellement). */
    public function notification(string $type, string $transaction, ?array $renouvellement = null): string
    {
        $donnees = [
            'bundleId' => config('services.apple.bundle_id'),
            'environment' => 'Sandbox',
            'signedTransactionInfo' => $transaction,
        ];
        if ($renouvellement !== null) {
            $donnees['signedRenewalInfo'] = $this->signer($renouvellement + ['signedDate' => (int) (microtime(true) * 1000)]);
        }

        return $this->signer(['notificationType' => $type, 'notificationUUID' => (string) str()->uuid(), 'data' => $donnees, 'signedDate' => (int) (microtime(true) * 1000)]);
    }

    public function signer(array $contenu): string
    {
        $b64url = fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $der = fn (string $pem): string => trim(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        $entete = $b64url(json_encode(['alg' => 'ES256', 'x5c' => array_map($der, $this->chaine)]));
        $corps = $b64url(json_encode($contenu));
        openssl_sign($entete.'.'.$corps, $signatureDer, $this->cleFeuille, OPENSSL_ALGO_SHA256);

        return $entete.'.'.$corps.'.'.$b64url($this->signatureBrute($signatureDer));
    }

    /** Signature DER d'OpenSSL en R‖S (64 octets), comme l'attend un JWS ES256. */
    private function signatureBrute(string $der): string
    {
        $pos = 2;
        $entiers = [];
        for ($i = 0; $i < 2; $i++) {
            $longueur = ord($der[$pos + 1]);
            $entiers[] = str_pad(ltrim(substr($der, $pos + 2, $longueur), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $longueur;
        }

        return $entiers[0].$entiers[1];
    }
}
