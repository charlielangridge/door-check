<?php

use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

test('staff receive child details and every related adult with usable private photos', function (string $collection): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $site = Site::factory()->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create([
        'date_of_birth' => '2018-03-14',
        'also_known_as' => ['Alex Smith'],
    ]);
    $parent = Adult::factory()->create();
    $relative = Adult::factory()->create();
    $child->adults()->attach($parent, ['relationship' => 'Mother']);
    $child->adults()->attach($relative, ['relationship' => '']);
    $otherChild = Child::factory()->create();
    $unrelatedAdult = Adult::factory()->create();
    $otherChild->adults()->attach($unrelatedAdult, ['relationship' => 'Father']);
    $child->addMedia(UploadedFile::fake()->image('old-child.jpg'))->toMediaCollection('logo', 'local');
    $childPhoto = $child->addMedia(UploadedFile::fake()->image('child.jpg'))->toMediaCollection($collection, 'local');
    $parent->addMedia(UploadedFile::fake()->image('old-adult.jpg'))->toMediaCollection('logo', 'local');
    $adultPhoto = $parent->addMedia(UploadedFile::fake()->image('adult.jpg'))->toMediaCollection($collection, 'local');
    $unrelatedPhoto = $unrelatedAdult->addMedia(UploadedFile::fake()->image('unrelated.jpg'))->toMediaCollection('photo', 'local');
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $childPhotoUrl = route('staff.media.show', ['site' => $site->slug, 'media' => $childPhoto]);
    $adultPhotoUrl = route('staff.media.show', ['site' => $site->slug, 'media' => $adultPhoto]);

    $this->get(route('staff.children.index', $site->slug))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff/Child/Index')
            ->has('children', 1)
            ->where('children.0.uuid', $child->uuid)
            ->where('children.0.name', $child->name)
            ->where('children.0.date_of_birth', '2018-03-14')
            ->where('children.0.age', $child->date_of_birth->age)
            ->where('children.0.also_known_as', ['Alex Smith'])
            ->where('children.0.photo', $childPhotoUrl)
            ->has('children.0.adults', 2)
            ->where('children.0.adults.0.name', $parent->name)
            ->where('children.0.adults.0.relationship', 'Mother')
            ->where('children.0.adults.0.photo', $adultPhotoUrl)
            ->where('children.0.adults.1.name', $relative->name)
            ->where('children.0.adults.1.relationship', '')
            ->where('children.0.adults.1.photo', null)
            ->missing('children.0.data'));
    $this->get($childPhotoUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get($adultPhotoUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('staff.media.show', ['site' => $site->slug, 'media' => $unrelatedPhoto]))->assertNotFound();
})->with(['photo', 'logo']);

test('children without photos aliases or related adults have empty details', function (): void {
    $site = Site::factory()->create();
    Child::factory()->hasAttached($site, [], 'sites')->create(['also_known_as' => null]);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->get(route('staff.children.index', $site->slug))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff/Child/Index')
            ->where('children.0.photo', null)
            ->where('children.0.also_known_as', [])
            ->where('children.0.adults', []));
});

test('staff can open their assigned childrens page with only the required site data', function (): void {
    $site = Site::factory()->create();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->get(route('staff.children.index', $site->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff/Child/Index')
            ->where('site.id', $site->id)->where('site.slug', $site->slug)
            ->where('site.name', $site->name)
            ->missing('history')->missing('is_admin')->missing('can_choose_site')->missing('requests'));
});

test('staff cannot open another venues childrens page', function (): void {
    $assigned = Site::factory()->create();
    $other = Site::factory()->create();
    $staff = User::factory()->hasAttached($assigned, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->get(route('staff.children.index', $other->slug))->assertNotFound();
});

test('guests must sign in to open the childrens page', function (): void {
    $site = Site::factory()->create();

    $this->get(route('staff.children.index', $site->slug))->assertRedirectToRoute('login');
});
