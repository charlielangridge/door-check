<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_child', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->unique(['site_id', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_child');
    }
};
