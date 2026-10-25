<?php

use App\ChildCollectionState;
use App\Jobs\IngestCollectionRequestJob;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('submission redirects the adult to their private status screen', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $response = $this->post(route('collection-request.store', $site), [
        'requester_name_text' => 'David Jones', 'requested_child_text' => 'Sam Jones',
    ]);
    $request = CollectionRequest::sole();

    $response->assertRedirectToRoute('collection-status.show', $request)->assertCookie('collection_browser');
    expect($request->browser_key_hash)->toHaveLength(64);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('only the submitting browser can see the public status and internal checks stay private', function (): void {
    $request = CollectionRequest::factory()->createQuietly([
        'browser_key_hash' => hash('sha256', str_repeat('a', 64)),
        'collection_state' => ChildCollectionState::Cancelled,
        'cancellation_reason' => 'not_authorised', 'cancellation_notes' => 'Internal staff explanation',
        'adult_match_confidence' => 15,
    ]);

    $this->get(route('collection-status.show', $request))->assertNotFound();
    $this->withCookie('collection_browser', str_repeat('b', 64))->get(route('collection-status.show', $request))->assertNotFound();
    $this->withCookie('collection_browser', str_repeat('a', 64))->get(route('collection-status.show', $request))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('CollectionRequest/Status')
            ->where('collection.children.0.label', 'Collection cancelled — please speak to staff')
            ->missing('collection.browser_key_hash')->missing('collection.adult_match_confidence')
            ->missing('collection.children.0.cancellation_notes')->missing('collection.children.0.cancellation_reason'));
});

test('an adult can add a child without changing the accepted adult or venue', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $children = Child::factory()->count(2)->hasAttached($site, [], 'sites')->create();
    $adult->children()->attach($children->modelKeys(), ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $children[0]->id, 'browser_key_hash' => hash('sha256', str_repeat('a', 64))]);

    $this->withCookie('collection_browser', str_repeat('a', 64))->post(route('collection-status.children.store', $request), [
        'requested_child_text' => $children[1]->name,
        'adult_id' => 987654, 'site_id' => 99999, 'requester_name_text' => 'Changed adult',
    ])->assertRedirectToRoute('collection-status.show', $request);

    $extra = CollectionRequest::where('parent_request_id', $request->id)->sole();
    expect($extra)->adult_id->toBe($adult->id)->site_id->toBe($site->id)
        ->requester_name_text->toBe($request->requester_name_text)->requested_child_text->toBe($children[1]->name);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('the add-child option requires a matched adult with multiple children at this venue', function (): void {
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $localChild = Child::factory()->hasAttached($site, [], 'sites')->create();
    $otherChild = Child::factory()->create();
    $adult->children()->attach([$localChild->id, $otherChild->id], ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'browser_key_hash' => hash('sha256', str_repeat('a', 64))]);

    $this->withCookie('collection_browser', str_repeat('a', 64))->get(route('collection-status.show', $request))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('collection.can_add_child', false));
    $this->withCredentials()->postJson(route('collection-status.children.store', $request), ['requested_child_text' => $otherChild->name])->assertUnprocessable();

    $this->assertDatabaseCount('collection_request', 1);
});

test('a known alias cannot add the same child twice', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $first = Child::factory()->hasAttached($site, [], 'sites')->create(['also_known_as' => ['Sammy Jones']]);
    $second = Child::factory()->hasAttached($site, [], 'sites')->create();
    $adult->children()->attach([$first->id, $second->id], ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $first->id, 'browser_key_hash' => hash('sha256', str_repeat('a', 64))]);

    $this->withCookie('collection_browser', str_repeat('a', 64))->withCredentials()->postJson(route('collection-status.children.store', $request), ['requested_child_text' => '  SAMMY   JONES  '])
        ->assertUnprocessable()->assertJsonValidationErrors('requested_child_text');

    $this->assertDatabaseCount('collection_request', 1);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
});

test('a finished request cannot accept another child', function (): void {
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $children = Child::factory()->count(2)->hasAttached($site, [], 'sites')->create();
    $adult->children()->attach($children->modelKeys(), ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'collection_state' => ChildCollectionState::Collected, 'browser_key_hash' => hash('sha256', str_repeat('a', 64))]);

    $this->withCookie('collection_browser', str_repeat('a', 64))->withCredentials()->postJson(route('collection-status.children.store', $request), ['requested_child_text' => $children[1]->name])->assertUnprocessable();

    $this->assertDatabaseCount('collection_request', 1);
});
