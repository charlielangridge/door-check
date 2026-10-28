<?php

use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

test('staff see stored adult and child photos and can confirm checks without a photo exception', function (string $collection): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $root = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $child->id]);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $adult->addMedia(UploadedFile::fake()->image('old-adult.jpg'))->toMediaCollection('logo', 'local');
    $child->addMedia(UploadedFile::fake()->image('old-child.jpg'))->toMediaCollection('logo', 'local');
    $adultPhoto = $adult->addMedia(UploadedFile::fake()->image('adult.jpg'))->toMediaCollection($collection, 'local');
    $childPhoto = $child->addMedia(UploadedFile::fake()->image('child.jpg'))->toMediaCollection($collection, 'local');
    $root->addMedia(UploadedFile::fake()->image('requester.jpg'))->toMediaCollection('requester_photo', 'local');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $parameters = ['site' => $site->uuid, 'collectionRequest' => $root];

    $this->getJson(route('staff.collections.data', $parameters))->assertOk()
        ->assertJsonPath('adult.photo', route('staff.media.show', ['site' => $site->uuid, 'media' => $adultPhoto]))
        ->assertJsonPath('children.0.child.photo', route('staff.media.show', ['site' => $site->uuid, 'media' => $childPhoto]));
    foreach ([$adultPhoto, $childPhoto] as $photo) {
        $this->get(route('staff.media.show', ['site' => $site->uuid, 'media' => $photo]))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
    $this->getJson(route('staff.collections.checks', [...$parameters, 'adult_id' => $adult->id, 'child_id' => $child->id]))
        ->assertOk()->assertJsonPath('unavailable_photos', []);
    $this->postJson(route('staff.collections.update', $parameters), ['action' => 'review', 'revision' => $root->fresh()->revision, 'identity_confirmed' => true])
        ->assertOk();
    expect($root->fresh())->reviewed_at->not->toBeNull()->photo_verification_reason->toBeNull();
})->with(['photo', 'logo']);
