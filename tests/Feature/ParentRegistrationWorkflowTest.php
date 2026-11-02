<?php

use App\Mail\ParentActionMail;
use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\ParentRegistration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ParentRegistrationWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

function parentRegistrationStaff(Site $site, bool $reviewer = true): User
{
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    if ($reviewer) {
        Role::findOrCreate('registration-reviewer', 'web')->givePermissionTo('approve parent registrations');
        $staff->assignRole('registration-reviewer');
    }

    return $staff;
}

function parentRegistrationPayload(): array
{
    return [
        'action' => 'submit', 'name' => 'Jamie Parent', 'also_known_as' => ['Jamie Example'],
        'adults' => [[
            'key' => (string) Str::uuid(), 'name' => 'Morgan Parent', 'date_of_birth' => '1985-05-10',
            'email' => 'morgan@example.test', 'phone' => '07700900123', 'relationship' => 'Parent',
            'also_known_as' => [], 'document_responsible' => true,
        ]],
        'documents' => [],
    ];
}

test('non-list adult input is rejected instead of creating an unusable draft', function (): void {
    $registration = ParentRegistration::factory()->create();
    $payload = parentRegistrationPayload();
    $payload['adults'] = [1 => $payload['adults'][0]];
    $this->withSession(['parent_registration' => [$registration->uuid => $registration->access_key]])
        ->post(app(ParentRegistrationWorkflow::class)->url($registration), $payload)->assertInvalid('adults');
    $this->assertDatabaseCount('adult_accesses', 0);
    expect($registration->fresh()->status)->toBe('invited');
});

test('returned registrations can correct provisional adult DOBs and retain empty aliases', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = parentRegistrationStaff($site);
    $registration = ParentRegistration::factory()->create(['site_id' => $site->id, 'email' => 'morgan@example.test']);
    $workflow = app(ParentRegistrationWorkflow::class);
    $url = $workflow->url($registration);
    $payload = parentRegistrationPayload();
    unset($payload['adults'][0]['also_known_as']);
    $this->withSession(['parent_registration' => [$registration->uuid => $registration->access_key]])->from($url)->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $registration->refresh();
    expect($registration->payload['adults'][0]['also_known_as'])->toBe([]);
    $workflow->returnForCorrections($registration, $staff, 'Please correct your DOB.', $registration->revision);
    $this->get($url)->assertGone();
    $registration->refresh();
    $newUrl = $workflow->url($registration);
    $this->from($newUrl)->post($newUrl, ['action' => 'verify', 'date_of_birth' => '2020-04-12'])->assertRedirect($newUrl);
    $payload['adults'][0]['date_of_birth'] = '1986-05-10';
    $this->from($newUrl)->post($newUrl, $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(AdultAccess::query()->sole()->identityDateOfBirth())->toBe('1986-05-10');
    expect($registration->fresh()->status)->toBe('submitted');
    $this->assertDatabaseCount('children', 0);
    Mail::assertQueued(ParentActionMail::class);
});

test('failed approval removes copied media while preserving the staged photo for retry', function (): void {
    Mail::fake();
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $site = Site::factory()->create();
    $staff = parentRegistrationStaff($site);
    Adult::factory()->hasAttached($site, [], 'sites')->create(['name' => 'Morgan Parent', 'date_of_birth' => '1985-05-10']);
    $registration = ParentRegistration::factory()->create(['site_id' => $site->id, 'email' => 'morgan@example.test']);
    $url = app(ParentRegistrationWorkflow::class)->url($registration);
    $payload = parentRegistrationPayload();
    $payload['new_photo'] = UploadedFile::fake()->image('child.jpg');
    $this->withSession(['parent_registration' => [$registration->uuid => $registration->access_key]])->from($url)->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $before = Storage::disk('local')->allFiles();
    $registration->refresh();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->post(route('staff.registrations.update', ['site' => $site->slug, 'registration' => $registration->uuid]), ['action' => 'approve', 'revision' => $registration->revision, 'is_active' => true])->assertInvalid('adult_choices.0');
    $this->assertDatabaseCount('children', 0);
    $this->assertDatabaseCount('media', 0);
    expect(Storage::disk('local')->allFiles())->toBe($before);
    expect($registration->fresh()->status)->toBe('submitted');
    Mail::assertQueued(ParentActionMail::class);
});

