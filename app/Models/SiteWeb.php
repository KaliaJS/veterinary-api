<?php

namespace App\Models;

use App\Auth\ContexteClinique;
use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Site web public d'une organisation — il présente toutes ses
 * cliniques. Créé à la première lecture depuis l'application (non
 * publié, services de départ, équipe reprise des employés actifs).
 * Adresses, téléphones et horaires restent ceux des cliniques —
 * jamais recopiés ici.
 */
class SiteWeb extends Model
{
    use AppartientAUneOrganisation;

    /** Teintes proposées au rendu du site (le front en porte les valeurs). */
    public const array COULEURS = ['sauge', 'ocean', 'terracotta', 'prune', 'ardoise', 'perso'];

    public const array MODES = ['clair', 'sombre'];

    /** Style des titres : moderne (sans empattement), élégante (à empattement), douce (arrondie). */
    public const array POLICES = ['moderne', 'elegante', 'douce'];

    public const array COINS = ['arrondis', 'droits'];

    public const array SERVICES_DE_DEPART = [
        ['titre' => 'Consultations', 'description' => 'Bilans de santé, suivi et conseils pour chiens, chats et NAC.'],
        ['titre' => 'Vaccination', 'description' => 'Primo-vaccination, rappels et carnet tenu à jour.'],
        ['titre' => 'Chirurgie', 'description' => 'Stérilisations et interventions courantes au bloc opératoire.'],
        ['titre' => 'Hospitalisation', 'description' => 'Surveillance et soins continus dans nos box.'],
        ['titre' => 'Laboratoire et imagerie', 'description' => 'Analyses sanguines, radiographie et échographie sur place.'],
    ];

    protected $table = 'sites_web';

    /** Défauts connus du modèle dès la création (la base les porte aussi). */
    protected $attributes = [
        'est_publie' => false,
        'couleur' => 'sauge',
        'mode' => 'clair',
        'police' => 'moderne',
        'coins' => 'arrondis',
    ];

    protected $fillable = [
        'organisation_id', 'slug', 'est_publie', 'couleur', 'mode', 'couleur_perso', 'police', 'coins', 'logo_chemin', 'accroche', 'presentation',
        'email', 'tel_urgences', 'services', 'equipe', 'actualites',
    ];

    protected function casts(): array
    {
        return [
            'est_publie' => 'boolean',
            'services' => 'array',
            'equipe' => 'array',
            'actualites' => 'array',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Réglages d'apparence servis tels quels (éditeur et site public).
     *
     * @return array{couleur: string, mode: string, couleur_perso: string|null, police: string, coins: string, logo_url: string|null}
     */
    public function apparence(): array
    {
        return [
            'couleur' => $this->couleur,
            'mode' => $this->mode,
            'couleur_perso' => $this->couleur_perso,
            'police' => $this->police,
            'coins' => $this->coins,
            'logo_url' => $this->logoUrl(),
        ];
    }

    /** URL publique du logo (versionnée pour le cache), ou null. */
    public function logoUrl(): ?string
    {
        return $this->logo_chemin
            ? route('site.logo', ['slug' => $this->slug, 'v' => substr(md5($this->logo_chemin), 0, 8)])
            : null;
    }

    /**
     * Site de l'organisation du jeton, créé à la première lecture (non
     * publié, slug tiré du nom, services de départ, équipe reprise des
     * employés actifs de toutes ses cliniques).
     */
    public static function courant(): self
    {
        $site = static::query()->with('organisation')->first();

        if ($site) {
            return $site;
        }

        $organisation = Organisation::findOrFail(app(ContexteClinique::class)->organisationId());
        $cliniques = $organisation->cliniques()->withoutGlobalScopes()->pluck('cliniques.nom', 'cliniques.id');
        $plusieurs = $cliniques->count() > 1;

        $site = static::create([
            'slug' => static::slugDisponible($organisation->nom),
            'services' => static::SERVICES_DE_DEPART,
            'equipe' => Employe::withoutGlobalScopes()
                ->whereIn('clinique_id', $cliniques->keys())
                ->where('acces_suspendu', false)
                ->orderBy('nom')
                ->get()
                ->map(fn (Employe $employe): array => [
                    'nom' => $employe->nom,
                    'role' => $employe->role_libelle,
                    'bio' => null,
                    'clinique' => $plusieurs ? $cliniques[$employe->clinique_id] : null,
                ])
                ->values()
                ->all(),
            'actualites' => [],
        ]);
        $site->wasRecentlyCreated = false;

        return $site->load('organisation');
    }

    /** Slug libre dérivé du nom de l'organisation (suffixe numérique au besoin). */
    public static function slugDisponible(string $nom, ?int $saufId = null): string
    {
        $base = Str::slug($nom) ?: 'clinique';
        $slug = $base;

        for ($i = 2; static::withoutGlobalScopes()->where('slug', $slug)->when($saufId, fn ($q) => $q->whereKeyNot($saufId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
