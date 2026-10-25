<?php

use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource;
use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource\Pages\CreatePermission;
use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource\Pages\EditPermission;
use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource\Pages\ListPermissions;
use Althinect\FilamentSpatieRolesPermissions\Resources\RoleResource;
use Althinect\FilamentSpatieRolesPermissions\Resources\RoleResource\Pages\CreateRole;
use Althinect\FilamentSpatieRolesPermissions\Resources\RoleResource\Pages\EditRole;
use Althinect\FilamentSpatieRolesPermissions\Resources\RoleResource\RelationManager\PermissionRelationManager;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Audit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('role and permission management requires an authenticated admin', function (): void {
    $this->get(RoleResource::getUrl())->assertRedirectToRoute('filament.admin.auth.login');
    $staff = User::factory()->globalCollector()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->get(RoleResource::getUrl())->assertForbidden();
    $this->get(PermissionResource::getUrl())->assertForbidden();
});

test('role and permission policies require admin access for every management operation', function (string $accountState, bool $allowed): void {
    $user = User::factory()->{$accountState}()->create();
    $records = [Role::findByName('staff', 'web'), Permission::findByName('access staff', 'web')];

    foreach ($records as $record) {
        foreach (['viewAny', 'create', 'deleteAny'] as $ability) {
            expect($user->can($ability, $record::class))->toBe($allowed);
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            expect($user->can($ability, $record))->toBe($allowed);
        }
    }
})->with(['admin' => ['administrator', true], 'global collector without admin access' => ['globalCollector', false]]);

test('an admin can create and update roles and their permissions with an audit trail', function (): void {
    $admin = User::factory()->administrator()->create();
    $global = Permission::findByName('process collections at any site', 'web');
    $this->actingAs($admin);

    $this->get(RoleResource::getUrl())->assertOk();
    Livewire::test(CreateRole::class)->fillForm(['name' => 'Collection leads', 'guard_name' => 'web', 'permissions' => [$global->id]])->call('create')->assertHasNoFormErrors();

    $role = Role::where('name', 'Collection leads')->sole();
    expect($role->hasPermissionTo($global))->toBeTrue();
    $this->assertDatabaseHas('audits', ['auditable_type' => Role::class, 'auditable_id' => $role->id, 'event' => 'created', 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_type' => Role::class, 'auditable_id' => $role->id, 'event' => 'relationship_attached', 'user_id' => $admin->id]);

    $collector = User::factory()->create();
    $collector->assignRole($role);
    expect($collector->can('process collections at any site'))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->id])->fillForm(['name' => 'Venue leads', 'permissions' => []])->call('save')->assertHasNoFormErrors();

    expect($collector->fresh()->can('process collections at any site'))->toBeFalse();
    $audit = Audit::where('auditable_type', Role::class)->where('auditable_id', $role->id)->where('event', 'updated')->sole();
    expect($audit->old_values['name'])->toBe('Collection leads');
    expect($audit->new_values['name'])->toBe('Venue leads');
    $this->assertDatabaseHas('audits', ['auditable_type' => Role::class, 'auditable_id' => $role->id, 'event' => 'relationship_detached', 'user_id' => $admin->id]);

    Livewire::test(EditRole::class, ['record' => $role->id])->callAction('delete');

    $this->assertModelMissing($role);
    $this->assertDatabaseHas('audits', ['auditable_type' => Role::class, 'auditable_id' => $role->id, 'event' => 'deleted', 'user_id' => $admin->id]);
});

