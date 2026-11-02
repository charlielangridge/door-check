<?php

use App\Mail\ParentActionMail;
use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\ParentRegistration;
use App\Models\Site;
use App\Models\User;
use App\Services\AdultPortalAccess;
use App\Services\ParentDocuments;
use App\Services\ParentRegistrationWorkflow;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('changed documents preserve acceptance evidence and block registration approval until every nominee accepts', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = User::factory()->create();
    $access = AdultAccess::factory()->create();
    $other = AdultAccess::factory()->create();
    $documents = app(ParentDocuments::class);
    $first = $documents->publish($site, $staff, ['title' => 'Terms', 'markdown' => 'Version one', 'requires_acceptance' => true]);
    $documents->accept($access, $site, [$first->id]);
    $registration = ParentRegistration::factory()->create([
        'site_id' => $site->id, 'status' => 'submitted', 'adult_access_id' => $access->id,
        'payload' => ['name' => 'Jamie', 'adults' => [
            ['name' => $access->name, 'email' => $access->email, 'access_id' => $access->id, 'document_responsible' => true],
            ['name' => $other->name, 'email' => $other->email, 'access_id' => $other->id, 'document_responsible' => true],
        ]],
    ]);
    expect($documents->outstanding($registration))->toHaveCount(1);
    expect(fn () => app(ParentRegistrationWorkflow::class)->approve($registration, $staff, ['revision' => 1, 'is_active' => true]))
        ->toThrow(ValidationException::class);
    $documents->accept($other, $site, [$first->id]);
    expect($documents->outstanding($registration))->toBe([]);
    $second = $documents->publish($site, $staff, ['document_id' => $first->site_document_id, 'title' => 'Terms', 'markdown' => 'Version two', 'requires_acceptance' => true]);
    expect($documents->outstanding($registration))->toHaveCount(2);
    $this->assertDatabaseCount('document_acceptances', 2);
    expect($first->fresh()->markdown)->toBe('Version one');
    expect(fn () => $documents->accept($access, $site, [$first->id]))->toThrow(ValidationException::class);
    $documents->accept($access, $site, [$second->id]);
    $documents->accept($access, $site, [$second->id]);
    $this->assertDatabaseCount('document_acceptances', 3);
    Mail::assertNothingOutgoing();
});

test('document requests go once to nominated adults with active children and exclude collectors and inactive-only families', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = User::factory()->create();
    $documents = app(ParentDocuments::class);
    $documents->publish($site, $staff, ['title' => 'Terms', 'markdown' => 'Terms', 'requires_acceptance' => true]);
    $eligible = null;
    foreach ([[true, true], [false, true], [true, false]] as [$active, $responsible]) {
        $adult = Adult::factory()->hasAttached($site, [], 'sites')->create();
        $access = AdultAccess::factory()->create(['adult_id' => $adult->id]);
        $child = Child::factory()->hasAttached($site, ['is_active' => $active], 'sites')->create();
        $child->adults()->attach($adult, ['relationship' => 'Parent']);
        ChildAdultSite::factory()->create(['site_id' => $site->id, 'child_id' => $child->id, 'adult_id' => $adult->id, 'document_responsible' => $responsible]);
        if ($active && $responsible) {
            $eligible = $access;
            $sibling = Child::factory()->hasAttached($site, [], 'sites')->create();
            $sibling->adults()->attach($adult, ['relationship' => 'Parent']);
            ChildAdultSite::factory()->create(['site_id' => $site->id, 'child_id' => $sibling->id, 'adult_id' => $adult->id, 'document_responsible' => true]);
        }
    }
    expect($documents->sendOutstanding($site))->toBe(1);
    Mail::assertQueued(ParentActionMail::class, fn (ParentActionMail $mail): bool => $mail->hasTo($eligible->email));
    Mail::assertQueuedCount(1);
});

test('document-only links require the recipient DOB and become unusable after acceptance', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    $staff = User::factory()->create();
    $profile = AdultAccess::factory()->create();
    $version = app(ParentDocuments::class)->publish($site, $staff, ['title' => 'Private terms', 'markdown' => 'Required text', 'requires_acceptance' => true]);
    ParentRegistration::factory()->create(['site_id' => $site->id, 'status' => 'submitted', 'payload' => ['name' => 'Jamie', 'adults' => [
        ['name' => $profile->name, 'email' => $profile->email, 'access_id' => $profile->id, 'document_responsible' => true],
    ]]]);
    $link = app(AdultPortalAccess::class)->sendLink($profile, 'documents', $site->id);
    $url = URL::temporarySignedRoute('adult-links.show', $link->expires_at, ['link' => $link->uuid]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('verified', false)->where('documents', []));
    $this->from($url)->post($url, ['date_of_birth' => '2020-04-12'])->assertInvalid('date_of_birth');
    $this->from($url)->post($url, ['date_of_birth' => '1985-05-10'])->assertRedirect($url);
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('verified', true)->where('documents.0.title', 'Private terms'));
    $this->from($url)->post($url, ['documents' => [$version->id]])->assertRedirectToRoute('parent-action.complete');
    $this->post($url, ['documents' => [$version->id]])->assertGone();
    $this->assertDatabaseHas('document_acceptances', ['adult_access_id' => $profile->id, 'document_version_id' => $version->id, 'actor_email' => $profile->email]);
    Mail::assertQueuedCount(1);
});

test('document publication requires its own permission and strips unsafe Markdown', function (): void {
    $site = Site::factory()->create();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])
        ->post(route('staff.documents.store', $site->slug), ['action' => 'publish'])->assertForbidden();
    $this->assertDatabaseCount('site_documents', 0);
    $documents = app(ParentDocuments::class);
    $documents->publish($site, $staff, ['title' => 'Terms', 'markdown' => '<script>alert(1)</script> [unsafe](javascript:alert(1))', 'requires_acceptance' => true]);
    $html = $documents->display($site)[0]['html'];
    expect($html)->not->toContain('<script>')->not->toContain('href="javascript:');
    $this->get(route('parent-documents.show', 99999))->assertNotFound();
});
