<?php

use App\ChildCollectionState;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

test('each site row links to that venues staff dashboard in a new tab', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->administrator()->globalCollector()->create();
    $site = Site::factory()->create();
    $this->actingAs($admin)->withSession(['staff_signed_in_at' => now()->timestamp]);

    Livewire::test(ListSites::class)->assertActionExists(TestAction::make('staffDashboard')->table($site), fn (Action $action): bool => $action->getUrl() === route('staff.board', $site->uuid) && $action->shouldOpenUrlInNewTab());
});

test('an admin opens the selected venue without changing their own staff assignment', function (): void {
    $assignedSite = Site::factory()->create();
    $selectedSite = Site::factory()->create();
    $admin = User::factory()->administrator()->globalCollector()->hasAttached($assignedSite, [], 'staffSites')->create();
    $root = CollectionRequest::factory()->for($selectedSite)->createQuietly();
    CollectionRequest::factory()->for($assignedSite)->createQuietly();
    $this->actingAs($admin)->withSession(['staff_signed_in_at' => now()->timestamp])->get(route('staff.board', $selectedSite->uuid))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('Staff/Board')->where('site.id', $selectedSite->id)->where('site.uuid', $selectedSite->uuid)->has('requests', 1)->where('requests.0.uuid', $root->uuid));

    expect($admin->staffSites()->pluck('sites.id')->all())->toBe([$assignedSite->id]);
    $this->assertDatabaseHas('audits', ['event' => 'staff_board_viewed', 'site_id' => $selectedSite->id, 'user_id' => $admin->id]);
});

test('admins without staff roles can review prepare and collect under their own identity', function (): void {
    $admin = User::factory()->administrator()->globalCollector()->create();
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $root = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $child->id]);
    $this->actingAs($admin)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->get(route('staff.board', $site->uuid))->assertOk();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-InertiaUI-Modal' => 'admin-test-modal'])
        ->get(route('staff.collections.show', ['site' => $root->site->uuid, 'collectionRequest' => $root]))->assertOk()->assertJsonPath('component', 'Staff/Collection')->assertJsonPath('props.collection.site_uuid', $site->uuid);
    $this->flushHeaders();
    $this->getJson(route('staff.collections.checks', ['site' => $root->site->uuid, 'collectionRequest' => $root], absolute: false).'?adult_id='.$adult->id.'&child_id='.$child->id)->assertOk()->assertJsonPath('relationship', 'parent');
    $this->getJson(route('staff.collections.candidates', ['site' => $site->uuid, 'collectionRequest' => $root, 'person' => 'child', 'q' => $child->name]))->assertOk();
    $this->postJson(route('staff.collections.update', ['site' => $root->site->uuid, 'collectionRequest' => $root]), ['action' => 'review', 'revision' => 1, 'identity_confirmed' => true, 'photo_verification_reason' => 'personally_known'])->assertOk();
    $this->postJson(route('staff.collections.update', ['site' => $root->site->uuid, 'collectionRequest' => $root]), ['action' => 'prepare', 'revision' => $root->fresh()->revision])->assertOk();
    $this->postJson(route('staff.collections.update', ['site' => $root->site->uuid, 'collectionRequest' => $root]), ['action' => 'collect', 'revision' => $root->fresh()->revision])->assertOk();
    $this->getJson(route('staff.collections.timeline', ['site' => $root->site->uuid, 'collectionRequest' => $root]))->assertOk();
    expect($root->fresh()->collection_state)->toBe(ChildCollectionState::Collected);
    expect($admin->staffSites()->count())->toBe(0);
    $this->assertDatabaseHas('audits', ['event' => 'collection_collect', 'user_id' => $admin->id, 'site_id' => $site->id, 'collection_request_id' => $root->id]);
});

test('staff cannot escape their assigned venues through staff routes', function (): void {
    $staff = User::factory()->hasAttached(Site::factory(), [], 'staffSites')->create();
    $staff->assignRole('staff');
    $otherSite = Site::factory()->create();
    $root = CollectionRequest::factory()->for($otherSite)->createQuietly();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->get(route('staff.board', $otherSite->uuid))->assertNotFound();
    $this->getJson(route('staff.collections.data', ['site' => $root->site->uuid, 'collectionRequest' => $root]))->assertNotFound();
    $this->postJson(route('staff.collections.update', ['site' => $root->site->uuid, 'collectionRequest' => $root]), ['action' => 'cancel', 'revision' => 1, 'cancellation_reason' => 'duplicate'])->assertNotFound();
    $this->get(route('staff.board', $staff->staffSites()->first()->uuid))->assertOk();
    expect($root->fresh()->collection_state)->toBe(ChildCollectionState::NeedsChecking);
});

test('global processors receive venue-scoped protected identity photos without membership', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $admin = User::factory()->administrator()->globalCollector()->create();
    $site = Site::factory()->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    $photo = $child->addMedia(UploadedFile::fake()->image('child.jpg'))->toMediaCollection('logo', 'local');
    $root = CollectionRequest::factory()->for($site)->createQuietly(['requested_child_id' => $child->id]);
    $this->actingAs($admin)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->getJson(route('staff.collections.data', ['site' => $root->site->uuid, 'collectionRequest' => $root]))->assertOk()->assertJsonPath('children.0.child.photo', route('staff.media.show', ['site' => $site->uuid, 'media' => $photo]));
    $this->get(route('staff.media.show', ['site' => $site->uuid, 'media' => $photo]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

test('unauthenticated and unverified admins cannot open staff dashboards', function (): void {
    $site = Site::factory()->create();
    $this->get(route('staff.board', $site->uuid))->assertRedirectToRoute('login');
    $admin = User::factory()->administrator()->globalCollector()->unverified()->create();
    $this->actingAs($admin)->withSession(['staff_signed_in_at' => now()->timestamp])->get(route('staff.board', $site->uuid))->assertRedirectToRoute('verification.notice');
});
