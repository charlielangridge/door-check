<?php

use App\ChildCollectionState;
use App\CollectionRequestMatchPerson;
use App\Models\Adult;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\CollectionRequestCandidateFinder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function restrictedCollectionFixture(): array
{
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    $child->adults()->attach($adult, ['relationship' => 'Parent']);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $collection = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $child->id]);
    $url = route('staff.collections.update', ['site' => $site->slug, 'collectionRequest' => $collection->uuid]);

    return compact('site', 'adult', 'child', 'staff', 'collection', 'url');
}

test('an explicit collection restriction cannot be bypassed with a staff override', function (): void {
    $fixture = restrictedCollectionFixture();
    $fixture['child']->adults()->updateExistingPivot($fixture['adult']->id, ['authorised_to_collect' => false]);
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson($fixture['url'], ['action' => 'review', 'revision' => $fixture['collection']->fresh()->revision, 'identity_confirmed' => true,
            'relationship_override_reason' => 'Trying to override', 'photo_verification_reason' => 'personally_known'])
        ->assertUnprocessable()->assertJsonValidationErrors('relationship_override_reason');
    expect($fixture['collection']->fresh()->collection_state)->toBe(ChildCollectionState::NeedsChecking);
});

test('turning permission off after review prevents preparation and handover', function (): void {
    $fixture = restrictedCollectionFixture();
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])
        ->postJson($fixture['url'], ['action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known'])
        ->assertOk();
    $fixture['child']->adults()->updateExistingPivot($fixture['adult']->id, ['authorised_to_collect' => false]);
    $this->postJson($fixture['url'], ['action' => 'prepare', 'revision' => $fixture['collection']->fresh()->revision])
        ->assertUnprocessable()->assertJsonValidationErrors('relationship_override_reason');
    expect($fixture['collection']->fresh()->collection_state)->toBe(ChildCollectionState::Reviewed);
});

test('child active status filters separately at each nursery and retains inactive records', function (): void {
    $fixture = restrictedCollectionFixture();
    $otherSite = Site::factory()->create();
    $fixture['child']->sites()->attach($otherSite->id);
    $fixture['child']->sites()->updateExistingPivot($fixture['site']->id, ['is_active' => false]);
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp]);
    $this->get(route('staff.children.index', $fixture['site']->slug))->assertOk()->assertInertia(fn (Assert $page) => $page->where('children', [])->where('inactive', false));
    $this->get(route('staff.children.index', ['site' => $fixture['site']->slug, 'inactive' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('children.0.uuid', $fixture['child']->uuid)->where('inactive', true));
    expect($fixture['child']->sites()->whereKey($otherSite->id)->sole()->pivot->is_active)->toBeTrue();
    $this->assertModelExists($fixture['child']);
});

test('postgres discovery excludes inactive children at only the affected nursery', function (): void {
    $site = Site::factory()->create();
    $child = Child::factory()->hasAttached($site, ['is_active' => false], 'sites')->create(['name' => 'Exact Nursery Child']);
    $otherSite = Site::factory()->create();
    $child->sites()->attach($otherSite->id);
    $finder = app(CollectionRequestCandidateFinder::class);
    expect($finder->find(CollectionRequestMatchPerson::Child, 'Exact Nursery Child', $site->id)['candidates'])->toBe([]);
    expect(array_column($finder->find(CollectionRequestMatchPerson::Child, 'Exact Nursery Child', $otherSite->id)['candidates'], 'id'))->toContain($child->id);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL trigram discovery requires a PostgreSQL test database.');

test('a local adult removal blocks collection only at that nursery', function (): void {
    $fixture = restrictedCollectionFixture();
    ChildAdultSite::factory()->create(['site_id' => $fixture['site']->id, 'child_id' => $fixture['child']->id, 'adult_id' => $fixture['adult']->id, 'is_linked' => false]);
    $this->actingAs($fixture['staff'])->withSession(['staff_signed_in_at' => now()->timestamp])->postJson($fixture['url'], [
        'action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known', 'relationship_override_reason' => 'Cannot bypass a removal',
    ])->assertUnprocessable()->assertJsonValidationErrors('relationship_override_reason');
    $other = Site::factory()->create();
    $fixture['child']->sites()->attach($other);
    $fixture['adult']->sites()->attach($other);
    $fixture['staff']->staffSites()->attach($other);
    $request = CollectionRequest::factory()->for($other)->createQuietly(['adult_id' => $fixture['adult']->id, 'requested_child_id' => $fixture['child']->id]);
    $this->postJson(route('staff.collections.update', ['site' => $other->slug, 'collectionRequest' => $request->uuid]), [
        'action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known',
    ])->assertOk();
});
