<?php

use App\ChildCollectionState;
use App\CollectionRequestStatus;
use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can open the collection form with a claimed entry', function (): void {
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->get(route('collection-request.create', $entryParameters))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CollectionRequest/Create')
            ->where('site.name', $site->name)
            ->where('site.slug', $site->slug)
            ->where('site.uuid', $site->uuid));
});

test('the collection form provides a site slug that can be used to submit a request', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $page = $this->get(route('collection-request.create', $entryParameters))->assertOk();

    $response = $this->post(route('collection-request.store', ['site' => $page->inertiaProps('site.slug'), 'entry' => $page->inertiaProps('entry.uuid')]), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
    ])->assertRedirect();

    $collectionRequest = CollectionRequest::sole();
    $response->assertRedirectToRoute('collection-status.show', $collectionRequest);
    expect($collectionRequest->site_id)->toBe($site->id);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('submission opens the status page before queued matching runs and subsequent visits show progress', function (): void {
    config(['queue.default' => 'database']);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $response = $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
    ]);

    $collectionRequest = CollectionRequest::sole();
    $response->assertRedirectToRoute('collection-status.show', $collectionRequest)
        ->assertCookie('collection_browser');
    expect($collectionRequest->status)->toBe(CollectionRequestStatus::Pending);
    expect($collectionRequest->adult_match_status)->toBe(CollectionRequestStatus::Pending);
    expect($collectionRequest->child_match_status)->toBe(CollectionRequestStatus::Pending);
    $this->assertDatabaseCount('jobs', 2);

    $this->withCookie('collection_browser', $response->getCookie('collection_browser')->getValue())
        ->get(route('collection-status.show', $collectionRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CollectionRequest/Status')
            ->where('collection.site.slug', $site->slug)
            ->where('collection.children.0.label', 'Request received')
            ->where('collection.finished', false));

    $collectionRequest->forceFill(['collection_state' => ChildCollectionState::Preparing])->save();

    $this->get(route('collection-status.show', $collectionRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('collection.children.0.label', 'Preparing child')
            ->where('collection.finished', false));

    $collectionRequest->forceFill(['collection_state' => ChildCollectionState::Collected])->save();

    $this->get(route('collection-status.show', $collectionRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('collection.children.0.label', 'Child collected')
            ->where('collection.finished', true));
});

test('guests can submit without evidence and the URL determines the site', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $this->travelTo('2026-10-24 12:00:00');
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);
    $otherSite = Site::factory()->create();

    $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'site_id' => $otherSite->id,
        'uuid' => 'client-chosen-uuid',
        'requested_at' => '2000-01-01 00:00:00',
        'adult_id' => 123,
        'requested_child_id' => 456,
        'status' => 'matched',
    ])->assertRedirectToRoute('collection-status.show', CollectionRequest::sole())
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('collection_request', 1);
    $this->assertDatabaseHas('collection_request', [
        'site_id' => $site->id,
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'requested_at' => '2026-10-24 12:00:00',
        'requester_latitude' => null,
        'requester_longitude' => null,
        'adult_id' => null,
        'requested_child_id' => null,
        'status' => 'pending',
    ]);
    expect(CollectionRequest::sole()->uuid)->toBeUuid();
    expect(CollectionRequest::sole()->getMedia('requester_photo'))->toBeEmpty();
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('a requester photo is attached to the request', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    Storage::fake('local');
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'photo' => UploadedFile::fake()->image('requester.jpg', 500, 500),
        'camera_available' => true,
    ])->assertRedirectToRoute('collection-status.show', CollectionRequest::sole())
        ->assertSessionHasNoErrors();

    $request = CollectionRequest::sole();
    $photo = $request->getFirstMedia('requester_photo');
    expect($photo)->not->toBeNull();
    expect($photo->disk)->toBe('local');
    Storage::disk('local')->assertExists($photo->getPathRelativeToRoot());
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('invalid collection inputs return useful errors without saving', function (array $invalid, array $errors): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->from(route('collection-request.create', $entryParameters))
        ->post(route('collection-request.store', $entryParameters), [
            'requester_name_text' => 'Jane Smith',
            'requested_child_text' => 'Alex Smith',
            ...$invalid,
        ])
        ->assertRedirectToRoute('collection-request.create', $entryParameters)
        ->assertSessionHasErrors($errors);

    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
})->with([
    'names missing' => [
        ['requester_name_text' => '', 'requested_child_text' => ''],
        ['requester_name_text' => 'The your name field is required.', 'requested_child_text' => 'The child you are collecting field is required.'],
    ],
    'name too long' => [
        ['requester_name_text' => str_repeat('a', 256)],
        ['requester_name_text' => 'The your name field must not be greater than 255 characters.'],
    ],
    'child name too long' => [
        ['requested_child_text' => str_repeat('a', 256)],
        ['requested_child_text' => 'The child you are collecting field must not be greater than 255 characters.'],
    ],
]);

test('obsolete GPS payloads are ignored without validation or storage', function (array $coordinates): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        ...$coordinates,
    ])->assertRedirectToRoute('collection-status.show', CollectionRequest::sole())->assertSessionHasNoErrors();

    $this->assertDatabaseHas('collection_request', ['requester_latitude' => null, 'requester_longitude' => null]);
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
})->with([
    'valid coordinates' => [['requester_latitude' => 51.50735123, 'requester_longitude' => -0.12775812]],
    'invalid coordinates' => [['requester_latitude' => 91, 'requester_longitude' => -181]],
    'incomplete location' => [['requester_latitude' => 51.5]],
]);

test('invalid photos are rejected without saving a request', function (Closure $upload): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'photo' => $upload(),
    ])->assertSessionHasErrors('photo');

    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
})->with([
    'not an image' => [fn () => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')],
    'too large' => [fn () => UploadedFile::fake()->image('requester.jpg')->size(2049)],
]);

test('precognition validates names without creating a collection request', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->withPrecognition()
        ->postJson(route('collection-request.store', $entryParameters), [
            'requester_name_text' => 'Jane Smith',
            'requested_child_text' => 'Alex Smith',
        ])->assertSuccessfulPrecognition();

    $this->withPrecognition()
        ->postJson(route('collection-request.store', $entryParameters), [
            'requester_name_text' => '',
            'requested_child_text' => 'Alex Smith',
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['requester_name_text']);

    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
});

test('an unknown site cannot receive a collection request', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);

    $this->post(route('collection-request.store', '00000000-0000-4000-8000-000000000000'), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
    ])->assertNotFound();

    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
});

test('a photo is required when the form reports a working camera', function (bool|string $available): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'camera_available' => $available,
    ])->assertSessionHasErrors([
        'photo' => 'Please take a photo before sending your request.',
    ]);

    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
})->with(['JSON' => [true], 'multipart form' => ['1']]);

test('requests can be submitted without a photo when the camera is unavailable', function (bool|string $available): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();
    $entryParameters = $this->openCollectionEntry($site);

    $this->post(route('collection-request.store', $entryParameters), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'camera_available' => $available,
    ])->assertRedirectToRoute('collection-status.show', CollectionRequest::sole())
        ->assertSessionHasNoErrors();

    $request = CollectionRequest::sole();
    expect($request->getMedia('requester_photo'))->toBeEmpty();
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
})->with(['JSON' => [false], 'multipart form' => ['0']]);
