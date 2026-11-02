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
        Schema::table('adults', function (Blueprint $table): void {
            $table->string('email')->nullable()->unique();
            $table->string('phone', 50)->nullable();
        });
        Schema::table('adult_child', function (Blueprint $table): void {
            $table->boolean('authorised_to_collect')->default(true);
        });
        Schema::table('site_child', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_approved')->default(true);
        });
        Schema::table('site_adult', function (Blueprint $table): void {
            $table->boolean('access_suspended')->default(false);
        });
        Schema::create('adult_accesses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('adult_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('email')->unique();
            $table->string('name');
            $table->date('date_of_birth');
            $table->unsignedInteger('session_version')->default(1);
            $table->timestamps();
        });
        Schema::create('parent_registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('access_key');
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('adult_access_id')->nullable()->constrained('adult_accesses')->restrictOnDelete();
            $table->foreignId('child_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('child_name');
            $table->date('child_date_of_birth');
            $table->string('email');
            $table->string('status')->default('invited');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('correction_message')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['site_id', 'status']);
        });
        Schema::create('child_adult_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adult_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_manager')->default(false);
            $table->boolean('is_linked')->default(true);
            $table->boolean('document_responsible')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'child_id', 'adult_id']);
        });
        Schema::create('site_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('markdown')->nullable();
            $table->string('pdf_path')->nullable();
            $table->boolean('requires_acceptance')->default(true);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_document_id', 'number']);
        });
        Schema::create('document_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adult_access_id')->constrained('adult_accesses')->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->string('actor_name');
            $table->string('actor_email');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->unique(['adult_access_id', 'document_version_id']);
        });
        Schema::create('adult_access_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('adult_access_id')->constrained('adult_accesses')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('email');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamps();
        });
        Schema::create('parent_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adult_access_id')->constrained('adult_accesses')->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('adult_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->json('payload');
            $table->json('original')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_message')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status']);
        });

        foreach (['approve parent registrations', 'manage site documents'] as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');
            $superAdmin = DB::table('roles')->where('name', 'super-admin')->where('guard_name', 'web')->value('id');
            if ($superAdmin !== null) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $superAdmin, 'permission_id' => $permissionId]);
            }
        }
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['parent_updates', 'adult_access_links', 'document_acceptances', 'document_versions', 'site_documents', 'child_adult_sites', 'parent_registrations', 'adult_accesses'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('site_adult', fn (Blueprint $table) => $table->dropColumn('access_suspended'));
        Schema::table('site_child', fn (Blueprint $table) => $table->dropColumn(['is_active', 'is_approved']));
        Schema::table('adult_child', fn (Blueprint $table) => $table->dropColumn('authorised_to_collect'));
        Schema::table('adults', fn (Blueprint $table) => $table->dropColumn(['email', 'phone']));
    }
};
