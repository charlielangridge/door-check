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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('staff_site_id')->nullable()->constrained('sites')->restrictOnDelete();
        });

        foreach (['adults', 'children'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->jsonb('also_known_as')->nullable();
            });
        }

        Schema::table('collection_request', function (Blueprint $table): void {
            $table->foreignId('parent_request_id')->nullable()->constrained('collection_request')->restrictOnDelete();
            $table->string('collection_state')->default('needs_checking')->index();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('browser_key_hash', 64)->nullable()->index();
            $table->timestamp('adult_locked_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->text('relationship_override_reason')->nullable();
            $table->text('match_change_reason')->nullable();
            $table->string('photo_verification_reason')->nullable();
            $table->text('photo_verification_notes')->nullable();
            $table->json('unavailable_photos')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->text('cancellation_notes')->nullable();
            $table->boolean('duplicate_child_match')->default(false);
            $table->unsignedBigInteger('duplicate_child_id')->nullable();
        });

        DB::statement("CREATE UNIQUE INDEX collection_one_preparing_child ON collection_request (requested_child_id) WHERE collection_state = 'preparing' AND requested_child_id IS NOT NULL");

        Schema::create('audits', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('user');
            $table->string('actor_name')->nullable();
            $table->string('event');
            $table->morphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->unsignedBigInteger('collection_request_id')->nullable()->index();
            $table->unsignedBigInteger('adult_id')->nullable()->index();
            $table->unsignedBigInteger('child_id')->nullable()->index();
            $table->timestamps();
        });

        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'access staff', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'staff', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        $superAdminId = DB::table('roles')->where('name', 'super-admin')->where('guard_name', 'web')->value('id');
        if ($superAdminId !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $superAdminId, 'permission_id' => $permissionId]);
        }
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS collection_one_preparing_child');
        Schema::dropIfExists('audits');
        Schema::table('collection_request', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_request_id');
            $table->dropColumn(['collection_state', 'revision', 'browser_key_hash', 'adult_locked_at', 'reviewed_at', 'preparing_at', 'collected_at', 'cancelled_at', 'closed_at', 'relationship_override_reason', 'match_change_reason', 'photo_verification_reason', 'photo_verification_notes', 'unavailable_photos', 'cancellation_reason', 'cancellation_notes', 'duplicate_child_match', 'duplicate_child_id']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('staff_site_id'));
        foreach (['adults', 'children'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('also_known_as'));
        }
        $permissionId = DB::table('permissions')->where('name', 'access staff')->where('guard_name', 'web')->value('id');
        $roleId = DB::table('roles')->where('name', 'staff')->where('guard_name', 'web')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_roles')->where('role_id', $roleId)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
