<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\HorairesSiteWeb;
use Illuminate\Http\JsonResponse;

/**
 * Site public d'une organisation (visiteur sans compte) : contenu
 * publié et TOUTES ses cliniques, avec coordonnées et horaires lus sur
 * chacune. Les horaires sortent mis en forme (sept jours, plages,
 * statut « ouvert / fermé ») avec les mêmes bornes que le planning —
 * pause comprise, comme à l'accueil.
 */
class SitePublicController extends Controller
{
    public function afficher(string $slug, HorairesSiteWeb $horaires): JsonResponse
    {
        $site = SiteWeb::withoutGlobalScopes()->where('slug', $slug)->where('est_publie', true)->firstOrFail();
        $organisation = Organisation::findOrFail($site->organisation_id);
        $cliniques = $organisation->cliniques()->withoutGlobalScopes()->orderBy('cliniques.id')->get();

        return response()->json(['data' => [
            'slug' => $site->slug,
            'couleur' => $site->couleur,
            'accroche' => $site->accroche,
            'presentation' => $site->presentation,
            'email' => $site->email,
            'tel_urgences' => $site->tel_urgences,
            'services' => array_values($site->services ?? []),
            'equipe' => array_values($site->equipe ?? []),
            'actualites' => collect($site->actualites ?? [])->sortByDesc('date')->values()->all(),
            'organisation' => ['nom' => $organisation->nom],
            'cliniques' => $cliniques->map(fn (Clinique $clinique): array => [
                'id' => $clinique->id,
                'nom' => $clinique->nom,
                'rue' => $clinique->rue,
                'npa' => $clinique->npa,
                'ville' => $clinique->ville,
                'tel' => $clinique->tel,
                'latitude' => $clinique->latitude === null ? null : (float) $clinique->latitude,
                'longitude' => $clinique->longitude === null ? null : (float) $clinique->longitude,
                'horaires' => $horaires->pour($clinique),
            ])->values()->all(),
        ]]);
    }
}
