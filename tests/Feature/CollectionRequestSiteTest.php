<?php

use App\CollectionRequestStatus;
use App\Filament\Resources\CollectionRequests\Pages\CreateCollectionRequest;
use App\Filament\Resources\CollectionRequests\Pages\EditCollectionRequest;
use App\Filament\Resources\CollectionRequests\Pages\ListCollectionRequests;
use App\Filament\Resources\CollectionRequests\Pages\ViewCollectionRequest;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->administrator()->create());
});

test('manual request creation requires a site and saves it', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $data = ['requested_at' => '2026-10-23 12:00:00', 'requester_name_text' => 'Jane Smith', 'requested_child_text' => 'Alex Smith'];

    Livewire::test(CreateCollectionRequest::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['site_id' => 'required']);
    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNothingPushed();

    Livewire::test(CreateCollectionRequest::class)
        ->fillForm([...$data, 'site_id' => $site->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('collection_request', ['site_id' => $site->id, 'requester_name_text' => 'Jane Smith']);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('the request site cannot be changed after creation', function (): void {
    $request = CollectionRequest::factory()->createQuietly();
    $otherSite = Site::factory()->create();

    expect(fn () => $request->update(['site_id' => $otherSite->id]))->toThrow(ValidationException::class);

    expect($request->fresh()->site_id)->not->toBe($otherSite->id);
});

test('the edit form keeps the original site when its state is tampered with', function (): void {
    $request = CollectionRequest::factory()->createQuietly();
    $otherSite = Site::factory()->create();

    Livewire::test(EditCollectionRequest::class, ['record' => $request->uuid])
        ->fillForm(['site_id' => $otherSite->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($request->fresh()->site_id)->not->toBe($otherSite->id);
});

test('staff can view all sites and filter requests by site', function (): void {
    $first = CollectionRequest::factory()->createQuietly();
    $second = CollectionRequest::factory()->createQuietly();

    Livewire::test(ListCollectionRequests::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertTableColumnStateSet('site.name', $first->site->name, record: $first)
        ->filterTable('site', $first->site_id)
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $first->uuid])
        ->assertOk()
        ->assertSee($first->site->name);
});

test('bulk site deletion preserves sites with requests while allowing unused sites to be deleted', function (): void {
    $request = CollectionRequest::factory()->createQuietly();
    $site = $request->site;
    $unused = Site::factory()->create();

    Livewire::test(ListSites::class)
        ->selectTableRecords([$site->id, $unused->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    $this->assertModelExists($site);
    $this->assertModelExists($request);
    $this->assertModelMissing($unused);
});

test('the database also blocks deleting a site that has requests', function (): void {
    $request = CollectionRequest::factory()->createQuietly();

    expect(fn () => $request->site->delete())->toThrow(QueryException::class);
});

test('membership changes remain visible when the other matching job fails', function (): void {
    $request = CollectionRequest::factory()->createQuietly([
        'status' => CollectionRequestStatus::MatchingFailed,
        'adult_match_status' => CollectionRequestStatus::NeedsApproval,
        'adult_site_membership_changed' => true,
        'child_match_status' => CollectionRequestStatus::MatchingFailed,
    ]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee('Site membership changed while matching was in progress; staff must review the identification.');
});
