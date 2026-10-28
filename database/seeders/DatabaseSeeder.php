<?php

namespace Database\Seeders;

use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

        $demoSite = Site::create([
            'name' => 'Willowbrook Nursery',
        ]);

        $demoSite->staffUsers()->attach(User::first());
        $demoSite->addMedia(base_path('source/sample-logo.png'))
            ->preservingOriginal()
            ->toMediaCollection('logo');

        $daddyPig = Adult::create([
            'name' => 'David Piggington',
            'date_of_birth' => '1970-01-01',
            'also_known_as' => ['Daddy Pig'],
        ]);
        $daddyPig->addMedia(base_path('source/daddy-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');
        $daddyPig->sites()->attach($demoSite->id);

        $mummyPig = Adult::create([
            'name' => 'Mary Piggington',
            'date_of_birth' => '1980-01-01',
            'also_known_as' => ['Mummy Pig'],
        ]);
        $mummyPig->sites()->attach($demoSite->id);
        $mummyPig->addMedia(base_path('source/mummy-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

        $suzySheep = Adult::create([
            'name' => 'Suzy Mutton',
            'date_of_birth' => '1983-12-12',
            'also_known_as' => ['Suzy Sheep'],
        ]);
        $suzySheep->sites()->attach($demoSite->id);
        $suzySheep->addMedia(base_path('source/suzy-sheep.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

        $georgePig = Child::create([
            'name' => 'George Piggington',
            'date_of_birth' => '2020-01-01',
            'also_known_as' => ['George Pig'],
        ]);
        $georgePig->sites()->attach($demoSite->id);
        $georgePig->adults()->syncWithPivotValues([$daddyPig->id, $mummyPig->id], ['relationship' => 'parent']);
        $georgePig->addMedia(base_path('source/george-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

        $peppaPig = Child::create([
            'name' => 'Peppa Piggington',
            'date_of_birth' => '2018-01-01',
            'also_known_as' => ['Peppa Pig'],
        ]);
        $peppaPig->sites()->attach($demoSite->id);
        $peppaPig->adults()->syncWithPivotValues([$daddyPig->id, $mummyPig->id], ['relationship' => 'parent']);
        $peppaPig->addMedia(base_path('source/peppa-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

        $richardRabbit = Child::create([
            'name' => 'Richard Rabbitsworth',
            'date_of_birth' => '2021-10-01',
            'also_known_as' => ['Richard Rabbit'],
        ]);
        $richardRabbit->sites()->attach($demoSite->id);
        $richardRabbit->addMedia(base_path('source/richard-rabbit.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

    }
}
