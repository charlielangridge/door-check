<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use App\Models\SiteDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_document_id' => SiteDocument::factory(), 'number' => 1, 'title' => 'Nursery terms', 'markdown' => '# Terms', 'requires_acceptance' => true,
        ];
    }
}
