<?php

use App\ChildCollectionState;
use App\Models\CollectionRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('super-admin receives the dedicated all-site permission while ordinary admin does not', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $site = Site::factory()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect($superAdmin->can('process collections at any site'))->toBeTrue();
    expect($superAdmin->can('processCollections', $site))->toBeTrue();
    expect($manager->can('access admin'))->toBeTrue();
    expect($manager->can('processCollections', $site))->toBeFalse();
    $this->actingAs($manager)->withSession(['staff_signed_in_at' => now()->timestamp])->get(route('staff.board', $site->uuid))->assertForbidden();
    $this->actingAs($superAdmin)->get(route('staff.board', $site->uuid))->assertOk();
    $assignedSite = Site::factory()->create();
    $manager->assignRole('staff');
    $manager->staffSites()->attach($assignedSite);
    $this->actingAs($manager)->get(route('staff.board', $assignedSite->uuid))->assertOk();
    $this->get(route('staff.board', $site->uuid))->assertNotFound();
});

test('a staff account chooses between assigned venues and each UUID opens only that venues requests', function (): void {
    $sites = Site::factory()->count(3)->create();
    $staff = User::factory()->hasAttached($sites->take(2), [], 'staffSites')->create();
    $staff->assignRole('staff');
    $first = CollectionRequest::factory()->for($sites[0])->createQuietly();
    $second = CollectionRequest::factory()->for($sites[1])->createQuietly();
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->get(route('staff.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Staff/Sites')->has('sites', 2)->where('sites', fn ($values): bool => collect($values)->pluck('uuid')->sort()->values()->all() === $sites->take(2)->pluck('uuid')->sort()->values()->all()));
    $this->get(route('staff.board', $sites[0]->uuid))->assertOk()->assertInertia(fn (Assert $page) => $page->where('site.uuid', $sites[0]->uuid)->has('requests', 1)->where('requests.0.uuid', $first->uuid));
    $this->get(route('staff.board', $sites[1]->uuid))->assertOk()->assertInertia(fn (Assert $page) => $page->where('site.uuid', $sites[1]->uuid)->has('requests', 1)->where('requests.0.uuid', $second->uuid));
    $this->get(route('staff.board', $sites[2]->uuid))->assertNotFound();
    $this->get(route('staff.board', $sites[0]->id))->assertNotFound();
});

test('one assigned venue opens directly and removing it immediately revokes its staff access', function (): void {
    $site = Site::factory()->create();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $staff->assignRole('staff');
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->get(route('staff.index'))->assertRedirectToRoute('staff.board', $site->uuid);
    $staff->staffSites()->detach($site);
    $this->get(route('staff.board', $site->uuid))->assertNotFound();
    $this->get(route('staff.index'))->assertForbidden();
});

test('a request cannot be used under another venues URL even when both venues are authorised', function (bool $globalPermission): void {
    $sites = Site::factory()->count(2)->create();
    $staff = User::factory()->hasAttached($sites, [], 'staffSites')->create();
    $staff->assignRole('staff');
    if ($globalPermission) {
        $staff->givePermissionTo('process collections at any site');
    }
    $request = CollectionRequest::factory()->for($sites[0])->createQuietly();
    $args = ['site' => $sites[1]->uuid, 'collectionRequest' => $request->uuid];
    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp]);

    $this->getJson(route('staff.collections.data', $args))->assertNotFound();
    $this->postJson(route('staff.collections.update', $args), ['action' => 'cancel', 'revision' => 1, 'cancellation_reason' => 'duplicate'])->assertNotFound();
    expect($request->fresh()->collection_state)->toBe(ChildCollectionState::NeedsChecking);
})->with([false, true]);

test('the dedicated permission grants all-site processing without admin access and revocation takes effect immediately', function (): void {
    $sites = Site::factory()->count(2)->create();
    $staff = User::factory()->globalCollector()->create();
    $request = CollectionRequest::factory()->for($sites[1])->createQuietly();

    $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertRedirectToRoute('staff.index')->assertSessionHas('staff_signed_in_at');
    $this->get(route('staff.board', $sites[0]->uuid))->assertOk();
    $this->postJson(route('staff.collections.update', ['site' => $sites[1]->uuid, 'collectionRequest' => $request]), ['action' => 'cancel', 'revision' => 1, 'cancellation_reason' => 'duplicate'])->assertOk();
    $staff->revokePermissionTo('process collections at any site');
    $this->actingAs($staff->fresh())->get(route('staff.board', $sites[0]->uuid))->assertForbidden();
    $this->assertDatabaseHas('audits', ['event' => 'collection_cancel', 'user_id' => $staff->id, 'site_id' => $sites[1]->id]);
});

test('the migration preserves existing staff assignments and grants only super-admin global access', function (): void {
    $site = Site::factory()->create();
    $staff = User::factory()->hasAttached($site, [], 'staffSites')->create();
    $superAdminRole = Role::findOrCreate('super-admin', 'web');
    $managerRole = Role::findOrCreate('manager', 'web');
    $migration = require database_path('migrations/2026_10_25_104309_add_multiple_staff_sites_and_global_collection_permission.php');

    $migration->down();
    $this->assertDatabaseHas('users', ['id' => $staff->id, 'staff_site_id' => $site->id]);
    $migration->up();

    $this->assertDatabaseHas('staff_site_user', ['user_id' => $staff->id, 'site_id' => $site->id]);
    $permissionId = DB::table('permissions')->where('name', 'process collections at any site')->value('id');
    $this->assertDatabaseHas('role_has_permissions', ['role_id' => $superAdminRole->id, 'permission_id' => $permissionId]);
    $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $managerRole->id, 'permission_id' => $permissionId]);
});

test('rolling back to single-site accounts refuses to discard multiple venue assignments', function (): void {
    $staff = User::factory()->hasAttached(Site::factory()->count(2), [], 'staffSites')->create();
    $migration = require database_path('migrations/2026_10_25_104309_add_multiple_staff_sites_and_global_collection_permission.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($staff->staffSites()->count())->toBe(2);
});
