<?php

namespace Database\Factories;

use App\Models\ParentRegistration;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParentRegistration>
 */
class ParentRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(), 'child_name' => fake()->name(), 'child_date_of_birth' => '2020-04-12', 'email' => fake()->unique()->safeEmail(), 'expires_at' => now()->addDays(7),
        ];
    }
}
