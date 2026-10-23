<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('settings.repositories.database.table');

        Schema::create(is_string($tableName) ? $tableName : 'settings', function (Blueprint $table): void {
            $table->id();

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');

            $table->timestamps();

            $table->unique(['group', 'name']);
        });
    }

    public function down(): void
    {
        $tableName = config('settings.repositories.database.table');

        Schema::dropIfExists(is_string($tableName) ? $tableName : 'settings');
    }
};
