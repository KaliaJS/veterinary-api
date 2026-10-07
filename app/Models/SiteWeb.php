<?php

namespace App\Models;

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
    public const array COULEURS = ['sauge', 'ocean', 'terracotta', 'prune', 'ardoise'];

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
    ];

    protected $fillable = [
        'organisation_id', 'slug', 'est_publie', 'couleur', 'accroche', 'presentation',
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
