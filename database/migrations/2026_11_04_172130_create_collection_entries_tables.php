<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('collection_entry_displays', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('collection_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('collection_entry_display_id')->constrained()->cascadeOnDelete();
            $table->timestamp('token_expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('form_expires_at')->nullable();
            $table->string('browser_key_hash', 64)->nullable();
            $table->foreignId('collection_request_id')->nullable()->unique()->constrained('collection_request')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['collection_entry_display_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_entries');
        Schema::dropIfExists('collection_entry_displays');
    }
};
