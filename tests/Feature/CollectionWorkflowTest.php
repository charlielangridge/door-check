<?php

use App\ChildCollectionState;
use App\Models\Adult;
use App\Models\Audit;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\StaffCollectionWorkflow;

/** @return array{request: CollectionRequest, staff: User, adult: Adult, child: Child, site: Site} */
function collectionWorkflowFixture(array $attributes = [], bool $related = true): array
{
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    if ($related) {
        $adult->children()->attach($child, ['relationship' => 'parent']);
    }
    $request = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $child->id, ...$attributes]);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');

    return compact('request', 'staff', 'adult', 'child', 'site');
}

test('a collection cannot skip review or preparation', function (string $action): void {
    $fixture = collectionWorkflowFixture();

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => $action, 'revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('action');

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::NeedsChecking)->collected_at->toBeNull();
    $this->assertDatabaseMissing('audits', ['event' => 'collection_'.$action]);
})->with(['prepare', 'collect']);

test('a missing photo needs a verification reason before checks can be confirmed', function (): void {
    $fixture = collectionWorkflowFixture();

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'review', 'revision' => 1, 'identity_confirmed' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('photo_verification_reason');

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::NeedsChecking)->adult_locked_at->toBeNull();
});

test('a relationship override needs an explanation and never creates a permanent relationship', function (): void {
    $fixture = collectionWorkflowFixture(related: false);
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp]);
    $payload = ['action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known'];

    $this->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), $payload)->assertUnprocessable()->assertJsonValidationErrors('relationship_override_reason');
    $this->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), [...$payload, 'relationship_override_reason' => 'Confirmed collection with the parent in person.'])->assertOk();

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::Reviewed)
        ->relationship_override_reason->toBe('Confirmed collection with the parent in person.');
    $this->assertDatabaseMissing('adult_child', ['adult_id' => $fixture['adult']->id, 'child_id' => $fixture['child']->id]);
});

test('other reasons require their own notes', function (array $payload, string $field): void {
    $fixture = collectionWorkflowFixture();

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['revision' => 1, ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($fixture['request']->fresh()->collection_state)->toBe(ChildCollectionState::NeedsChecking);
})->with([
    'cancellation' => [['action' => 'cancel', 'cancellation_reason' => 'other'], 'cancellation_notes'],
    'photo verification' => [['action' => 'review', 'identity_confirmed' => true, 'photo_verification_reason' => 'other'], 'photo_verification_notes'],
]);

test('a stale action gets 409 and cannot overwrite the saved request', function (): void {
    $fixture = collectionWorkflowFixture(['revision' => 4]);

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'cancel', 'revision' => 3, 'cancellation_reason' => 'adult_left'])
        ->assertConflict();

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::NeedsChecking)->revision->toBe(4);
});

test('staff cannot act on or inspect another venue request', function (): void {
    $fixture = collectionWorkflowFixture();
    $other = CollectionRequest::factory()->createQuietly();
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->getJson(route('staff.collections.data', ['site' => $other->site->uuid, 'collectionRequest' => $other]))->assertNotFound();
    $this->postJson(route('staff.collections.update', ['site' => $other->site->uuid, 'collectionRequest' => $other]), ['action' => 'cancel', 'revision' => 1, 'cancellation_reason' => 'duplicate'])->assertNotFound();

    expect($other->fresh()->collection_state)->toBe(ChildCollectionState::NeedsChecking);
});

test('a child cannot be prepared for two requests', function (): void {
    $fixture = collectionWorkflowFixture(['collection_state' => ChildCollectionState::Reviewed, 'photo_verification_reason' => 'personally_known', 'unavailable_photos' => ['child', 'stored_adult', 'current_adult']]);
    CollectionRequest::factory()->for($fixture['site'])->createQuietly(['requested_child_id' => $fixture['child']->id, 'collection_state' => ChildCollectionState::Preparing]);

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'prepare', 'revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['action' => 'This child is already being prepared for another request. Cancel that preparation first.']);

    expect($fixture['request']->fresh()->collection_state)->toBe(ChildCollectionState::Reviewed);
});

test('the same-day collection block uses the venue date across the UTC boundary', function (): void {
    $this->travelTo('2026-06-30 23:30:00');
    $fixture = collectionWorkflowFixture(['collection_state' => ChildCollectionState::Reviewed, 'photo_verification_reason' => 'personally_known', 'unavailable_photos' => ['child', 'stored_adult', 'current_adult']]);
    CollectionRequest::factory()->for($fixture['site'])->createQuietly(['requested_child_id' => $fixture['child']->id, 'collection_state' => ChildCollectionState::Collected, 'collected_at' => '2026-06-30 23:10:00']);

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'prepare', 'revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('action');

    expect($fixture['request']->fresh()->collection_state)->toBe(ChildCollectionState::Reviewed);
});

test('preparing and collecting save distinct audited handover stages', function (): void {
    $fixture = collectionWorkflowFixture(['collection_state' => ChildCollectionState::Reviewed, 'reviewed_at' => now(), 'adult_locked_at' => now(), 'photo_verification_reason' => 'personally_known', 'unavailable_photos' => ['child', 'stored_adult', 'current_adult']]);
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'prepare', 'revision' => 1])->assertOk();
    $this->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'collect', 'revision' => $fixture['request']->fresh()->revision])->assertOk();

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::Collected)
        ->preparing_at->not->toBeNull()->collected_at->not->toBeNull();
    $this->assertDatabaseHas('audits', ['event' => 'collection_prepare', 'user_id' => $fixture['staff']->id]);
    $this->assertDatabaseHas('audits', ['event' => 'collection_collect', 'user_id' => $fixture['staff']->id]);
});

