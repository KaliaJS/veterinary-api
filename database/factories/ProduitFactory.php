<?php

namespace Database\Factories;

use App\Enums\CategorieProduit;
use App\Models\Organisation;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produit>
 */
class ProduitFactory extends Factory
{
    private const array PREFIXES = [
        'med' => 'MED',
        'ali' => 'ALI',
        'soin' => 'SOI',
        'apara' => 'APA',
        'phyto' => 'PHY',
        'mat' => 'MAT',
    ];

    private const array NOMS = [
        'med' => ['Meloxicam 1,5 mg/ml · 100 ml', 'Amoxicilline 250 mg · 20 cp', 'Oclacitinib 16 mg · 20 cp', 'Insuline vétérinaire · 10 ml'],
        'ali' => ['Croquettes rénales chien · 2 kg', 'Croquettes rénales chat · 1,5 kg', 'Pâtée convalescence · 12 × 85 g'],
        'soin' => ['Shampooing chlorhexidine · 250 ml', 'Nettoyant auriculaire · 125 ml', 'Dentifrice enzymatique + doigtier'],
        'apara' => ['Pipettes spot-on chien M · ×4', 'Comprimés antipuces chien · 3 cp', 'Vermifuge large spectre · 2 cp'],
        'phyto' => ['Harpagophytum articulations · 90 gél.', 'Valériane & passiflore — calmant · 30 ml'],
        'mat' => ['Collerette T45', 'Seringues 5 ml · ×100', 'Compresses stériles · ×100'],
    ];

    public function definition(): array
    {
        $categorie = fake()->randomElement(CategorieProduit::cases());
        $cout = fake()->randomFloat(2, 4, 45);

        return [
            'organisation_id' => Organisation::factory(),
            'nom' => fake()->randomElement(self::NOMS[$categorie->value]),
            'categorie' => $categorie,
            'reference' => self::PREFIXES[$categorie->value].'-'.fake()->unique()->numerify('####'),
            'prix' => round($cout * fake()->randomFloat(2, 1.6, 2.2), 2),
            'cout' => $cout,
            'unite' => fake()->randomElement(['flacon', 'boîte', 'dose', 'sac', 'kit', 'pièce', 'carton']),
            'seuil_alerte' => fake()->numberBetween(3, 10),
            'est_boutique' => fake()->boolean(55),
            'est_sur_ordonnance' => $categorie === CategorieProduit::Medicament,
            'peremption_le' => fake()->boolean(80) ? fake()->dateTimeBetween('+2 months', '+2 years')->modify('first day of this month') : null,
            'fournisseur' => fake()->randomElement(['Vetopharm SA', 'Zoevet AG', 'NutriVet Sàrl', 'DermaPet SA', 'MedSupply GmbH', 'PhytoVet Lab']),
            'ean' => null,
            'promo_pct' => 0,
            'est_vedette' => false,
        ];
    }

    public function medicament(): static
    {
        return $this->state(fn (): array => [
            'categorie' => CategorieProduit::Medicament,
            'nom' => fake()->randomElement(self::NOMS['med']),
            'reference' => 'MED-'.fake()->unique()->numerify('####'),
            'est_sur_ordonnance' => true,
        ]);
    }

    public function sansPeremption(): static
    {
        return $this->state(fn (): array => ['peremption_le' => null]);
    }
}
