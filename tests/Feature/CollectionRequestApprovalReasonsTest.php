<?php

use App\CollectionRequestStatus;
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

test('approval explains when the matched child is not linked to the matched adult', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $adult = Adult::factory()->create();
    $child = Child::factory()->create();
    $request = CollectionRequest::factory()->create([
        'adult_id' => $adult->id,
        'requested_child_id' => $child->id,
        'status' => CollectionRequestStatus::NeedsApproval,
        'adult_match_status' => CollectionRequestStatus::Matched,
        'child_match_status' => CollectionRequestStatus::Matched,
    ]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee('Why approval is needed')
        ->assertSee('The matched child is not linked to the matched adult.')
        ->assertDontSee('no match was found');
});

test('approval explains low confidence for both people using the saved threshold', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $request = CollectionRequest::factory()->create([
        'status' => CollectionRequestStatus::NeedsApproval,
        'adult_match_status' => CollectionRequestStatus::NeedsApproval,
        'child_match_status' => CollectionRequestStatus::NeedsApproval,
        'matching_threshold' => 95,
        'adult_match_confidence' => 93,
        'child_match_confidence' => 87,
        'adult_match_candidates' => [['id' => 1, 'name' => 'Adult candidate', 'similarity' => 0.9, 'confidence' => 93]],
        'child_match_candidates' => [['id' => 1, 'name' => 'Child candidate', 'similarity' => 0.8, 'confidence' => 87]],
    ]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee('Adult: the highest JEV confidence is 93%, below the 95% threshold.')
        ->assertSee('Child: the highest JEV confidence is 87%, below the 95% threshold.')
        ->assertDontSee('The matched child is not linked');
});

test('approval explains ambiguous truncated and missing matching results', function (array $attributes, string $reason): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $request = CollectionRequest::factory()->create([
        'status' => CollectionRequestStatus::NeedsApproval,
        'matching_threshold' => 95,
        ...$attributes,
    ]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee($reason);
})->with([
    'multiple qualifying candidates' => [
        ['adult_match_candidates' => [
            ['id' => 1, 'name' => 'First candidate', 'similarity' => 0.9, 'confidence' => 95],
            ['id' => 2, 'name' => 'Second candidate', 'similarity' => 0.9, 'confidence' => 99],
        ]],
        'Adult: multiple candidates meet the 95% threshold;',
    ],
    'truncated search with one qualifying candidate' => [
        ['child_candidates_truncated' => true, 'child_match_candidates' => [
            ['id' => 1, 'name' => 'Child candidate', 'similarity' => 0.9, 'confidence' => 99],
        ]],
        'Child: the candidate search was truncated, so automatic matching could not safely select a person.',
    ],
    'no candidates' => [[], 'Adult: no match was found; a person must be selected manually.'],
    'missing saved threshold' => [
        ['matching_threshold' => null, 'adult_match_candidates' => [
            ['id' => 1, 'name' => 'Adult candidate', 'similarity' => 0.9, 'confidence' => 99],
        ]],
        'Adult: no automatic matching threshold was recorded;',
    ],
]);

test('a subsequently linked relationship is not reported as missing while approval is still pending', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $adult = Adult::factory()->create();
    $child = Child::factory()->create();
    $adult->children()->attach($child, ['relationship' => 'Mother']);
    $request = CollectionRequest::factory()->create([
        'status' => CollectionRequestStatus::NeedsApproval,
        'adult_id' => $adult->id,
        'requested_child_id' => $child->id,
    ]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertSee('This request is still marked as needing approval.')
        ->assertDontSee('The matched child is not linked');

    expect($request->fresh()->status)->toBe(CollectionRequestStatus::NeedsApproval);
});

test('other request statuses do not display approval reasons', function (CollectionRequestStatus $status): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $request = CollectionRequest::factory()->create(['status' => $status]);

    Livewire::test(ViewCollectionRequest::class, ['record' => $request->uuid])
        ->assertOk()
        ->assertDontSee('Why approval is needed');

    expect($request->approvalReasons())->toBe([]);
})->with([CollectionRequestStatus::Pending, CollectionRequestStatus::Matched, CollectionRequestStatus::MatchingFailed]);