test('a child already in the request cannot be selected for another entry', function (): void {
    $fixture = collectionWorkflowFixture();
    $extra = CollectionRequest::factory()->for($fixture['site'])->createQuietly(['parent_request_id' => $fixture['request']->id, 'adult_id' => $fixture['adult']->id]);

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $extra->site->uuid, 'collectionRequest' => $extra]), ['action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'child_id' => $fixture['child']->id, 'photo_verification_reason' => 'personally_known'])
        ->assertUnprocessable()->assertJsonValidationErrors('child_id');

    expect($extra->fresh()->requested_child_id)->toBeNull();
    expect($fixture['request']->fresh()->duplicate_child_match)->toBeTrue();
    $this->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'review', 'revision' => $fixture['request']->fresh()->revision, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known'])
        ->assertUnprocessable()->assertJsonValidationErrors('child_id');
    $this->postJson(route('staff.collections.update', ['site' => $extra->site->uuid, 'collectionRequest' => $extra]), ['action' => 'cancel', 'revision' => $fixture['request']->fresh()->revision, 'cancellation_reason' => 'duplicate'])->assertOk();
    $this->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'review', 'revision' => $fixture['request']->fresh()->revision, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known'])->assertOk();
});

test('handover is blocked when eligibility is revoked after preparation', function (string $revoked): void {
    $fixture = collectionWorkflowFixture(['collection_state' => ChildCollectionState::Preparing, 'photo_verification_reason' => 'personally_known', 'unavailable_photos' => ['child', 'stored_adult', 'current_adult']]);
    match ($revoked) {
        'adult membership' => $fixture['adult']->sites()->detach($fixture['site']),
        'child membership' => $fixture['child']->sites()->detach($fixture['site']),
        'relationship' => $fixture['adult']->children()->detach($fixture['child']),
    };

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'collect', 'revision' => $fixture['request']->fresh()->revision])
        ->assertUnprocessable()->assertJsonValidationErrors('action');

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::Preparing)->collected_at->toBeNull();
})->with(['adult membership', 'child membership', 'relationship']);

test('reviewing the first child locks the adult for all children', function (): void {
    $fixture = collectionWorkflowFixture(['adult_locked_at' => now(), 'collection_state' => ChildCollectionState::Reviewed]);
    $extra = CollectionRequest::factory()->for($fixture['site'])->createQuietly(['parent_request_id' => $fixture['request']->id, 'adult_id' => $fixture['adult']->id]);
    $otherAdult = Adult::factory()->hasAttached($fixture['site'], [], 'sites')->create();
    $otherChild = Child::factory()->hasAttached($fixture['site'], [], 'sites')->create();

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $extra->site->uuid, 'collectionRequest' => $extra]), ['action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'adult_id' => $otherAdult->id, 'child_id' => $otherChild->id])
        ->assertUnprocessable()->assertJsonValidationErrors('adult_id');

    expect($fixture['request']->fresh()->adult_id)->toBe($fixture['adult']->id);
});

test('staff can explicitly flag a draft duplicate without accepting its identity', function (): void {
    $fixture = collectionWorkflowFixture();
    $extra = CollectionRequest::factory()->for($fixture['site'])->createQuietly(['parent_request_id' => $fixture['request']->id, 'adult_id' => $fixture['adult']->id]);
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->postJson(route('staff.collections.update', ['site' => $extra->site->uuid, 'collectionRequest' => $extra]), ['action' => 'flag_duplicate', 'revision' => 1, 'child_id' => $fixture['child']->id])->assertOk()->assertJsonPath('saved', true);

    expect($extra->fresh())->requested_child_id->toBeNull()->duplicate_child_match->toBeTrue()->collection_state->toBe(ChildCollectionState::NeedsChecking);
    expect($fixture['request']->fresh()->duplicate_child_match)->toBeTrue();
    $this->assertDatabaseHas('audits', ['event' => 'collection_flag_duplicate', 'collection_request_id' => $fixture['request']->id, 'user_id' => $fixture['staff']->id]);
});

test('finished child collections are read-only for staff', function (ChildCollectionState $state): void {
    $fixture = collectionWorkflowFixture(['collection_state' => $state]);

    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson(route('staff.collections.update', ['site' => $fixture['request']->site->uuid, 'collectionRequest' => $fixture['request']]), ['action' => 'cancel', 'revision' => 1, 'cancellation_reason' => 'duplicate'])
        ->assertUnprocessable()->assertJsonValidationErrors('action');

    expect($fixture['request']->fresh()->collection_state)->toBe($state);
})->with([ChildCollectionState::Collected, ChildCollectionState::Cancelled]);

test('an admin correction reopens the child and preserves the original outcome in the audit', function (): void {
    $fixture = collectionWorkflowFixture(['collection_state' => ChildCollectionState::Collected, 'adult_locked_at' => now(), 'reviewed_at' => now(), 'collected_at' => now(), 'closed_at' => now()]);
    $admin = User::factory()->administrator()->create();

    app(StaffCollectionWorkflow::class)->reopen($fixture['request'], $admin, 'Marked collected before the actual handover.');

    expect($fixture['request']->fresh())->collection_state->toBe(ChildCollectionState::NeedsChecking)
        ->reviewed_at->toBeNull()->collected_at->toBeNull()->adult_locked_at->toBeNull();
    $this->assertDatabaseHas('audits', ['event' => 'collection_reopened', 'user_id' => $admin->id, 'collection_request_id' => $fixture['request']->id]);
    expect(Audit::where('event', 'collection_reopened')->sole()->new_values)->toBe(['previous_state' => 'collected', 'reason' => 'Marked collected before the actual handover.']);
});
