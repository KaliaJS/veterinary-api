<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\HorairesSiteWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $site = SiteWeb::withoutGlobalScopes()->with('organisation')->where('slug', $slug)->where('est_publie', true)->firstOrFail();
        $organisation = Organisation::ouvertes()->findOrFail($site->organisation_id);
        $cliniques = $organisation->cliniques()->withoutGlobalScopes()->orderBy('cliniques.id')->get();

        return response()->json(['data' => [
            'slug' => $site->slug,
            ...$site->apparence(),
            'accroche' => $site->accroche,
            'presentation' => $site->presentation,
            'tel_urgences' => $site->tel_urgences,
            'services' => array_values($site->services ?? []),
            'equipe' => collect($site->equipe())->reject(fn (array $m): bool => $m['est_masque'])
                ->map(fn (array $m): array => collect($m)->except('est_masque')->all())->values()->all(),
            'actualites' => collect($site->actualites ?? [])->sortByDesc('date')->values()->all(),
            'organisation' => ['nom' => $organisation->nom],
            'cliniques' => $cliniques->map(fn (Clinique $clinique): array => [
                'id' => $clinique->id,
                'nom' => $clinique->nom,
                'rue' => $clinique->rue,
                'npa' => $clinique->npa,
                'ville' => $clinique->ville,
                // Coordonnées de la clinique, affichées selon les choix du site
                'tel' => $site->afficher_tel ? $clinique->tel : null,
                'email' => $site->afficher_email ? $clinique->email : null,
                'latitude' => $clinique->latitude === null ? null : (float) $clinique->latitude,
                'longitude' => $clinique->longitude === null ? null : (float) $clinique->longitude,
                'horaires' => $horaires->pour($clinique),
                // Prise de rendez-vous : sans compte acceptée, réservée aussitôt ou à valider
                'rdv_sans_compte' => $clinique->rdv_sans_compte,
                'rdv_a_valider' => $clinique->rdv_a_valider,
                'urgences_a_valider' => $clinique->urgences_a_valider,
                // Acompte pour réserver (centimes, null : aucun) et délai de remboursement
                'acompte_centimes' => $clinique->acompte_centimes,
            ])->values()->all(),
        ]]);
    }

    /**
     * Logo de l'organisation — servi même si le site n'est pas publié
     * (aperçu de l'éditeur, annuaire des cliniques). Mis en cache : l'URL
     * change avec le fichier.
     */
    public function logo(string $slug): StreamedResponse
    {
        $site = SiteWeb::withoutGlobalScopes()->where('slug', $slug)->whereNotNull('logo_chemin')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($site->logo_chemin), 404);

        return Storage::disk('local')->response($site->logo_chemin, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
