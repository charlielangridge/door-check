<?php

namespace Database\Factories;

use App\Models\AdultAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdultAccess>
 */
class AdultAccessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(), 'name' => fake()->name(), 'date_of_birth' => '1985-05-10',
        ];
    }
}
