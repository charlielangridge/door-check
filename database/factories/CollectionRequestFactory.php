<?php

namespace Database\Factories;

use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionRequest>
 */
class CollectionRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [

            'uuid' => $this->faker->uuid(),
            'site_id' => Site::factory(),
            'requested_at' => $this->faker->dateTime(),
            'requester_name_text' => $this->faker->name(),
            'requested_child_text' => $this->faker->name(),
            'requester_latitude' => $this->faker->latitude(),
            'requester_longitude' => $this->faker->longitude(),

        ];
    }
}
