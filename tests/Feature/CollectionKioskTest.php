<?php

use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('kiosk entry requires a current staff session authorised for the display site', function (): void {
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $args = ['site' => $site->slug, 'display' => $display->uuid];
    $this->postJson(route('collection-kiosk.start', $args))->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson(route('collection-kiosk.start', $args))->assertForbidden();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->post(route('collection-kiosk.start', $args))->assertRedirectToRoute('login');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $otherSite = Site::factory()->create();
    $this->post(route('collection-kiosk.start', [...$args, 'site' => $otherSite->slug]))->assertNotFound();
    $this->assertDatabaseCount('collection_request', 0);
});

test('the kiosk button admits a fresh form without setting a collection browser cookie', function (): void {
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $response = $this->post(route('collection-kiosk.start', $displayArgs));
    $entry = $display->entries()->whereNotNull('claimed_at')->sole();
    $args = [...$displayArgs, 'entry' => $entry->uuid];

    $response->assertRedirectToRoute('collection-kiosk.create', $args)->assertCookieMissing('collection_browser');
    $this->get(route('collection-kiosk.create', $args))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/Create')
            ->where('site.uuid', $site->uuid)->where('kiosk.display', $display->uuid)->where('entry.uuid', $entry->uuid)
            ->missing('collection')->missing('requester_name_text')->missing('requested_child_text'));
    $this->postJson(route('collection-request.store', ['site' => $site->slug, 'entry' => $entry->uuid]))->assertForbidden();
    $this->assertDatabaseCount('collection_request', 0);
});

test('a kiosk submission saves evidence and returns to its QR screen with a request received notification', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    Storage::fake('local');
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $entry = $display->entries()->whereNotNull('claimed_at')->sole();

    $this->post(route('collection-kiosk.store', [...$displayArgs, 'entry' => $entry->uuid]), [
        'requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith',
        'requester_latitude' => 51.50735123, 'requester_longitude' => -0.12775812,
        'camera_available' => true, 'photo' => UploadedFile::fake()->image('requester.jpg', 500, 500),
        'kiosk' => false,
    ])->assertRedirectToRoute('collection-display.resume', $displayArgs)
        ->assertSessionHas('collection_request_received', true)->assertSessionHas('inertia.clear_history', true)
        ->assertCookieMissing('collection_browser');

    $collection = CollectionRequest::sole();
    expect($collection->site_id)->toBe($site->id);
    expect($collection->requester_name_text)->toBe('Jane Smith');
    expect($collection->requested_child_text)->toBe('Alex Smith');
    expect($collection->requester_latitude)->toBe('51.50735123');
    expect($collection->requester_longitude)->toBe('-0.12775812');
    expect($collection->browser_key_hash)->toBeNull();
    $photo = $collection->getFirstMedia('requester_photo');
    expect($photo)->not->toBeNull();
    Storage::disk('local')->assertExists($photo->getPathRelativeToRoot());
    $this->get(route('collection-display.resume', $displayArgs))->assertInertia(fn (Assert $page) => $page
        ->component('CollectionRequest/Display')->where('display.uuid', $display->uuid)->where('requestReceived', true));
    $key = session('collection_kiosk.'.$display->uuid.'.key');
    $this->withCookie('collection_browser', $key)->get(route('collection-status.show', $collection))->assertNotFound();
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('kiosk retries stay on the QR screen and never create duplicate requests', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $entry = $display->entries()->whereNotNull('claimed_at')->sole();
    $args = [...$displayArgs, 'entry' => $entry->uuid];
    $this->post(route('collection-kiosk.store', $args), ['requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith'])->assertRedirect();
    $this->travel(16)->minutes();

    $this->post(route('collection-kiosk.store', $args), [])->assertRedirectToRoute('collection-display.resume', $displayArgs);
    $this->get(route('collection-kiosk.create', $args))->assertRedirectToRoute('collection-display.resume', $displayArgs);
    $this->assertDatabaseCount('collection_request', 1);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('starting the next kiosk form invalidates the previous visitors form grant', function (): void {
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $old = $display->entries()->whereNotNull('claimed_at')->sole();
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $current = $display->entries()->whereNotNull('claimed_at')->latest('id')->firstOrFail();
    expect($current->uuid)->not->toBe($old->uuid);

    $this->get(route('collection-kiosk.create', [...$displayArgs, 'entry' => $old->uuid]))->assertRedirectToRoute('collection-display.resume', $displayArgs);
    $this->withCredentials()->postJson(route('collection-kiosk.store', [...$displayArgs, 'entry' => $old->uuid]))->assertForbidden();
    $this->get(route('collection-kiosk.create', [...$displayArgs, 'entry' => $current->uuid]))->assertOk();
    $this->assertDatabaseCount('collection_request', 0);
});

test('kiosk validation and precognition retain evidence rules without creating requests', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withCredentials()->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $entry = $display->entries()->whereNotNull('claimed_at')->sole();
    $url = route('collection-kiosk.store', [...$displayArgs, 'entry' => $entry->uuid]);

    $this->postJson($url, ['camera_available' => true])->assertUnprocessable()
        ->assertJsonValidationErrors(['requester_name_text', 'requested_child_text', 'photo']);
    $this->withPrecognition()->postJson($url, ['requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith'])->assertSuccessfulPrecognition();
    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
});

test('expired kiosk forms return to the QR screen and cannot be submitted', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withCredentials()->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $entry = $display->entries()->whereNotNull('claimed_at')->sole();
    $args = [...$displayArgs, 'entry' => $entry->uuid];
    $this->travel(15)->minutes();

    $this->get(route('collection-kiosk.create', $args))->assertRedirectToRoute('collection-display.resume', $displayArgs);
    $this->postJson(route('collection-kiosk.store', $args))->assertForbidden();
    $this->assertDatabaseCount('collection_request', 0);
});

test('a kiosk session cannot use entries from another display or a phone request', function (): void {
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $otherDisplay = CollectionEntryDisplay::factory()->for($site)->create();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withCredentials()->withSession(['staff_signed_in_at' => now()->timestamp]);
    $displayArgs = ['site' => $site->slug, 'display' => $display->uuid];
    $this->post(route('collection-kiosk.start', $displayArgs))->assertRedirect();
    $entry = $display->entries()->whereNotNull('claimed_at')->sole();
    $this->postJson(route('collection-kiosk.store', [...$displayArgs, 'display' => $otherDisplay->uuid, 'entry' => $entry->uuid]))->assertNotFound();
    $unclaimed = CollectionEntry::factory()->for($display, 'display')->create();

    $this->postJson(route('collection-kiosk.store', [...$displayArgs, 'entry' => $unclaimed->uuid]))->assertForbidden();
    $this->assertDatabaseCount('collection_request', 0);
});
