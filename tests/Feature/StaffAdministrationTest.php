<?php

use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\RelationManagers\StaffUsersRelationManager;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Site;
use App\Models\User;
use App\Services\StaffVenueAssignments;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('an admin can create a working staff account directly within its venue', function (): void {
    $admin = User::factory()->administrator()->create();
    $site = Site::factory()->create();
    $this->actingAs($admin);

    $relationKey = array_search(StaffUsersRelationManager::class, SiteResource::getRelations(), true);
    Livewire::withQueryParams(['relation' => (string) $relationKey])->test(EditSite::class, ['record' => $site->getRouteKey()])->assertOk()->assertSeeLivewire(StaffUsersRelationManager::class);

    Livewire::test(StaffUsersRelationManager::class, ['ownerRecord' => $site, 'pageClass' => EditSite::class])
        ->assertActionExists(TestAction::make('create')->table(), fn (Action $action): bool => $action->getUrl() === null && $action->hasModal())
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Jane Staff', 'email' => 'jane.staff@example.test', 'password' => 'a-staff-password-123', 'email_verified_at' => now()->toDateTimeString()])
        ->assertHasNoFormErrors()->assertNotified();

    $staff = User::where('email', 'jane.staff@example.test')->sole();
    expect($staff)->staff_site_id->toBe($site->id)->hasVerifiedEmail()->toBeTrue()->hasRole('staff')->toBeTrue();
    expect(Hash::check('a-staff-password-123', $staff->password))->toBeTrue();
    $this->assertDatabaseHas('audits', ['auditable_type' => User::class, 'auditable_id' => $staff->id, 'event' => 'created', 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_id' => $staff->id, 'event' => 'role_attached_event', 'user_id' => $admin->id]);
    $this->post(route('logout'));
    $this->post('/login', ['email' => $staff->email, 'password' => 'a-staff-password-123'])->assertRedirectToRoute('staff.board');
    $this->get(route('staff.board'))->assertOk();
});

test('an admin can assign and unassign existing staff without removing their account', function (): void {
    $admin = User::factory()->administrator()->create();
    $site = Site::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $this->actingAs($admin);

    Livewire::test(StaffUsersRelationManager::class, ['ownerRecord' => $site, 'pageClass' => EditSite::class])
        ->callAction(TestAction::make('associate')->table(), data: ['recordId' => $staff->id])
        ->assertHasNoFormErrors()->assertNotified()->assertCanSeeTableRecords([$staff]);
    expect($staff->fresh()->staff_site_id)->toBe($site->id);

    Livewire::test(StaffUsersRelationManager::class, ['ownerRecord' => $site, 'pageClass' => EditSite::class])
        ->callAction(TestAction::make('dissociate')->table($staff))->assertNotified()->assertCanNotSeeTableRecords([$staff]);
    expect($staff->fresh())->staff_site_id->toBeNull()->hasRole('staff')->toBeTrue();
    $this->assertModelExists($staff);
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->get(route('staff.board'))->assertForbidden();
});

test('venue assignment rejects another venues account and nonstaff accounts', function (bool $alreadyAssigned): void {
    $admin = User::factory()->administrator()->create();
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = User::factory()->create(['staff_site_id' => $alreadyAssigned ? $otherSite->id : null]);
    if ($alreadyAssigned) {
        $user->assignRole('staff');
    }
    $this->actingAs($admin);

    Livewire::test(StaffUsersRelationManager::class, ['ownerRecord' => $site, 'pageClass' => EditSite::class])
        ->callAction(TestAction::make('associate')->table(), data: ['recordId' => $user->id]);
    expect($user->fresh()->staff_site_id)->toBe($alreadyAssigned ? $otherSite->id : null);
})->with([true, false]);

test('the users form creates and reassigns staff while preserving a blank edited password', function (): void {
    $admin = User::factory()->administrator()->create();
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $this->actingAs($admin);

    Livewire::test(CreateUser::class)->fillForm(['name' => 'Venue Staff', 'email' => 'venue.staff@example.test', 'password' => 'another-password-123', 'roles' => [Role::findByName('staff', 'web')->id], 'staff_site_id' => $site->id])->call('create')->assertHasNoFormErrors();
    $staff = User::where('email', 'venue.staff@example.test')->sole();
    $password = $staff->password;
    expect($staff)->staff_site_id->toBe($site->id)->hasRole('staff')->toBeTrue();

    Livewire::test(EditUser::class, ['record' => $staff->id])->fillForm(['staff_site_id' => $otherSite->id, 'password' => ''])->call('save')->assertHasNoFormErrors();
    expect($staff->fresh())->staff_site_id->toBe($otherSite->id)->password->toBe($password);
});

test('creating staff requires a venue and staff cannot open admin account management', function (): void {
    $admin = User::factory()->administrator()->create();
    $this->actingAs($admin);
    Livewire::test(CreateUser::class)->fillForm(['name' => 'Unassigned Staff', 'email' => 'unassigned@example.test', 'password' => 'another-password-123', 'roles' => [Role::findByName('staff', 'web')->id], 'staff_site_id' => null])->call('create')->assertHasFormErrors(['staff_site_id' => 'required']);
    $this->assertDatabaseMissing('users', ['email' => 'unassigned@example.test']);

    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->get('/admin/users/create')->assertForbidden();
});

test('a stale assignment or unassignment cannot move staff after another admin has assigned them', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $firstVenue = Site::factory()->create();
    $secondVenue = Site::factory()->create();
    $staleStaff = $staff->fresh();
    $assignments = app(StaffVenueAssignments::class);

    expect($assignments->assign($staff, $firstVenue))->toBeTrue();
    expect($assignments->assign($staleStaff, $secondVenue))->toBeFalse();
    expect($assignments->unassign($staleStaff, $secondVenue))->toBeFalse();
    expect($staff->fresh()->staff_site_id)->toBe($firstVenue->id);
});
