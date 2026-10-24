<?php

namespace App\Services\Domaines;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            Log::warning('Cloudflare a refusé le domaine.', ['domaine' => $domaine, 'statut' => $reponse->status(), 'erreurs' => $reponse->json('errors')]);

            throw new ErreurDomaine(match (true) {
                $reponse->status() === 409 || str_contains(strtolower((string) $this->message($reponse->json())), 'duplicate') => 'Ce domaine est déjà relié à un autre site.',
                default => 'Ce domaine n’a pas pu être ajouté : vérifiez son orthographe, puis réessayez dans quelques minutes.',
            });
        }

        return $reponse->json('result.id');
    }

    public function etat(string $domaine, ?string $identifiant): array
    {
        if (! $identifiant) {
            return ['actif' => false, 'erreur' => 'Domaine inconnu chez Cloudflare.'];
        }

        // Injoignable ou en erreur de son côté : on ne sait rien du domaine
        try {
            $reponse = $this->client()->get($this->url($identifiant));
        } catch (ConnectionException $e) {
            report($e);

            throw new FournisseurIndisponible;
        }
        // Panne (5xx), trop d'appels (429), ou jeton refusé (401, 403 : jeton
        // expiré ou retiré, 24.10.2026) : on ne sait rien du domaine
        if ($reponse->serverError() || in_array($reponse->status(), [401, 403, 429], true)) {
            Log::warning('Cloudflare ne répond pas pour un domaine.', ['domaine' => $domaine, 'statut' => $reponse->status()]);

            throw new FournisseurIndisponible;
        }
        $resultat = $reponse->json('result') ?? [];
        $actif = ($resultat['status'] ?? null) === 'active' && ($resultat['ssl']['status'] ?? null) === 'active';
        $erreurs = [...($resultat['verification_errors'] ?? []), ...($resultat['ssl']['validation_errors'] ?? [])];

        if (! $actif && $erreurs !== []) {
            Log::info('Domaine pas encore validé par Cloudflare.', ['domaine' => $domaine, 'erreurs' => $erreurs]);
        }

        // Le détail de Cloudflare (anglais, technique) reste au journal
        return [
            'actif' => $actif,
            'erreur' => $actif || $erreurs === [] ? null : 'Le domaine ne pointe pas encore vers votre site : vérifiez l’enregistrement CNAME chez votre registraire. La prise en compte peut demander quelques heures.',
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
