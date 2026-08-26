<?php

namespace Database\Factories;

use App\Enums\DomainePermission;
use App\Models\Organisation;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'nom' => 'Rôle '.fake()->unique()->word(),
            'domaines' => DomainePermission::cases(),
        ];
    }

    public function sans(DomainePermission ...$exclus): static
    {
        return $this->state(fn (): array => [
            'domaines' => array_values(array_udiff(
                DomainePermission::cases(),
                $exclus,
                fn (DomainePermission $a, DomainePermission $b): int => strcmp($a->value, $b->value),
            )),
        ]);
    }
}
