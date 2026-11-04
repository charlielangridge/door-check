<?php

namespace Database\Seeders;

use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // User::factory(10)->create();

        User::create([
            'name' => 'Test User',
            'email' => 'charlie@charlielangridge.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ])->assignRole('super-admin');


        User::create([
            'name' => 'MJ Day',
            'email' => 'mj@fatpoppadaddys.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ])->assignRole('super-admin');

        $this->call(DemoSeeder::class);

    }
}