test('a mail failure after submission commits does not delete the saved photo', function (): void {
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
    Storage::fake('local');
    $registration = ParentRegistration::factory()->create(['email' => 'morgan@example.test']);
    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('Simulated mail delivery failure after commit.');
    });
    $payload = parentRegistrationPayload();
    $payload['new_photo'] = UploadedFile::fake()->image('saved-child.jpg');
    $url = app(ParentRegistrationWorkflow::class)->url($registration);
    $this->withSession(['parent_registration' => [$registration->uuid => $registration->access_key]])->from($url)->post($url, $payload)->assertServerError();
    $registration->refresh();
    expect($registration->status)->toBe('submitted');
    Storage::disk('local')->assertExists($registration->payload['photo_path']);
});

test('invitation hides family details until DOB verification and locks after five failures', function (): void {
    Mail::fake();
    $registration = ParentRegistration::factory()->create(['child_name' => 'Hidden Child']);
    $url = app(ParentRegistrationWorkflow::class)->url($registration);
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
        ->component('Parents/Registration')->where('verified', false)->where('payload', null)->where('siteName', null));
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from($url)->post($url, ['action' => 'verify', 'date_of_birth' => '2019-01-01'])->assertInvalid('date_of_birth');
    }
    $this->post($url, ['action' => 'verify', 'date_of_birth' => '2020-04-12'])->assertGone();
    expect($registration->fresh()->failed_attempts)->toBe(5);
    Mail::assertNothingOutgoing();
});

test('parents save drafts and submit once without creating operational people until approval', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = parentRegistrationStaff($site);
    $registration = ParentRegistration::factory()->create(['site_id' => $site->id, 'email' => 'morgan@example.test']);
    $url = app(ParentRegistrationWorkflow::class)->url($registration);
    $this->from($url)->post($url, ['action' => 'verify', 'date_of_birth' => '2020-04-12'])->assertRedirect($url);
    $payload = parentRegistrationPayload();
    $this->from($url)->post($url, [...$payload, 'action' => 'draft'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('parent_registrations', ['id' => $registration->id, 'status' => 'draft']);
    $this->assertDatabaseCount('adults', 0);
    $this->assertDatabaseCount('children', 0);
    $this->from($url)->post($url, $payload)->assertSessionHasNoErrors();
    $registration->refresh();
    expect($registration->status)->toBe('submitted');
    $this->assertDatabaseCount('children', 0);
    $this->assertDatabaseCount('adult_child', 0);
    $this->post($url, $payload)->assertGone();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('closed', true)->where('submitted', true)->where('payload', null));
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->post(route('staff.registrations.update', ['site' => $site->slug, 'registration' => $registration->uuid]), [
            'action' => 'approve', 'revision' => $registration->revision, 'is_active' => true,
        ])->assertSessionHasNoErrors();
    $child = Child::query()->sole();
    expect($child->sites()->sole()->pivot->is_active)->toBeTrue();
    expect($child->adults()->sole()->pivot->authorised_to_collect)->toBeTrue();
    $this->assertDatabaseHas('child_adult_sites', ['child_id' => $child->id, 'is_manager' => true, 'document_responsible' => true]);
    expect(AdultAccess::query()->sole()->adult_id)->toBe(Adult::query()->sole()->id);
    Mail::assertQueued(ParentActionMail::class, fn (ParentActionMail $mail): bool => $mail->heading === 'Registration submitted' && str_contains($mail->messageText, 'link is now invalid'));
});

test('resending preserves a same-recipient draft but never exposes it to a replacement recipient', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = parentRegistrationStaff($site);
    $registration = ParentRegistration::factory()->create(['site_id' => $site->id, 'status' => 'draft', 'payload' => ['name' => 'Private draft']]);
    $workflow = app(ParentRegistrationWorkflow::class);
    $original = $workflow->url($registration);
    $workflow->resend($registration, $staff);
    $registration->refresh();
    expect($registration->payload)->toBe(['name' => 'Private draft']);
    $this->get($original)->assertGone();
    $workflow->resend($registration, $staff, 'replacement@example.test');
    expect($registration->fresh()->payload)->toBeNull();
    Mail::assertQueued(ParentActionMail::class, 2);
});

