<?php

namespace Database\Factories;

use App\Models\Adult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Adult>
 */
class AdultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'date_of_birth' => $this->faker->date(),
        ];
    }
}
