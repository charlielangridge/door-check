<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adult_child', function (Blueprint $table): void {
            $table->foreignId('adult_id');
            $table->foreignId('child_id');
            $table->string('relationship');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adult_child');
    }
};
