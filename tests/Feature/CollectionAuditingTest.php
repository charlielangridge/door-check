<?php

use App\Filament\Resources\Adults\Pages\EditAdult;
use App\Models\Adult;
use App\Models\Audit;
use App\Models\AuditedMedia;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\CollectionBoardData;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

test('staff timelines include relevant membership and photo changes without exposing other venues or relationships', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $child = Child::factory()->hasAttached($site, [], 'sites')->create();
    $unrelatedChild = Child::factory()->hasAttached($otherSite, [], 'sites')->create();
    $root = CollectionRequest::factory()->for($site)->createQuietly(['adult_id' => $adult->id, 'requested_child_id' => $child->id, 'requested_at' => now()->subMinute()]);
    $admin = User::factory()->administrator()->create();
    $this->actingAs($admin);
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $included = Audit::latest('id')->first()->id;
    $adult->sites()->attach($otherSite);
    $excludedMembership = Audit::latest('id')->first()->id;
    $adult->children()->attach($unrelatedChild, ['relationship' => 'guardian']);
    $excludedRelationship = Audit::latest('id')->first()->id;
    $photo = $adult->addMedia(UploadedFile::fake()->image('identity.jpg'))->toMediaCollection('logo', 'local');
    $photoAudit = Audit::where('auditable_type', AuditedMedia::class)->where('auditable_id', $photo->id)->where('event', 'created')->sole();
    $site->children()->detach($child);
    $removedMembership = Audit::latest('id')->first()->id;

    $events = app(CollectionBoardData::class)->timeline($root)['events'];

    expect(array_column($events, 'id'))->toContain($included, $photoAudit->id, $removedMembership)
        ->not->toContain($excludedMembership, $excludedRelationship);
});

test('alternative names and relationship changes are audited with the acting account', function (): void {
    $admin = User::factory()->administrator()->create();
    $adult = Adult::factory()->create();
    $child = Child::factory()->create();
    $this->actingAs($admin);

    $adult->update(['also_known_as' => ['Davey Jones', 'David Smith']]);
    $adult->children()->attach($child, ['relationship' => 'parent']);
    $adult->children()->detach($child);

    $audit = Audit::where('auditable_type', Adult::class)->where('auditable_id', $adult->id)->where('event', 'updated')->sole();
    expect($audit->new_values['also_known_as'])->toBe(['Davey Jones', 'David Smith']);
    expect($audit->user_id)->toBe($admin->id);
    $this->assertDatabaseHas('audits', ['event' => 'relationship_attached', 'auditable_id' => $adult->id, 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['event' => 'relationship_detached', 'auditable_id' => $adult->id, 'user_id' => $admin->id]);
});

test('permission assignments are audited and credentials are excluded', function (): void {
    $admin = User::factory()->administrator()->create();
    $staff = User::factory()->create();
    $this->actingAs($admin);

    $staff->assignRole('staff');
    $staff->update(['password' => Hash::make('new-private-password'), 'name' => 'Updated staff name']);

    $this->assertDatabaseHas('audits', ['event' => 'role_attached_event', 'auditable_id' => $staff->id, 'user_id' => $admin->id]);
    $audit = Audit::where('auditable_type', User::class)->where('auditable_id', $staff->id)->where('event', 'updated')->sole();
    expect($audit->new_values)->not->toHaveKey('password');
    expect($audit->old_values)->not->toHaveKey('password');
    expect($audit->actor_name)->toBe($admin->name);
});

test('admin photo replacement retains older evidence and private delivery enforces the staff venue', function (): void {
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->administrator()->create();
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
    $oldPhoto = $adult->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('logo', 'local');
    $this->actingAs($admin);

    Livewire::test(EditAdult::class, ['record' => $adult->getRouteKey()])
        ->fillForm(['new_photo' => UploadedFile::fake()->image('new.jpg')])->call('save')->assertHasNoFormErrors();

    expect($adult->fresh()->getMedia('logo'))->toHaveCount(2);
    Storage::disk('local')->assertExists($oldPhoto->getPathRelativeToRoot());
    $newPhoto = $adult->fresh()->getMedia('logo')->last();
    $this->assertDatabaseHas('audits', ['auditable_type' => AuditedMedia::class, 'auditable_id' => $newPhoto->id, 'event' => 'created', 'user_id' => $admin->id]);
    Queue::assertPushed(PerformConversionsJob::class, 2);
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->get(route('staff.media.show', ['site' => $site->uuid, 'media' => $newPhoto]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $staff->staffSites()->detach($site);
    $this->get(route('staff.media.show', ['site' => $site->uuid, 'media' => $newPhoto]))->assertNotFound();
});
