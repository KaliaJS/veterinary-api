<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\SiteWeb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Site web d'une organisation, tel que créé à sa première lecture : non
 * publié, services de départ, aucune actualité. Le slug suit
 * l'organisation (unique, stable d'un test à l'autre). Le modèle n'a pas
 * HasFactory : `SiteWebFactory::new()`.
 *
 * @extends Factory<SiteWeb>
 */
class SiteWebFactory extends Factory
{
    protected $model = SiteWeb::class;

    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'slug' => fn (array $attributs): string => 'site-'.$attributs['organisation_id'],
            'services' => SiteWeb::SERVICES_DE_DEPART,
            'equipe_masquee' => [],
            'actualites' => [],
        ];
    }

    public function publie(): static
    {
        return $this->state(['est_publie' => true]);
    }
}
