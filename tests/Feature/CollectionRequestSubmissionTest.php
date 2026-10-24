<?php

use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can open the collection form for a site', function (): void {
    $site = Site::factory()->create();

    $this->get(route('collection-request.create', $site))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CollectionRequest/Create')
            ->where('site.name', $site->name)
            ->where('site.uuid', $site->uuid));
});

test('guests can submit without evidence and the URL determines the site', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $this->travelTo('2026-10-24 12:00:00');
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();

    $this->post(route('collection-request.store', $site), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'site_id' => $otherSite->id,
        'uuid' => 'client-chosen-uuid',
        'requested_at' => '2000-01-01 00:00:00',
        'adult_id' => 123,
        'requested_child_id' => 456,
        'status' => 'matched',
    ])->assertRedirectToRoute('collection-request.create', $site)
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

test('a requester photo and precise GPS coordinates are attached to the request', function (): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    Storage::fake('local');
    $site = Site::factory()->create();

    $this->post(route('collection-request.store', $site), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'requester_latitude' => 51.50735123,
        'requester_longitude' => -0.12775812,
        'photo' => UploadedFile::fake()->image('requester.jpg', 500, 500),
        'camera_available' => true,
    ])->assertRedirectToRoute('collection-request.create', $site)
        ->assertSessionHasNoErrors();

    $request = CollectionRequest::sole();
    expect($request->requester_latitude)->toBe('51.50735123');
    expect($request->requester_longitude)->toBe('-0.12775812');
    $photo = $request->getFirstMedia('requester_photo');
    expect($photo)->not->toBeNull();
    expect($photo->disk)->toBe('local');
    Storage::disk('local')->assertExists($photo->getPathRelativeToRoot());
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
});

test('invalid collection inputs return useful errors without saving', function (array $invalid, array $errors): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();

    $this->from(route('collection-request.create', $site))
        ->post(route('collection-request.store', $site), [
            'requester_name_text' => 'Jane Smith',
            'requested_child_text' => 'Alex Smith',
            ...$invalid,
        ])
        ->assertRedirectToRoute('collection-request.create', $site)
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
    'invalid coordinates' => [
        ['requester_latitude' => 91, 'requester_longitude' => -181],
        ['requester_latitude' => 'The latitude field must be between -90 and 90.', 'requester_longitude' => 'The longitude field must be between -180 and 180.'],
    ],
    'incomplete location' => [
        ['requester_latitude' => 51.5],
        ['requester_longitude' => 'The longitude field is required when latitude is present.'],
    ],
]);

test('invalid photos are rejected without saving a request', function (Closure $upload): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();

    $this->post(route('collection-request.store', $site), [
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

    $this->withPrecognition()
        ->postJson(route('collection-request.store', $site), [
            'requester_name_text' => 'Jane Smith',
            'requested_child_text' => 'Alex Smith',
        ])->assertSuccessfulPrecognition();

    $this->withPrecognition()
        ->postJson(route('collection-request.store', $site), [
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

    $this->post(route('collection-request.store', $site), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'camera_available' => $available,
    ])->assertSessionHasErrors([
        'photo' => 'Please take a photo before sending your request.',
    ]);

    $this->assertDatabaseCount('collection_request', 0);
    Queue::assertNotPushed(IngestCollectionRequestJob::class);
})->with(['JSON' => [true], 'multipart form' => ['1']]);

test('GPS can be saved without a photo when the camera is unavailable', function (bool|string $available): void {
    Queue::fake([IngestCollectionRequestJob::class]);
    $site = Site::factory()->create();

    $this->post(route('collection-request.store', $site), [
        'requester_name_text' => 'Jane Smith',
        'requested_child_text' => 'Alex Smith',
        'camera_available' => $available,
        'requester_latitude' => 51.50735123,
        'requester_longitude' => -0.12775812,
    ])->assertRedirectToRoute('collection-request.create', $site)
        ->assertSessionHasNoErrors();

    $request = CollectionRequest::sole();
    expect($request->requester_latitude)->toBe('51.50735123');
    expect($request->getMedia('requester_photo'))->toBeEmpty();
    Queue::assertPushed(IngestCollectionRequestJob::class, 1);
})->with(['JSON' => [false], 'multipart form' => ['0']]);
