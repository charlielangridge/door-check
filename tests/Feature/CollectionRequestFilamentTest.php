<?php

use App\CollectionRequestStatus;
use App\Filament\Resources\CollectionRequests\Pages\ListCollectionRequests;
use App\Filament\Resources\CollectionRequests\Pages\ViewCollectionRequest;
use App\Jobs\IngestCollectionRequestJob;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->administrator()->create());
});

test('the show page displays submitted details matched people and saved JEV checks', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $adult = Adult::factory()->create(['name' => 'Jane Example']);
    $child = Child::factory()->create(['name' => 'Alex Example']);
    $adult->children()->attach($child, ['relationship' => 'Mother']);
    $request = CollectionRequest::factory()->create([
        'adult_id' => $adult->id,
        'requested_child_id' => $child->id,
        'status' => CollectionRequestStatus::NeedsApproval,
        'matching_threshold' => 95,
        'adult_match_status' => CollectionRequestStatus::NeedsApproval,
        'child_match_status' => CollectionRequestStatus::Matched,
        'adult_match_confidence' => 94.5,
        'child_match_confidence' => 99,
        'adult_candidates_truncated' => true,
        'adult_match_candidates' => [['id' => $adult->id, 'name' => 'Jane candidate snapshot', 'similarity' => 0.75, 'confidence' => 94.5]],
        'child_match_candidates' => [['id' => $child->id, 'name' => 'Alex candidate snapshot', 'similarity' => 0.85, 'confidence' => 99]],
    ]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee($request->requester_name_text)
        ->assertSee($request->requested_child_text)
        ->assertSee($request->requester_latitude)
        ->assertSee($request->requester_longitude)
        ->assertSee($request->uuid)
        ->assertSee('Needs approval')
        ->assertSee('Jane Example')
        ->assertSee('Alex Example')
        ->assertSee('Mother')
        ->assertSee('95%')
        ->assertSee('Jane candidate snapshot')
        ->assertSee('Alex candidate snapshot')
        ->assertSee('0.7500')
        ->assertSee('94.50%')
        ->assertSee('99.00%')
        ->assertSee('Truncated — manual review required');
});

test('pending and failed requests render without matched people or candidate checks', function (CollectionRequestStatus $status): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $request = CollectionRequest::factory()->create(['status' => $status]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee($status->getLabel())
        ->assertSee('Not matched')
        ->assertSee('Not yet set')
        ->assertSee('No candidate checks recorded');
})->with([CollectionRequestStatus::Pending, CollectionRequestStatus::MatchingFailed]);

test('the table displays readable status badges', function (CollectionRequestStatus $status): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $request = CollectionRequest::factory()->create(['status' => $status]);

    Livewire::test(ListCollectionRequests::class)
        ->assertOk()
        ->assertTableColumnFormattedStateSet('status', $status->getLabel(), record: $request);
})->with(CollectionRequestStatus::cases());

test('the table uses related names when linked and submitted names otherwise', function (bool $hasAdult, bool $hasChild): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $adult = $hasAdult ? Adult::factory()->create(['name' => 'Matched Adult']) : null;
    $child = $hasChild ? Child::factory()->create(['name' => 'Matched Child']) : null;
    $request = CollectionRequest::factory()->create([
        'adult_id' => $adult?->id,
        'requested_child_id' => $child?->id,
        'requester_name_text' => 'Submitted Adult',
        'requested_child_text' => 'Submitted Child',
    ]);

    Livewire::test(ListCollectionRequests::class)
        ->assertOk()
        ->assertTableColumnStateSet('requester_name_text', $hasAdult ? 'Matched Adult' : 'Submitted Adult', record: $request)
        ->assertTableColumnStateSet('requested_child_text', $hasChild ? 'Matched Child' : 'Submitted Child', record: $request);
})->with([
    'both linked' => [true, true],
    'adult linked' => [true, false],
    'child linked' => [false, true],
    'neither linked' => [false, false],
]);
