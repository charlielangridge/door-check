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
        Schema::create('collection_request', function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->index('site_id');
            $table->dateTime('requested_at');
            $table->string('requester_name_text');
            $table->string('requested_child_text');
            $table->decimal('requester_latitude', 10, 8)->nullable();
            $table->decimal('requester_longitude', 11, 8)->nullable();
            $table->foreignId('adult_id')->nullable()->constrained('adults');
            $table->foreignId('requested_child_id')->nullable()->constrained('children');

            // Photo

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_request');
    }
};
