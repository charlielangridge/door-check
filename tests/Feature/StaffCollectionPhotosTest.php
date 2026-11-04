<?php

use App\CollectionRequestStatus;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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
    $requesterPhoto = $root->addMedia(UploadedFile::fake()->image('requester.jpg'))->toMediaCollection('requester_photo', 'local');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $parameters = ['site' => $site->slug, 'collectionRequest' => $root];

    $this->getJson(route('staff.collections.data', $parameters))->assertOk()
        ->assertJsonPath('adult.photo', route('staff.media.show', ['site' => $site->slug, 'media' => $adultPhoto]))
        ->assertJsonPath('children.0.child.photo', route('staff.media.show', ['site' => $site->slug, 'media' => $childPhoto]))
        ->assertJsonPath('requester_photo', route('staff.media.show', ['site' => $site->slug, 'media' => $requesterPhoto]));
    $this->get(route('staff.board', $site->slug))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.adult_photo', route('staff.media.show', ['site' => $site->slug, 'media' => $adultPhoto]))
            ->where('requests.0.adult_photo_pending', false));
    foreach ([$adultPhoto, $childPhoto, $requesterPhoto] as $photo) {
        $this->get(route('staff.media.show', ['site' => $site->slug, 'media' => $photo]))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
    $this->getJson(route('staff.collections.checks', [...$parameters, 'adult_id' => $adult->id, 'child_id' => $child->id]))
        ->assertOk()->assertJsonPath('unavailable_photos', []);
    $this->postJson(route('staff.collections.update', $parameters), ['action' => 'review', 'revision' => $root->fresh()->revision, 'identity_confirmed' => true])
        ->assertOk();
    expect($root->fresh())->reviewed_at->not->toBeNull()->photo_verification_reason->toBeNull();
})->with(['photo', 'logo']);

test('the board distinguishes an adult photo awaiting matching from an unavailable photo', function (CollectionRequestStatus $status, bool $pending): void {
    Queue::fake();
    $site = Site::factory()->create();
    CollectionRequest::factory()->for($site)->createQuietly(['adult_match_status' => $status]);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->get(route('staff.board', $site->slug))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.adult_photo', null)
            ->where('requests.0.adult_photo_pending', $pending));
})->with([
    [CollectionRequestStatus::Pending, true],
    [CollectionRequestStatus::Processing, true],
    [CollectionRequestStatus::NoMatchFound, false],
    [CollectionRequestStatus::MatchingFailed, false],
]);
