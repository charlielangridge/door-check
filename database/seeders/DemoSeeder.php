<?php

namespace Database\Seeders;

use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\DocumentAcceptance;
use App\Models\DocumentVersion;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Models\SiteDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
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
            'email' => 'david@example.test',
            'phone' => '07700900101',
        ]);
        $daddyPig->addMedia(base_path('source/daddy-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');
        $daddyPig->sites()->attach($demoSite->id);

        $mummyPig = Adult::create([
            'name' => 'Mary Piggington',
            'date_of_birth' => '1980-01-01',
            'also_known_as' => ['Mummy Pig'],
            'email' => 'mary@example.test',
            'phone' => '07700900102',
        ]);
        $mummyPig->sites()->attach($demoSite->id);
        $mummyPig->addMedia(base_path('source/mummy-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

        $suzySheep = Adult::create([
            'name' => 'Suzy Mutton',
            'date_of_birth' => '1983-12-12',
            'also_known_as' => ['Suzy Sheep'],
            'email' => 'suzy@example.test',
            'phone' => '07700900103',
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
        $georgePig->sites()->attach($demoSite->id, ['is_active' => true, 'is_approved' => true]);
        $georgePig->adults()->syncWithPivotValues([$daddyPig->id, $mummyPig->id], ['relationship' => 'Parent', 'authorised_to_collect' => true]);
        $georgePig->adults()->attach($suzySheep->id, ['relationship' => 'Family friend', 'authorised_to_collect' => false]);
        $georgePig->addMedia(base_path('source/george-pig.webp'))
            ->preservingOriginal()
            ->toMediaCollection('photo');

        $peppaPig = Child::create([
            'name' => 'Peppa Piggington',
            'date_of_birth' => '2018-01-01',
            'also_known_as' => ['Peppa Pig'],
        ]);
        $peppaPig->sites()->attach($demoSite->id, ['is_active' => false, 'is_approved' => true]);
        $peppaPig->adults()->syncWithPivotValues([$daddyPig->id, $mummyPig->id], ['relationship' => 'Parent', 'authorised_to_collect' => true]);
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

        $profiles = [];
        foreach ([$daddyPig, $mummyPig, $suzySheep] as $adult) {
            $profiles[$adult->id] = AdultAccess::create(['adult_id' => $adult->id, 'name' => $adult->name, 'date_of_birth' => $adult->date_of_birth, 'email' => $adult->email]);
        }
        foreach ([$georgePig, $peppaPig] as $child) {
            foreach ([$daddyPig, $mummyPig] as $adult) {
                ChildAdultSite::create(['site_id' => $demoSite->id, 'child_id' => $child->id, 'adult_id' => $adult->id, 'is_manager' => true, 'document_responsible' => true]);
            }
        }
        ChildAdultSite::create(['site_id' => $demoSite->id, 'child_id' => $georgePig->id, 'adult_id' => $suzySheep->id]);

        $document = SiteDocument::create(['site_id' => $demoSite->id]);
        $firstVersion = DocumentVersion::create(['site_document_id' => $document->id, 'number' => 1, 'title' => 'Demo nursery terms', 'markdown' => '# Demo nursery terms\n\nExample content for demonstrating document acceptance only.', 'requires_acceptance' => true]);
        $currentVersion = DocumentVersion::create(['site_document_id' => $document->id, 'number' => 2, 'title' => 'Demo nursery terms', 'markdown' => "# Demo nursery terms\n\nUpdated example content for demonstrating document acceptance only.", 'requires_acceptance' => true]);
        $document->update(['current_version_id' => $currentVersion->id]);
        foreach ([[$profiles[$daddyPig->id], $firstVersion], [$profiles[$mummyPig->id], $currentVersion]] as [$profile, $version]) {
            DocumentAcceptance::create(['adult_access_id' => $profile->id, 'document_version_id' => $version->id, 'actor_name' => $profile->name, 'actor_email' => $profile->email, 'accepted_at' => now()]);
        }

        $pendingAdult = AdultAccess::create(['name' => 'Alex Demo', 'date_of_birth' => '1988-05-10', 'email' => 'alex-demo@example.test']);
        $pendingPayload = ['name' => 'Taylor Demo', 'also_known_as' => [], 'adults' => [[
            'key' => (string) Str::uuid(), 'name' => $pendingAdult->name, 'date_of_birth' => '1988-05-10', 'email' => $pendingAdult->email,
            'phone' => '07700900104', 'relationship' => 'Parent', 'also_known_as' => [], 'access_id' => $pendingAdult->id,
            'is_manager' => true, 'document_responsible' => true, 'authorised_to_collect' => true,
        ]]];
        ParentRegistration::create(['site_id' => $demoSite->id, 'adult_access_id' => $pendingAdult->id, 'child_name' => 'Taylor Demo', 'child_date_of_birth' => '2022-04-12', 'email' => $pendingAdult->email, 'status' => 'submitted', 'payload' => $pendingPayload, 'submitted_at' => now(), 'expires_at' => now()->addDays(7)]);
        DocumentAcceptance::create(['adult_access_id' => $pendingAdult->id, 'document_version_id' => $currentVersion->id, 'actor_name' => $pendingAdult->name, 'actor_email' => $pendingAdult->email, 'accepted_at' => now()]);
        ParentRegistration::create(['site_id' => $demoSite->id, 'child_name' => 'Riley Invited', 'child_date_of_birth' => '2022-06-10', 'email' => 'riley-parent@example.test', 'status' => 'invited', 'expires_at' => now()->addDays(7)]);
        ParentRegistration::create(['site_id' => $demoSite->id, 'child_name' => 'Sam Draft', 'child_date_of_birth' => '2021-09-01', 'email' => 'sam-parent@example.test', 'status' => 'draft', 'payload' => ['name' => 'Sam Draft', 'adults' => [[
            'key' => (string) Str::uuid(), 'name' => 'Robin Demo', 'date_of_birth' => '', 'phone' => '', 'email' => 'sam-parent@example.test', 'relationship' => 'Parent', 'document_responsible' => true,
        ]]], 'expires_at' => now()->addDays(7)]);
        ParentUpdate::create(['adult_access_id' => $profiles[$daddyPig->id]->id, 'site_id' => $demoSite->id, 'child_id' => $georgePig->id, 'kind' => 'child',
            'payload' => ['name' => $georgePig->name, 'date_of_birth' => '2020-01-01', 'also_known_as' => ['George Pig', 'Georgie Piggington']],
            'original' => $georgePig->only(['name', 'date_of_birth', 'also_known_as']),
        ]);
    }
}
