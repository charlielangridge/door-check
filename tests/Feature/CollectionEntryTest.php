<?php

use App\Events\CollectionEntryClaimed;
use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('only staff authorised for the site can open its QR display', function (): void {
    $site = Site::factory()->create();
    $this->get(route('collection-display.show', $site->slug))->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->create())->get(route('collection-display.show', $site->slug))->assertForbidden();

    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->get(route('collection-display.show', $site->slug))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/Display')
            ->where('site.name', $site->name)->where('site.logo', null)->has('display.uuid'));

    $other = Site::factory()->create();
    $this->get(route('collection-display.show', $other->slug))->assertNotFound();
});

test('display heartbeats require a current staff session and cannot cross sites', function (): void {
    $site = Site::factory()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $args = ['site' => $site->slug, 'display' => $display->uuid];
    $this->postJson(route('collection-display.update', $args))->assertUnauthorized();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->postJson(route('collection-display.update', $args))->assertRedirectToRoute('login');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $other = Site::factory()->create();
    $this->postJson(route('collection-display.update', [...$args, 'site' => $other->slug]))->assertNotFound();
});

test('a display keeps its unclaimed code until sixty seconds then replaces it', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    $site = Site::factory()->create();
    $staff = User::factory()->globalCollector()->create();
    $display = CollectionEntryDisplay::factory()->for($site)->create();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $url = route('collection-display.update', ['site' => $site->slug, 'display' => $display->uuid]);
    $first = $this->postJson($url)->assertOk()->assertJsonPath('remaining_ms', 60000)
        ->assertHeader('Cache-Control', 'no-store, private')->json();
    expect($first['qr'])->toStartWith('data:image/svg+xml;base64,');
    expect(base64_decode(substr($first['qr'], strlen('data:image/svg+xml;base64,'))))->toContain('<svg');

    $this->travel(59)->seconds();
    $this->postJson($url, ['entry' => $first['entry']])->assertOk()
        ->assertJsonPath('entry', $first['entry'])->assertJsonPath('qr', null)->assertJsonPath('remaining_ms', 1000);
    $this->travel(1)->seconds();
    $second = $this->postJson($url)->assertOk()->json('entry');
    expect($second)->not->toBe($first['entry']);
    $this->assertDatabaseCount('collection_entries', 2);
});

test('reconnecting replaces the old code without invalidating an already admitted form', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    $site = Site::factory()->create();
    $args = $this->openCollectionEntry($site);
    $claimed = CollectionEntry::query()->where('uuid', $args['entry'])->sole();
    $unclaimed = $claimed->display->entries()->latest('id')->firstOrFail();
    $staff = User::factory()->globalCollector()->create();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $new = $this->postJson(route('collection-display.update', ['site' => $site->slug, 'display' => $claimed->display->uuid]), ['replace' => true])
        ->assertOk()->json('entry');
    expect($new)->not->toBe($unclaimed->uuid);
    expect($unclaimed->fresh()->token_expires_at->isFuture())->toBeFalse();
    $this->get(route('collection-request.create', $args))->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/Create'));
});

test('claiming a code replaces only its display and emits a private update', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    $site = Site::factory()->create();
    $first = CollectionEntry::factory()->for(CollectionEntryDisplay::factory()->for($site), 'display')->create();
    $other = CollectionEntry::factory()->for(CollectionEntryDisplay::factory()->for($site), 'display')->create();
    Event::fake([CollectionEntryClaimed::class]);

    $this->get(route('collection-request.create', ['site' => $site->slug, 'entry' => $first->uuid]))->assertOk()
        ->assertCookie('collection_browser')->assertInertia(fn (Assert $page) => $page
        ->component('CollectionRequest/Create')->where('entry.remaining_ms', 900000));

    expect($first->fresh()->claimed_at)->not->toBeNull();
    expect($first->display->entries()->count())->toBe(2);
    expect($other->display->entries()->count())->toBe(1);
    expect($other->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('collection_request', 0);
    Event::assertDispatched(fn (CollectionEntryClaimed $event): bool => $event->displayUuid === $first->display->uuid
        && $event->broadcastOn()[0]->name === 'private-collection-entry.'.$first->display->uuid);
});

