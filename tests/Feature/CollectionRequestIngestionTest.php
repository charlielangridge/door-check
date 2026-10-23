<?php

use App\CollectionRequestStatus;
use App\Jobs\IngestCollectionRequestJob;
use App\Jobs\MatchCollectionRequestAdultJob;
use App\Jobs\MatchCollectionRequestChildJob;
use App\Models\CollectionRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('creating a collection request queues ingestion only after commit', function (): void {
    config(['queue.default' => 'database']);
    DB::beginTransaction();

    $request = CollectionRequest::factory()->create();

    $this->assertDatabaseCount('jobs', 0);
    DB::commit();
    $this->assertDatabaseCount('jobs', 1);
    expect(json_decode(DB::table('jobs')->first()->payload, true)['displayName'])->toBe(IngestCollectionRequestJob::class);
    expect($request->fresh()->status)->toBe(CollectionRequestStatus::Pending);
});

test('rolling back a collection request does not queue ingestion', function (): void {
    config(['queue.default' => 'database']);
    DB::beginTransaction();
    CollectionRequest::factory()->create();

    DB::rollBack();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('collection_request', 0);
});

test('ingestion dispatches separate adult and child matching jobs', function (): void {
    Queue::fake([MatchCollectionRequestAdultJob::class, MatchCollectionRequestChildJob::class]);
    $request = CollectionRequest::factory()->createQuietly();

    (new IngestCollectionRequestJob($request))->handle();

    Queue::assertPushed(MatchCollectionRequestAdultJob::class, fn (MatchCollectionRequestAdultJob $job): bool => $job->collectionRequestId === $request->id);
    Queue::assertPushed(MatchCollectionRequestChildJob::class, fn (MatchCollectionRequestChildJob $job): bool => $job->collectionRequestId === $request->id);
});

test('ingestion skips completed requests on redelivery', function (CollectionRequestStatus $status): void {
    Queue::fake();
    $request = CollectionRequest::factory()->createQuietly();
    $request->forceFill(['status' => $status])->save();

    (new IngestCollectionRequestJob($request))->handle();

    Queue::assertNothingPushed();
})->with([
    CollectionRequestStatus::Matched,
    CollectionRequestStatus::NeedsApproval,
    CollectionRequestStatus::NoMatchFound,
    CollectionRequestStatus::MatchingFailed,
]);

test('ingestion does not redispatch an individually matched person', function (): void {
    Queue::fake([MatchCollectionRequestAdultJob::class, MatchCollectionRequestChildJob::class]);
    $request = CollectionRequest::factory()->createQuietly();
    $request->forceFill(['status' => CollectionRequestStatus::Processing, 'adult_match_status' => CollectionRequestStatus::Matched])->save();

    (new IngestCollectionRequestJob($request))->handle();

    Queue::assertNotPushed(MatchCollectionRequestAdultJob::class);
    Queue::assertPushed(MatchCollectionRequestChildJob::class, 1);
});
