<?php

namespace Tests\Concerns;

use App\Enums\StatutVerificationVeterinaire;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Met une vidéo en vente comme le ferait le navigateur (31.10.2026) :
 * brouillon, envoi du paquet HLS par adresses signées, contrôle, fiche,
 * publication. Avec BuildsHlsPackages et EmetDesJetonsApi.
 */
trait PublishesVideos
{
    /** @return array{user: User, jeton: string} */
    protected function verifiedVet(bool $readyAccount = true): array
    {
        $vet = $this->proprietaireAvecJeton(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie]);
        if ($readyAccount) {
            $vet['user']->forceFill(['stripe_account_id' => 'acct_vet_'.$vet['user']->id, 'stripe_account_ready_at' => now()])->save();
        }

        return $vet;
    }

    protected function draft(string $jeton, string $base = '/api/v1/espace/videos/seller/videos'): string
    {
        return $this->postJson($base, ['title' => 'Nourrir un chat senior'], $this->enTetes($jeton))->assertCreated()->json('data.id');
    }

    /** Envoie un paquet comme le navigateur : démarrer, demander les adresses, déposer chaque fichier. */
    protected function upload(string $jeton, string $id, array $files, string $base = '/api/v1/espace/videos/seller/videos'): void
    {
        $this->postJson("{$base}/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($jeton))->assertOk();
        // Par paquets de 200 au plus, comme le navigateur
        foreach (array_chunk(array_keys($files), 200) as $batch) {
            $urls = $this->postJson("{$base}/{$id}/upload/urls", ['files' => $batch], $this->enTetes($jeton))->assertOk()->json('data');
            foreach ($urls as $url) {
                $this->call('PUT', $url['url'], [], [], [], [], $files[$url['name']])->assertNoContent();
            }
        }
    }

    protected function finish(string $jeton, string $id, string $base = '/api/v1/espace/videos/seller/videos'): TestResponse
    {
        return $this->postJson("{$base}/{$id}/upload/finish", [], $this->enTetes($jeton));
    }

    /** Une vidéo prête, renseignée et en vente. */
    protected function published(array $fields = [], ?array $vet = null): array
    {
        $vet ??= $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", [
            'title' => 'Nourrir un chat senior', 'description' => 'Ce qu’il faut savoir.', 'price_cents' => 1500, 'audience' => 'owners', ...$fields,
        ], $this->enTetes($vet['jeton']))->assertOk();
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/publish", [], $this->enTetes($vet['jeton']))->assertOk();

        return ['id' => $id, 'vet' => $vet];
    }
}
