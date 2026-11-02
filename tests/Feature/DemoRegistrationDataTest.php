<?php

use App\Models\Adult;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\DocumentAcceptance;
use App\Models\DocumentVersion;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

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