test('registration requires each adult DOB phone relationship and nominated adults email', function (): void {
    $registration = ParentRegistration::factory()->create(['email' => 'morgan@example.test']);
    $url = app(ParentRegistrationWorkflow::class)->url($registration);
    $payload = parentRegistrationPayload();
    unset($payload['adults'][0]['date_of_birth'], $payload['adults'][0]['phone'], $payload['adults'][0]['relationship']);
    $this->withSession(['parent_registration' => [$registration->uuid => $registration->access_key]])
        ->post($url, $payload)->assertInvalid(['adults.0.date_of_birth', 'adults.0.phone', 'adults.0.relationship']);
    $this->assertDatabaseCount('adult_accesses', 0);
});

test('invitation staff cannot approve and reviewers cannot approve registrations at another site', function (): void {
    $site = Site::factory()->create();
    $staff = parentRegistrationStaff($site, false);
    $registration = ParentRegistration::factory()->create(['site_id' => $site->id, 'status' => 'submitted']);
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->post(route('staff.registrations.update', ['site' => $site->slug, 'registration' => $registration->uuid]), ['action' => 'approve', 'revision' => 1, 'is_active' => true])->assertForbidden();
    $other = Site::factory()->create();
    $otherRegistration = ParentRegistration::factory()->create(['site_id' => $other->id]);
    $this->post(route('staff.registrations.update', ['site' => $site->slug, 'registration' => $otherRegistration->uuid]), ['action' => 'resend'])->assertNotFound();
    $this->assertDatabaseCount('children', 0);
});

test('possible duplicates require staff choices and linking does not overwrite approved details', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = parentRegistrationStaff($site);
    $child = Child::factory()->hasAttached($site, [], 'sites')->create(['name' => 'Jamie Parent', 'date_of_birth' => '2020-04-12']);
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create(['name' => 'Morgan Parent', 'date_of_birth' => '1985-05-10', 'phone' => 'Keep this']);
    $child->adults()->attach($adult, ['relationship' => 'Parent', 'authorised_to_collect' => false]);
    $registration = ParentRegistration::factory()->create(['site_id' => $site->id, 'email' => 'morgan@example.test']);
    $url = app(ParentRegistrationWorkflow::class)->url($registration);
    $this->withSession(['parent_registration' => [$registration->uuid => $registration->access_key]])->from($url)
        ->post($url, parentRegistrationPayload())->assertSessionHasNoErrors();
    $registration->refresh();
    $reviewUrl = route('staff.registrations.update', ['site' => $site->slug, 'registration' => $registration->uuid]);
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->post($reviewUrl, ['action' => 'approve', 'revision' => $registration->revision, 'is_active' => false])->assertInvalid('child_choice');
    $local = ChildAdultSite::factory()->create(['site_id' => $site->id, 'child_id' => $child->id, 'adult_id' => $adult->id, 'is_linked' => false]);
    $this->post($reviewUrl, ['action' => 'approve', 'revision' => $registration->revision, 'is_active' => false, 'child_choice' => (string) $child->id, 'adult_choices' => [(string) $adult->id]])->assertInvalid('adult_choices.0');
    $local->update(['is_linked' => true]);
    $this->post($reviewUrl, ['action' => 'approve', 'revision' => $registration->revision, 'is_active' => false, 'child_choice' => (string) $child->id, 'adult_choices' => [(string) $adult->id]])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('children', 1);
    $this->assertDatabaseCount('adults', 1);
    expect($adult->fresh()->phone)->toBe('Keep this');
    expect($child->adults()->sole()->pivot->authorised_to_collect)->toBeFalse();
    expect($child->sites()->sole()->pivot->is_active)->toBeFalse();
    Mail::assertQueued(ParentActionMail::class);
});
