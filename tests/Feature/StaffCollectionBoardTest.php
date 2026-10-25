<?php

use App\ChildCollectionState;
use App\Events\CollectionBoardChanged;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

test('guests must sign in to use the staff board', function (): void {
    $this->get('/staff')->assertRedirectToRoute('login');
});

test('staff without permission cannot open the board', function (): void {
    $this->actingAs(User::factory()->create())->get('/staff')->assertForbidden();
});

test('a signed-in staff account sees only its assigned venue', function (): void {
    $site = Site::factory()->create();
    $user = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $user->assignRole('staff');

    $this->actingAs($user)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->get(route('staff.board', $site->uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff/Board')->where('site.id', $site->id));
});

test('staff confirm checks with a recorded photo exception before preparing a child', function (): void {
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $request = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $child->id]);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $request->site->uuid, 'collectionRequest' => $request]), [
            'action' => 'review', 'revision' => 1, 'identity_confirmed' => true,
            'photo_verification_reason' => 'checked_in_person',
        ])->assertOk()->assertJsonPath('saved', true);

    expect($request->fresh())->collection_state->toBe(ChildCollectionState::Reviewed)
        ->adult_locked_at->not->toBeNull()->reviewed_at->not->toBeNull();
    $this->assertDatabaseHas('audits', ['event' => 'collection_review', 'user_id' => $staff->id, 'collection_request_id' => $request->id]);
    expect($adult->children()->whereKey($child->id)->exists())->toBeTrue();
});

test('the board keeps unfinished siblings together and moves only finished groups to history', function (): void {
    $this->freezeTime();
    $site = Site::factory()->create();
    $root = CollectionRequest::factory()->for($site)->createQuietly(['collection_state' => ChildCollectionState::Collected, 'requested_child_text' => 'First child', 'closed_at' => now(), 'requested_at' => now()->subDay()]);
    $extra = CollectionRequest::factory()->for($site)->createQuietly(['parent_request_id' => $root->id, 'requested_child_text' => 'Second child']);
    CollectionRequest::factory()->createQuietly();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->get(route('staff.board', $site->uuid))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('requests', 1)->where('requests.0.uuid', $root->uuid)->where('requests.0.previous_day', true)
        ->has('requests.0.children', 2)->where('requests.0.children.0.state', 'collected')->where('requests.0.children.1.state', 'needs_checking'));
    $this->get(route('staff.board', ['site' => $site->uuid, 'history' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    Event::fake([CollectionBoardChanged::class]);
    $extra->forceFill(['collection_state' => ChildCollectionState::Cancelled, 'closed_at' => now()])->save();
    $this->get(route('staff.board', $site->uuid))->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    $this->get(route('staff.board', ['site' => $site->uuid, 'history' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.uuid', $root->uuid));
    Event::assertDispatched(CollectionBoardChanged::class);
});

test('opening a request returns an Inertia modal and records a view without changing collection data', function (): void {
    $site = Site::factory()->create();
    $root = CollectionRequest::factory()->for($site)->createQuietly();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);
    $this->get(route('staff.board', $site->uuid))->assertOk();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-InertiaUI-Modal' => 'testing-modal'])
        ->get(route('staff.collections.show', ['site' => $root->site->uuid, 'collectionRequest' => $root]))->assertOk()
        ->assertJsonPath('component', 'Staff/Collection')->assertJsonPath('props.collection.uuid', $root->uuid)
        ->assertJsonMissingPath('props.collection.browser_key_hash');

    expect($root->fresh())->collection_state->toBe(ChildCollectionState::NeedsChecking)->revision->toBe(1);
    $this->assertDatabaseHas('audits', ['event' => 'request_viewed', 'collection_request_id' => $root->id, 'user_id' => $staff->id]);
});

test('discarding a draft audits the intent without accepting its submitted people', function (): void {
    $site = Site::factory()->create();
    $root = CollectionRequest::factory()->for($site)->createQuietly();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->postJson(route('staff.collections.update', ['site' => $root->site->uuid, 'collectionRequest' => $root]), ['action' => 'discard', 'revision' => 1, 'adult_id' => 123, 'child_id' => 456])->assertOk();

    expect($root->fresh())->adult_id->toBeNull()->requested_child_id->toBeNull()->collection_state->toBe(ChildCollectionState::NeedsChecking)->revision->toBe(1);
    $this->assertDatabaseHas('audits', ['event' => 'draft_discarded', 'collection_request_id' => $root->id, 'user_id' => $staff->id]);
});
