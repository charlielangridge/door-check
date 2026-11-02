<?php

namespace Database\Factories;

use App\Models\AdultAccess;
use App\Models\DocumentAcceptance;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAcceptance>
 */
class DocumentAcceptanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adult_access_id' => AdultAccess::factory(), 'document_version_id' => DocumentVersion::factory(), 'actor_name' => fake()->name(), 'actor_email' => fake()->safeEmail(), 'accepted_at' => now(),
        ];
    }
}
