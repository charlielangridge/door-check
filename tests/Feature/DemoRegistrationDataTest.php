<?php

use App\Models\Adult;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\DocumentAcceptance;
use App\Models\DocumentVersion;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

test('the default database seed restores demo photos and nursery branding from repository assets', function (): void {
    Mail::fake();
    Http::preventStrayRequests();
    Queue::fake([PerformConversionsJob::class]);
    Storage::fake('local');
    Storage::fake('public');
    config(['media-library.disk_name' => 'local']);

    $this->seed(DatabaseSeeder::class);

    $site = Site::query()->where('name', 'Willowbrook Nursery')->sole();
    expect($site->slug)->toBe('willowbrook-nursery');
    expect($site->staffUsers()->sole()->hasRole('super-admin'))->toBeTrue();

    $assets = [
        [$site, 'logo', 'sample-logo.png'],
        [Adult::query()->where('name', 'David Piggington')->sole(), 'photo', 'daddy-pig.webp'],
        [Adult::query()->where('name', 'Mary Piggington')->sole(), 'photo', 'mummy-pig.webp'],
        [Adult::query()->where('name', 'Suzy Mutton')->sole(), 'photo', 'suzy-sheep.webp'],
        [Child::query()->where('name', 'George Piggington')->sole(), 'photo', 'george-pig.webp'],
        [Child::query()->where('name', 'Peppa Piggington')->sole(), 'photo', 'peppa-pig.webp'],
        [Child::query()->where('name', 'Richard Rabbitsworth')->sole(), 'photo', 'richard-rabbit.webp'],
    ];

    foreach ($assets as [$model, $collection, $filename]) {
        $source = base_path('source/'.$filename);
        $this->assertFileExists($source);
        $media = $model->getMedia($collection)->sole();
        expect($media->file_name)->toBe($filename);
        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());
        expect(Storage::disk($media->disk)->get($media->getPathRelativeToRoot()))
            ->toBe(file_get_contents($source));
    }

    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
    Queue::assertPushed(PerformConversionsJob::class);
});

test('demo data demonstrates registration states documents permissions and inactive children without emailing families', function (): void {
    Mail::fake();
    Queue::fake([PerformConversionsJob::class]);
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->create()->assignRole('super-admin');
    $this->seed(DemoSeeder::class);

    expect(ParentRegistration::query()->pluck('status')->all())->toContain('invited', 'draft', 'submitted');
    expect(Child::query()->where('name', 'Peppa Piggington')->sole()->sites()->sole()->pivot->is_active)->toBeFalse();
    $this->assertDatabaseHas('adult_child', ['adult_id' => Adult::query()->where('name', 'Suzy Mutton')->sole()->id, 'authorised_to_collect' => false]);
    expect(ChildAdultSite::query()->where('is_manager', true)->count())->toBeGreaterThan(0);
    expect(DocumentVersion::query()->count())->toBe(2);
    expect(DocumentAcceptance::query()->count())->toBe(3);
    expect(ParentUpdate::query()->sole()->status)->toBe('pending');
    Mail::assertNothingOutgoing();
    Queue::assertPushed(PerformConversionsJob::class);
});
