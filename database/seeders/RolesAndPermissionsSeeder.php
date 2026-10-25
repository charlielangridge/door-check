<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        Permission::firstOrCreate(['name' => 'access admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'access staff', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'process collections at any site', 'guard_name' => 'web']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->givePermissionTo('access admin');

        $role = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $role->givePermissionTo('access staff');

        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::all());
    }
}
