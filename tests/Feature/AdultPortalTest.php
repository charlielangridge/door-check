<?php

use App\Mail\ParentActionMail;
use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\AdultAccessLink;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AdultPortalAccess;
use App\Services\ParentDetailsUpdates;
use App\Services\ParentDocuments;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

function adultPortalFixture(bool $manager = false): array
{
    $site = Site::factory()->create();
    $adult = Adult::factory()->hasAttached($site, [], 'sites')->create(['email' => 'portal@example.test', 'phone' => '07700900123']);
    $access = AdultAccess::factory()->create(['adult_id' => $adult->id, 'email' => $adult->email, 'date_of_birth' => $adult->date_of_birth]);
    $child = Child::factory()->hasAttached($site, [], 'sites')->create(['date_of_birth' => '2020-01-01']);
    $child->adults()->attach($adult, ['relationship' => 'Parent']);
    ChildAdultSite::factory()->create(['site_id' => $site->id, 'adult_id' => $adult->id, 'child_id' => $child->id, 'is_manager' => $manager]);
    $session = ['adult_portal' => ['id' => $access->id, 'version' => $access->session_version, 'last_activity' => now()->timestamp]];

    return compact('site', 'adult', 'access', 'child', 'session');
}

test('revoked management rights hide old registration and proposal details and exclude other-site adults', function (): void {
    $fixture = adultPortalFixture(true);
    $otherSite = Site::factory()->create();
    $fixture['child']->sites()->attach($otherSite);
    $otherAdult = Adult::factory()->hasAttached($otherSite, [], 'sites')->create(['email' => 'other-site-secret@example.test']);
    $fixture['child']->adults()->attach($otherAdult, ['relationship' => 'Parent']);
    ParentRegistration::factory()->create([
        'site_id' => $fixture['site']->id, 'child_id' => $fixture['child']->id, 'adult_access_id' => $fixture['access']->id,
        'status' => 'approved', 'payload' => ['name' => $fixture['child']->name, 'adults' => [['name' => 'Private other adult', 'email' => 'secret@example.test']]],
    ]);
    ParentUpdate::factory()->create(['site_id' => $fixture['site']->id, 'child_id' => $fixture['child']->id, 'adult_access_id' => $fixture['access']->id, 'kind' => 'child', 'payload' => ['name' => 'Proposed child name', 'date_of_birth' => '2020-01-01']]);
    $this->withSession($fixture['session'])->get(route('adult-portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('sites.0.children.0.adults', 1)->where('registrations.0.submitted_details', null));
    ChildAdultSite::query()->where('site_id', $fixture['site']->id)->where('adult_id', $fixture['adult']->id)->update(['is_manager' => false]);
    $this->get(route('adult-portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('sites.0.children.0.date_of_birth')->missing('sites.0.children.0.adults')->where('updates.0.payload', []));
});

test('detaching a shared relationship removes stale management and document authority', function (): void {
    $fixture = adultPortalFixture(true);
    ChildAdultSite::query()->where('adult_id', $fixture['adult']->id)->update(['document_responsible' => true]);
    $fixture['child']->adults()->detach($fixture['adult']->id);
    expect(app(ParentDocuments::class)->recipients($fixture['site']))->toHaveCount(0);
    $this->withSession($fixture['session'])->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'child', 'child_id' => $fixture['child']->id, 'name' => 'Unauthorised', 'date_of_birth' => '2020-01-01',
    ])->assertForbidden();
    $this->assertDatabaseCount('parent_updates', 0);
});

test('removing an adult at one nursery preserves their other nursery association and management rights', function (): void {
    $fixture = adultPortalFixture(true);
    $otherSite = Site::factory()->create();
    $fixture['child']->sites()->attach($otherSite);
    $other = Adult::factory()->hasAttached($fixture['site'], [], 'sites')->hasAttached($otherSite, [], 'sites')->create();
    $fixture['child']->adults()->attach($other, ['relationship' => 'Parent']);
    $otherProfile = AdultAccess::factory()->create(['adult_id' => $other->id]);
    ChildAdultSite::factory()->create(['site_id' => $otherSite->id, 'adult_id' => $other->id, 'child_id' => $fixture['child']->id, 'is_manager' => true, 'document_responsible' => true]);
    ParentUpdate::factory()->create(['site_id' => $fixture['site']->id, 'adult_id' => $other->id, 'child_id' => $fixture['child']->id, 'adult_access_id' => $fixture['access']->id, 'kind' => 'adult', 'status' => 'approved', 'payload' => ['email' => 'previous-private-contact@example.test']]);
    $this->withSession($fixture['session'])->from(route('adult-portal.index'))->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'remove_adult', 'child_id' => $fixture['child']->id, 'adult_id' => $other->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    app(ParentDetailsUpdates::class)->review(ParentUpdate::query()->where('kind', 'remove_adult')->sole(), User::factory()->create(), ['action' => 'approve_update']);
    $this->assertDatabaseHas('adult_child', ['adult_id' => $other->id, 'child_id' => $fixture['child']->id]);
    $this->assertDatabaseHas('child_adult_sites', ['site_id' => $fixture['site']->id, 'child_id' => $fixture['child']->id, 'adult_id' => $other->id, 'is_linked' => false]);
    expect(app(ParentDetailsUpdates::class)->canManage($otherProfile, $otherSite, $fixture['child']))->toBeTrue();
    $this->get(route('adult-portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('sites.0.children.0.adults', 1)->where('updates.1.payload', []));
});

test('multipart omission of empty aliases clears them in a proposed update', function (): void {
    Mail::fake();
    $fixture = adultPortalFixture();
    $fixture['adult']->update(['also_known_as' => ['Old alias']]);
    $this->withSession($fixture['session'])->from(route('adult-portal.index'))->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'adult', 'adult_id' => $fixture['adult']->id, 'name' => $fixture['adult']->name,
        'date_of_birth' => $fixture['adult']->date_of_birth->toDateString(), 'email' => $fixture['adult']->email, 'phone' => '07700900123',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(ParentUpdate::query()->sole()->payload['also_known_as'])->toBe([]);
    Mail::assertNothingOutgoing();
});

test('a photo proposal cannot overwrite a newer staff photo', function (): void {
    Mail::fake();
    Storage::fake('local');
    Queue::fake([PerformConversionsJob::class]);
    $fixture = adultPortalFixture(true);
    $fixture['child']->addMedia(UploadedFile::fake()->image('original.jpg'))->toMediaCollection('photo', 'local');
    $this->withSession($fixture['session'])->from(route('adult-portal.index'))->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'child', 'child_id' => $fixture['child']->id, 'name' => $fixture['child']->name, 'date_of_birth' => '2020-01-01',
        'new_photo' => UploadedFile::fake()->image('proposed.jpg'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $newer = $fixture['child']->addMedia(UploadedFile::fake()->image('newer-staff.jpg'))->toMediaCollection('photo', 'local');
    expect(fn () => app(ParentDetailsUpdates::class)->review(ParentUpdate::query()->sole(), User::factory()->create(), ['action' => 'approve_update']))
        ->toThrow(ValidationException::class);
    expect($fixture['child']->fresh()->getMedia('photo')->sortBy('id')->last()->id)->toBe($newer->id);
    expect(ParentUpdate::query()->sole()->status)->toBe('pending');
    Mail::assertNothingOutgoing();
});

test('a mail failure after an email-change proposal commits preserves its staged photo', function (): void {
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
    Storage::fake('local');
    $fixture = adultPortalFixture();
    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('Simulated mail delivery failure after commit.');
    });
    $this->withSession($fixture['session'])->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'adult', 'adult_id' => $fixture['adult']->id, 'name' => $fixture['adult']->name,
        'date_of_birth' => $fixture['adult']->date_of_birth->toDateString(), 'email' => 'changed@example.test', 'phone' => '07700900123',
        'new_photo' => UploadedFile::fake()->image('saved-proposal.jpg'),
    ])->assertServerError();
    $update = ParentUpdate::query()->sole();
    expect($update->status)->toBe('pending');
    Storage::disk('local')->assertExists($update->payload['photo_path']);
});

test('permanent return link does not expose family details and login links are single use and session bound', function (): void {
    Mail::fake();
    $fixture = adultPortalFixture();
    $access = $fixture['access'];
    $portal = app(AdultPortalAccess::class);
    $this->get($portal->returnUrl($access))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Parents/Access')->where('mode', 'request')->missing('adult')->missing('children'));
    $this->get(route('adult-portal.index'))->assertUnauthorized();
    $link = $portal->sendLink($access);
    $url = URL::temporarySignedRoute('adult-links.show', $link->expires_at, ['link' => $link->uuid]);
    $this->get($url)->assertOk();
    $this->post($url)->assertRedirectToRoute('adult-portal.index');
    $this->get(route('adult-portal.index'))->assertOk();
    $this->post($url)->assertGone();
    $this->travel(31)->minutes();
    $this->get(route('adult-portal.index'))->assertUnauthorized();
    Mail::assertQueuedCount(1);
});

test('collectors cannot see child DOB or other adult details and cannot propose child changes', function (): void {
    $fixture = adultPortalFixture();
    $other = Adult::factory()->hasAttached($fixture['site'], [], 'sites')->create();
    $fixture['child']->adults()->attach($other, ['relationship' => 'Grandparent']);
    $this->withSession($fixture['session'])->get(route('adult-portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sites.0.children.0.can_manage', false)->missing('sites.0.children.0.date_of_birth')->missing('sites.0.children.0.adults'));
    $this->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'child', 'child_id' => $fixture['child']->id, 'name' => 'Unauthorised change', 'date_of_birth' => '2020-01-01',
    ])->assertForbidden();
    $this->assertDatabaseCount('parent_updates', 0);
});

