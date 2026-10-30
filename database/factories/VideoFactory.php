<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Video> */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'seller_user_id' => User::factory(),
            'seller_name' => 'Dr '.fake()->lastName(),
            'created_by_user_id' => fn (array $attributes) => $attributes['seller_user_id'],
            'title' => fake()->sentence(4),
            'status' => 'draft',
            'files_status' => 'pending',
        ];
    }
}
