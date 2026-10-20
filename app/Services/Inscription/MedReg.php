<?php

namespace App\Services\Inscription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * MedReg, le registre des professions médicales de l'OFSP, par les appels
 * de son site public (healthreg-public.admin.ch) : recherche d'un
 * vétérinaire par GLN, puis sa fiche. Ce n'est pas une interface
 * officielle (l'OFSP en vend une, sur demande) : elle peut changer sans
 * prévenir, et une panne laisse simplement la vérification à la main.
 */
final class MedReg
{
    public const int PROFESSION_VETERINAIRE = 4;

    /**
     * Le vétérinaire de ce GLN, ou null s'il n'est pas inscrit comme tel.
     *
     * @throws MedRegIndisponible
     */
    public function chercher(string $gln): ?FicheMedReg
    {
        $resultats = $this->poster('/person/search', [
            'name' => '',
            'firstName' => '',
            'gln' => $gln,
            'professionId' => self::PROFESSION_VETERINAIRE,
        ]);

        $entree = collect($resultats['entries'] ?? [])
            ->first(fn (array $entree): bool => $this->professionVeterinaire($entree['professions'] ?? []) !== null);

        if ($entree === null) {
            return null;
        }

        $personne = $this->poster('/person', ['id' => (int) $entree['id']]);
        $profession = $this->professionVeterinaire($personne['professions'] ?? []);

        if ((string) ($personne['gln'] ?? '') !== $gln || $profession === null) {
            return null;
        }

        return new FicheMedReg(
            id: (int) $personne['id'],
            gln: $gln,
            prenom: trim((string) ($personne['firstName'] ?? '')),
            nom: trim((string) ($personne['name'] ?? '')),
            diplome: $profession['diplomaType']['textFr'] ?? null,
            autorisation: (bool) ($personne['hasPermission'] ?? false),
            cantons: collect($profession['permissions'] ?? [])
                ->map(fn (array $autorisation): ?string => $autorisation['canton']['textFr'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $professions
     * @return array<string, mixed>|null
     */
    private function professionVeterinaire(array $professions): ?array
    {
        return collect($professions)
            ->first(fn (array $profession): bool => ($profession['profession']['id'] ?? null) === self::PROFESSION_VETERINAIRE);
    }

    /**
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     *
     * @throws MedRegIndisponible
     */
    private function poster(string $chemin, array $corps): array
    {
        try {
            $reponse = $this->client()->post($chemin, $corps);
        } catch (ConnectionException $e) {
            throw new MedRegIndisponible('MedReg injoignable.', previous: $e);
        }

        $donnees = $reponse->json();

        if (! $reponse->successful() || ! is_array($donnees)) {
            throw new MedRegIndisponible('MedReg répond HTTP '.$reponse->status().'.');
        }

        return $donnees;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.medreg.url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(10);
    }
}