test('manager child updates remain pending until staff approve and adult DOBs stay private', function (): void {
    Mail::fake();
    $fixture = adultPortalFixture(true);
    $other = Adult::factory()->hasAttached($fixture['site'], [], 'sites')->create();
    $fixture['child']->adults()->attach($other, ['relationship' => 'Grandparent']);
    $this->withSession($fixture['session'])->get(route('adult-portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sites.0.children.0.can_manage', true)->where('sites.0.children.0.date_of_birth', '2020-01-01')
        ->missing('sites.0.children.0.adults.1.date_of_birth'));
    $this->from(route('adult-portal.index'))->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'child', 'child_id' => $fixture['child']->id, 'name' => 'Reviewed child name', 'date_of_birth' => '2020-01-01', 'also_known_as' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($fixture['child']->fresh()->name)->not->toBe('Reviewed child name');
    $update = ParentUpdate::query()->sole();
    $staff = User::factory()->hasAttached($fixture['site'], [], 'staffSites')->create();
    $staff->assignRole('staff');
    Role::findOrCreate('portal-reviewer', 'web')->givePermissionTo('approve parent registrations');
    $staff->assignRole('portal-reviewer');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->post(route('staff.parent-updates.review', ['site' => $fixture['site']->slug, 'update' => $update->id]), ['action' => 'approve_update'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($fixture['child']->fresh()->name)->toBe('Reviewed child name');
    Mail::assertNothingOutgoing();
});

test('site suspension immediately removes that site from an existing adult session without changing collection permission', function (): void {
    $fixture = adultPortalFixture(true);
    $fixture['adult']->sites()->updateExistingPivot($fixture['site']->id, ['access_suspended' => true]);
    $this->withSession($fixture['session'])->get(route('adult-portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('sites', []));
    $this->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'adult', 'adult_id' => $fixture['adult']->id,
    ])->assertForbidden();
    expect($fixture['child']->adults()->sole()->pivot->authorised_to_collect)->toBeTrue();
});

test('email changes require new address verification and staff approval before old access is revoked', function (): void {
    Mail::fake();
    $fixture = adultPortalFixture();
    $this->withSession($fixture['session'])->from(route('adult-portal.index'))->post(route('adult-portal.updates.store', $fixture['site']->slug), [
        'kind' => 'adult', 'adult_id' => $fixture['adult']->id, 'name' => $fixture['adult']->name,
        'date_of_birth' => $fixture['adult']->date_of_birth->toDateString(), 'email' => 'new-email@example.test', 'phone' => '07700900123', 'also_known_as' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($fixture['access']->fresh()->email)->toBe('portal@example.test');
    $staff = User::factory()->hasAttached($fixture['site'], [], 'staffSites')->create();
    $staff->assignRole('staff');
    Role::findOrCreate('email-reviewer', 'web')->givePermissionTo('approve parent registrations');
    $staff->assignRole('email-reviewer');
    $update = ParentUpdate::query()->sole();
    $reviewUrl = route('staff.parent-updates.review', ['site' => $fixture['site']->slug, 'update' => $update->id]);
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->post($reviewUrl, ['action' => 'approve_update'])->assertInvalid('update');
    $link = AdultAccessLink::query()->where('purpose', 'email_change')->sole();
    $url = URL::temporarySignedRoute('adult-links.show', $link->expires_at, ['link' => $link->uuid]);
    $this->post($url)->assertRedirectToRoute('parent-action.complete');
    $this->post($reviewUrl, ['action' => 'approve_update'])->assertRedirect()->assertSessionHasNoErrors();
    expect($fixture['access']->fresh()->email)->toBe('new-email@example.test');
    $this->withSession($fixture['session'])->get(route('adult-portal.index'))->assertUnauthorized();
    Mail::assertQueued(ParentActionMail::class, fn (ParentActionMail $mail): bool => $mail->hasTo('new-email@example.test'));
});
