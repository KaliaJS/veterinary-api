<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Models\PreferencesProprietaire;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Terminer la configuration » du propriétaire (décisions de Jim,
 * 28.10.2026), sur le modèle de celle de la clinique : « Vos rappels »
 * (e-mail, SMS, et le mobile si SMS) et « Vos coordonnées ». Elle s'ouvre
 * d'elle-même au premier accès, puis reste dans le menu jusqu'à ce que
 * tout soit fait ou masqué.
 */
class ConfigurationProprietaireController extends Controller
{
    public function afficher(ContexteProprietaire $contexte): JsonResponse
    {
        return response()->json(['data' => $this->etat($contexte)]);
    }

    public function modifier(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $donnees = $request->validate([
            'vue' => ['sometimes', 'accepted'],
            'masquee' => ['sometimes', 'boolean'],
        ]);
        $preferences = PreferencesProprietaire::pour($contexte->user());
        if (array_key_exists('vue', $donnees) && $preferences->configuration_vue_le === null) {
            $preferences->forceFill(['configuration_vue_le' => now()]);
        }
        if (array_key_exists('masquee', $donnees)) {
            $preferences->forceFill(['configuration_masquee_le' => $donnees['masquee'] ? now() : null]);
        }
        $preferences->save();

        return response()->json(['data' => $this->etat($contexte)]);
    }

    /** @return array{etapes: list<array{cle: string, fait: bool}>, ouvrir: bool, masquee: bool} */
    private function etat(ContexteProprietaire $contexte): array
    {
        $user = $contexte->user();
        $preferences = PreferencesProprietaire::pour($user);
        $fiche = $contexte->clients()->first();
        $tel = $fiche->tel ?? $user->tel;
        $adresse = [$fiche->rue ?? $user->rue, $fiche->npa ?? $user->npa, $fiche->localite ?? $user->localite];

        $etapes = [
            // Choisis, et un mobile pour les SMS s'ils sont voulus
            ['cle' => 'rappels', 'fait' => $preferences->rappels_choisis_le !== null && (! $preferences->rappels_sms || Telephone::mobile($tel) !== null)],
            ['cle' => 'coordonnees', 'fait' => filled($tel) && collect($adresse)->every(fn (?string $v): bool => filled($v))],
        ];
        $masquee = $preferences->configuration_masquee_le !== null;
        $toutFait = collect($etapes)->every(fn (array $e): bool => $e['fait']);

        return [
            'etapes' => $etapes,
            'ouvrir' => ! $masquee && ! $toutFait && $preferences->configuration_vue_le === null,
            'masquee' => $masquee,
        ];
    }
}
