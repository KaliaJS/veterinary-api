<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Site web public d'une clinique, créé à la première lecture depuis
 * l'application (non publié, services de départ, équipe reprise des
 * vétérinaires et ASV de la clinique). Adresse, téléphone et horaires
 * restent ceux de la clinique — jamais recopiés ici.
 */
class SiteClinique extends Model
{
    use AppartientAUneClinique;

    /** Teintes proposées au rendu du site (le front en porte les valeurs). */
    public const array COULEURS = ['sauge', 'ocean', 'terracotta', 'prune', 'ardoise'];

    public const array SERVICES_DE_DEPART = [
        ['titre' => 'Consultations', 'description' => 'Bilans de santé, suivi et conseils pour chiens, chats et NAC.'],
        ['titre' => 'Vaccination', 'description' => 'Primo-vaccination, rappels et carnet tenu à jour.'],
        ['titre' => 'Chirurgie', 'description' => 'Stérilisations et interventions courantes au bloc opératoire.'],
        ['titre' => 'Hospitalisation', 'description' => 'Surveillance et soins continus dans nos box.'],
        ['titre' => 'Laboratoire et imagerie', 'description' => 'Analyses sanguines, radiographie et échographie sur place.'],
    ];

    protected $table = 'sites_cliniques';

    /** Défauts connus du modèle dès la création (la base les porte aussi). */
    protected $attributes = [
        'est_publie' => false,
        'couleur' => 'sauge',
    ];

    protected $fillable = [
        'clinique_id', 'slug', 'est_publie', 'couleur', 'accroche', 'presentation',
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

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** Slug libre dérivé du nom de la clinique (suffixe numérique au besoin). */
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
