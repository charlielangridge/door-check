<?php

namespace Database\Factories;

use App\Models\AdultAccess;
use App\Models\AdultAccessLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdultAccessLink>
 */
class AdultAccessLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adult_access_id' => AdultAccess::factory(), 'purpose' => 'login', 'email' => fake()->safeEmail(), 'expires_at' => now()->addMinutes(15),
        ];
    }
}
