<?php

namespace App\Services\Domaines;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare for SaaS — « custom hostnames » d'une zone dont l'origine
 * de repli est l'app (ex. Laravel Cloud). Cloudflare valide le domaine
 * et émet le certificat (validation HTTP) dès que le CNAME du client est
 * en place. Configuration : CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID.
 */
class FournisseurCloudflare implements FournisseurDomaines
{
    private const string API = 'https://api.cloudflare.com/client/v4';

    public function ajouter(string $domaine): ?string
    {
        $reponse = $this->client()->post($this->url(), [
            'hostname' => $domaine,
            'ssl' => ['method' => 'http', 'type' => 'dv'],
        ]);

        if (! $reponse->successful()) {
            throw new RuntimeException($this->message($reponse->json()) ?? 'Cloudflare a refusé le domaine.');
        }

        return $reponse->json('result.id');
    }

    public function etat(string $domaine, ?string $identifiant): array
    {
        if (! $identifiant) {
            return ['actif' => false, 'erreur' => 'Domaine inconnu chez Cloudflare.'];
        }

        $resultat = $this->client()->get($this->url($identifiant))->json('result') ?? [];
        $actif = ($resultat['status'] ?? null) === 'active' && ($resultat['ssl']['status'] ?? null) === 'active';
        $erreurs = [...($resultat['verification_errors'] ?? []), ...($resultat['ssl']['validation_errors'] ?? [])];

        return [
            'actif' => $actif,
            'erreur' => $actif ? null : (collect($erreurs)->map(fn ($e) => is_array($e) ? ($e['message'] ?? null) : $e)->filter()->first()),
        ];
    }

    public function retirer(string $domaine, ?string $identifiant): void
    {
        if ($identifiant) {
            $this->client()->delete($this->url($identifiant));
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('sites.domaines.cloudflare.jeton'))->acceptJson()->timeout(15);
    }

    private function url(?string $identifiant = null): string
    {
        return self::API.'/zones/'.config('sites.domaines.cloudflare.zone').'/custom_hostnames'.($identifiant ? '/'.$identifiant : '');
    }

    /** @param  array<string, mixed>|null  $json */
    private function message(?array $json): ?string
    {
        return $json['errors'][0]['message'] ?? null;
    }
}
