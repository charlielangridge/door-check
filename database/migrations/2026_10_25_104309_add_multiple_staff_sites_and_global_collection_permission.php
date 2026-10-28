<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('staff_site_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('site_id')->index()->constrained('sites')->cascadeOnDelete();
            $table->primary(['user_id', 'site_id']);
        });
        DB::table('staff_site_user')->insertUsing(['user_id', 'site_id'], DB::table('users')->select('id', 'staff_site_id')->whereNotNull('staff_site_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('staff_site_id'));

        DB::table('permissions')->insertOrIgnore(['name' => 'process collections at any site', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->where('name', 'process collections at any site')->where('guard_name', 'web')->value('id');
        $roleId = DB::table('roles')->where('name', 'super-admin')->where('guard_name', 'web')->value('id');
        if ($roleId !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw_if(DB::table('staff_site_user')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists(), RuntimeException::class, 'Remove additional staff venue assignments before rolling back to single-venue accounts.');
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('staff_site_id')->nullable()->constrained('sites')->restrictOnDelete());
        foreach (DB::table('staff_site_user')->orderBy('user_id')->cursor() as $membership) {
            DB::table('users')->where('id', $membership->user_id)->update(['staff_site_id' => $membership->site_id]);
        }
        Schema::dropIfExists('staff_site_user');
        $permissionId = DB::table('permissions')->where('name', 'process collections at any site')->where('guard_name', 'web')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
