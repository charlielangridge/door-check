<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_adult', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('adult_id')->constrained('adults')->cascadeOnDelete();
            $table->unique(['site_id', 'adult_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_adult');
    }
};
