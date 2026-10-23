<?php

namespace App\Models;

use App\Auth\ContexteClinique;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Services\Domaines\DomaineSite;
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
        'afficher_tel' => true,
        'afficher_email' => true,
    ];

    protected $fillable = [
        'organisation_id', 'slug', 'domaine', 'domaine_statut', 'domaine_erreur', 'domaine_fournisseur_id', 'domaine_verifie_le', 'est_publie', 'couleur', 'mode', 'couleur_perso', 'police', 'coins', 'logo_chemin', 'accroche', 'presentation',
        'tel_urgences', 'afficher_tel', 'afficher_email', 'services', 'equipe_masquee', 'actualites',
    ];

    protected function casts(): array
    {
        return [
            'est_publie' => 'boolean',
            'domaine_verifie_le' => 'datetime',
            'services' => 'array',
            'equipe_masquee' => 'array',
            'afficher_tel' => 'boolean',
            'afficher_email' => 'boolean',
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

    /**
     * Équipe de l'organisation telle que le site la connaît : employés
     * présentables, avec leurs cliniques (si l'organisation en a
     * plusieurs), l'état « masqué » (choix de l'administrateur) et leur
     * accord (nLPD, 23.10.2026 : sans lui, personne ne paraît).
     *
     * @return list<array{id: int, nom: string, role: string|null, clinique: string|null, photo_url: string|null, est_masque: bool, a_accepte: bool}>
     */
    public function equipe(): array
    {
        $cliniques = $this->organisation->cliniques()->withoutGlobalScopes()->pluck('cliniques.nom', 'cliniques.id');
        $plusieurs = $cliniques->count() > 1;
        $masques = array_map('intval', $this->equipe_masquee ?? []);

        return Employe::withoutGlobalScopes()
            ->where('organisation_id', $this->organisation_id)
            ->with('cliniques')
            ->orderBy('nom')
            ->get()
            ->filter(fn (Employe $employe): bool => $employe->estPresentable())
            ->map(fn (Employe $employe): array => [
                'id' => $employe->id,
                'nom' => $employe->nom,
                'role' => $employe->role_libelle,
                'clinique' => $plusieurs ? $employe->cliniques->pluck('nom')->implode(', ') : null,
                'photo_url' => $employe->photoUrl(),
                'est_masque' => in_array($employe->id, $masques, true),
                'a_accepte' => $employe->accord_site_le !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * Domaine personnalisé pour l'éditeur : statut et enregistrement DNS
     * à créer chez le registraire (CNAME pour un sous-domaine, A pour un
     * domaine nu si une IP est configurée).
     *
     * @return array<string, mixed>|null
     */
    public function domaineInfo(): ?array
    {
        if (! $this->domaine) {
            return null;
        }

        $etiquettes = explode('.', $this->domaine);
        $estNu = count($etiquettes) === 2;
        $ip = config('sites.domaines.ip');

        $enregistrement = $estNu && $ip
            ? ['type' => 'A', 'nom' => '@', 'valeur' => $ip]
            : ['type' => 'CNAME', 'nom' => $estNu ? '@' : implode('.', array_slice($etiquettes, 0, -2)), 'valeur' => config('sites.domaines.cible_cname')];

        return [
            'nom' => $this->domaine,
            'statut' => $this->domaine_statut,
            'erreur' => $this->domaine_erreur,
            'verifie_le' => $this->domaine_verifie_le?->toIso8601String(),
            'est_nu' => $estNu,
            'enregistrement' => $enregistrement,
        ];
    }

    /** URL publique du logo (versionnée pour le cache), ou null. */
    /** Adresse publique du site : son domaine une fois actif, sinon celle de Veterinary. */
    public function urlPublique(): string
    {
        return $this->domaine && $this->domaine_statut === DomaineSite::ACTIF
            ? 'https://'.$this->domaine
            : rtrim((string) config('app.frontend_url'), '/').'/site/'.$this->slug;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_chemin
            ? route('site.logo', ['slug' => $this->slug, 'v' => substr(md5($this->logo_chemin), 0, 8)])
            : null;
    }

    /**
     * Site de l'organisation du jeton, créé à la première lecture (non
     * publié, slug tiré du nom, services de départ ; l'équipe vient des
     * employés, rien n'est recopié).
     */
    public static function courant(): self
    {
        $site = static::query()->with('organisation')->first();

        if ($site) {
            return $site;
        }

        $organisation = Organisation::findOrFail(app(ContexteClinique::class)->organisationId());

        $site = static::create([
            'slug' => static::slugDisponible($organisation->nom),
            'services' => static::SERVICES_DE_DEPART,
            'equipe_masquee' => [],
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
