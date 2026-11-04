<?php

namespace Database\Factories;

use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionEntry>
 */
class CollectionEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collection_entry_display_id' => CollectionEntryDisplay::factory(),
            'token_expires_at' => now()->addSeconds(60),
        ];
    }
}
