<?php

use App\Models\AuditedMedia;
use App\Models\Site;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

test('missing demo media is recovered onto shared storage without changing record ids or reseeding', function (): void {
    Mail::fake();
    Queue::fake([PerformConversionsJob::class]);
    Storage::fake('public');
    Storage::fake('private');
    config(['media-library.disk_name' => 'public']);
    $this->seed(DatabaseSeeder::class);
    $mediaIds = AuditedMedia::query()->pluck('id')->all();
    Storage::disk('public')->deleteDirectory('/');
    config(['filesystems.default' => 'private', 'media-library.disk_name' => 'private']);
    Queue::fake([PerformConversionsJob::class]);

    $this->artisan('demo:restore-media')->assertSuccessful();

    expect(AuditedMedia::query()->pluck('id')->all())->toBe($mediaIds);
    foreach (AuditedMedia::all() as $media) {
        expect($media->disk)->toBe('private');
        expect($media->conversions_disk)->toBe('private');
        Storage::disk('private')->assertExists($media->getPathRelativeToRoot());
        expect(Storage::disk('private')->get($media->getPathRelativeToRoot()))
            ->toBe(file_get_contents(base_path('source/'.$media->file_name)));
    }
    $site = Site::query()->sole();
    $this->get(route('sites.logo', ['site' => $site->slug]))
        ->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertStreamedContent(file_get_contents(base_path('source/sample-logo.png')));
    $this->get(route('staff.media.show', ['site' => $site->slug, 'media' => 5]))->assertRedirectToRoute('login');
    Queue::assertPushed(PerformConversionsJob::class, 7);
    Mail::assertNothingOutgoing();

    Queue::fake([PerformConversionsJob::class]);
    $this->artisan('demo:restore-media')->assertSuccessful();
    Queue::assertNothingPushed();
});

test('demo media recovery refuses an unseeded nursery', function (): void {
    $this->artisan('demo:restore-media')->assertFailed();
});
