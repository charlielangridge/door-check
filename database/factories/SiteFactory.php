<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'uuid' => $this->faker->uuid(),
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ];
    }
}