test('a second browser cannot claim or submit another browsers entry', function (): void {
    $site = Site::factory()->create();
    $args = $this->openCollectionEntry($site);
    $this->withCookie('collection_browser', str_repeat('b', 64));

    $this->get(route('collection-request.create', $args))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/ScanRequired'));
    $this->postJson(route('collection-request.store', $args), ['requester_name_text' => 'Another adult', 'requested_child_text' => 'A child'])
        ->assertForbidden();
    $this->assertDatabaseCount('collection_request', 0);
});

test('a photographed code cannot be claimed after its sixty second expiry', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    $site = Site::factory()->create();
    $entry = CollectionEntry::factory()->for(CollectionEntryDisplay::factory()->for($site), 'display')->create();
    $this->travel(60)->seconds();

    $this->get(route('collection-request.create', ['site' => $site->slug, 'entry' => $entry->uuid]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/ScanRequired'));
    expect($entry->fresh()->claimed_at)->toBeNull();
});

test('form refresh preserves its original fifteen minute deadline and submission expires at that deadline', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    $site = Site::factory()->create();
    $args = $this->openCollectionEntry($site);
    $this->travel(13)->minutes();
    $this->get(route('collection-request.create', $args))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/Create')->where('entry.remaining_ms', 120000));
    $this->travel(2)->minutes();

    $this->postJson(route('collection-request.store', $args), ['requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith'])
        ->assertForbidden();
    $this->get(route('collection-request.create', $args))->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/ScanRequired'));
    $this->assertDatabaseCount('collection_request', 0);
});

test('submission retries return the same private status screen even after the form deadline', function (): void {
    $this->travelTo('2026-11-04 12:00:00');
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $args = $this->openCollectionEntry($site);
    $url = route('collection-request.store', $args);
    $this->post($url, ['requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith'])->assertRedirect();
    $collection = CollectionRequest::sole();
    $this->travel(16)->minutes();

    $this->post($url, [])->assertRedirectToRoute('collection-status.show', $collection);
    $this->get(route('collection-request.create', $args))->assertRedirectToRoute('collection-status.show', $collection);
    $this->get(route('collection-status.show', $collection))->assertOk();
    $this->assertDatabaseCount('collection_request', 1);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('a claimed entry cannot be used for another site', function (): void {
    $site = Site::factory()->create();
    $args = $this->openCollectionEntry($site);
    $other = Site::factory()->create();
    $args['site'] = $other->slug;

    $this->get(route('collection-request.create', $args))->assertNotFound();
    $this->postJson(route('collection-request.store', $args))->assertNotFound();
    $this->assertDatabaseCount('collection_request', 0);
});

test('tokenless bookmarks and direct or unclaimed submissions cannot bypass QR entry', function (): void {
    $site = Site::factory()->create();
    $this->get(route('collection-request.create', $site))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/ScanRequired'));
    $this->post(route('collection-request.store', $site))->assertRedirectToRoute('collection-request.create', $site);
    $entry = CollectionEntry::factory()->for(CollectionEntryDisplay::factory()->for($site), 'display')->create();
    $this->postJson(route('collection-request.store', ['site' => $site->slug, 'entry' => $entry->uuid]))->assertForbidden();
    $this->assertDatabaseCount('collection_request', 0);
});

test('malformed entry identifiers are rejected without querying a UUID column', function (): void {
    $site = Site::factory()->create();
    $args = ['site' => $site->slug, 'entry' => 'not-a-uuid'];

    $this->get(route('collection-request.create', $args))->assertNotFound();
    $this->post(route('collection-request.store', $args))->assertNotFound();
});

test('deleting a collection request invalidates its consumed entry without blocking deletion', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $args = $this->openCollectionEntry($site);
    $this->post(route('collection-request.store', $args), ['requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith'])->assertRedirect();
    $collection = CollectionRequest::sole();

    $collection->delete();

    $this->assertModelMissing($collection);
    $this->get(route('collection-request.create', $args))->assertNotFound();
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});
