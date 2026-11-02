<?php

namespace Database\Factories;

use App\Models\AdultAccess;
use App\Models\ParentUpdate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParentUpdate>
 */
class ParentUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adult_access_id' => AdultAccess::factory(), 'site_id' => Site::factory(), 'kind' => 'adult', 'payload' => ['name' => 'Updated name'], 'status' => 'pending',
        ];
    }
}
