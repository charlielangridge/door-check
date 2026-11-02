<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteDocument>
 */
class SiteDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(), 'is_archived' => false,
        ];
    }
}
