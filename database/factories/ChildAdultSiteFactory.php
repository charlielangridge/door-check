<?php

namespace Database\Factories;

use App\Models\Adult;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChildAdultSite>
 */
class ChildAdultSiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(), 'child_id' => Child::factory(), 'adult_id' => Adult::factory(), 'is_manager' => false, 'document_responsible' => false,
        ];
    }
}