test('an admin can manage permission definitions and assign roles from the permission form', function (): void {
    $admin = User::factory()->administrator()->create();
    $role = Role::findOrCreate('Collection observers', 'web');
    $collector = User::factory()->create();
    $collector->assignRole($role);
    $this->actingAs($admin);

    $this->get(PermissionResource::getUrl())->assertOk();
    Livewire::test(CreatePermission::class)->fillForm(['name' => 'view collection history', 'guard_name' => 'web', 'roles' => [$role->id]])->call('create')->assertHasNoFormErrors();

    $permission = Permission::where('name', 'view collection history')->sole();
    expect($collector->fresh()->can('view collection history'))->toBeTrue();
    $this->assertDatabaseHas('audits', ['auditable_type' => Permission::class, 'auditable_id' => $permission->id, 'event' => 'created', 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_type' => Permission::class, 'auditable_id' => $permission->id, 'event' => 'relationship_attached', 'user_id' => $admin->id]);

    Livewire::test(EditPermission::class, ['record' => $permission->id])->fillForm(['name' => 'view historic collections', 'roles' => []])->call('save')->assertHasNoFormErrors();

    expect($permission->fresh()->name)->toBe('view historic collections');
    expect($collector->fresh()->can('view historic collections'))->toBeFalse();
    $this->assertDatabaseHas('audits', ['auditable_type' => Permission::class, 'auditable_id' => $permission->id, 'event' => 'updated', 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_type' => Permission::class, 'auditable_id' => $permission->id, 'event' => 'relationship_detached', 'user_id' => $admin->id]);

    Livewire::test(ListPermissions::class)->selectTableRecords([$permission->id])->callAction(TestAction::make('delete')->table()->bulk());

    $this->assertModelMissing($permission);
    $this->assertDatabaseHas('audits', ['auditable_type' => Permission::class, 'auditable_id' => $permission->id, 'event' => 'deleted', 'user_id' => $admin->id]);
});

test('permission relation manager changes are audited and clear cached access', function (): void {
    $admin = User::factory()->administrator()->create();
    $role = Role::findOrCreate('Collection leads', 'web');
    $permission = Permission::findByName('process collections at any site', 'web');
    $collector = User::factory()->create();
    $collector->assignRole($role);
    expect($collector->can('process collections at any site'))->toBeFalse();
    $this->actingAs($admin);

    Livewire::test(PermissionRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->callAction(TestAction::make('Attach Permission')->table(), data: ['recordId' => $permission->id])->assertHasNoFormErrors();

    expect($collector->fresh()->can('process collections at any site'))->toBeTrue();
    $this->assertDatabaseHas('audits', ['auditable_type' => Role::class, 'auditable_id' => $role->id, 'event' => 'relationship_attached', 'user_id' => $admin->id]);

    Livewire::test(PermissionRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->callAction(TestAction::make('detach')->table($permission));

    expect($collector->fresh()->can('process collections at any site'))->toBeFalse();
    $this->assertDatabaseHas('audits', ['auditable_type' => Role::class, 'auditable_id' => $role->id, 'event' => 'relationship_detached', 'user_id' => $admin->id]);
});

test('staff and user role and direct permission changes use the existing assignment audit events', function (): void {
    $admin = User::factory()->administrator()->create();
    $staff = User::factory()->create();
    $site = Site::factory()->create();
    $role = Role::findByName('staff', 'web');
    $permission = Permission::findByName('process collections at any site', 'web');
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $staff->id])
        ->fillForm(['roles' => [(string) $role->id], 'permissions' => [(string) $permission->id], 'staffSites' => [$site->id]])->call('save')->assertHasNoFormErrors();

    expect($staff->fresh()->hasRole('staff'))->toBeTrue();
    expect($staff->fresh()->can('process collections at any site'))->toBeTrue();
    $this->assertDatabaseHas('audits', ['auditable_type' => User::class, 'auditable_id' => $staff->id, 'event' => 'role_attached_event', 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_type' => User::class, 'auditable_id' => $staff->id, 'event' => 'permission_attached_event', 'user_id' => $admin->id]);

    Livewire::test(EditUser::class, ['record' => $staff->id])->fillForm(['roles' => [], 'permissions' => []])->call('save')->assertHasNoFormErrors();

    expect($staff->fresh()->can('process collections at any site'))->toBeFalse();
    $this->assertDatabaseHas('audits', ['auditable_type' => User::class, 'auditable_id' => $staff->id, 'event' => 'role_detached_event', 'user_id' => $admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_type' => User::class, 'auditable_id' => $staff->id, 'event' => 'permission_detached_event', 'user_id' => $admin->id]);
});
