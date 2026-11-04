<?php

namespace Database\Factories;

use App\Models\CollectionEntryDisplay;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionEntryDisplay>
 */
class CollectionEntryDisplayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
        ];
    }
}
