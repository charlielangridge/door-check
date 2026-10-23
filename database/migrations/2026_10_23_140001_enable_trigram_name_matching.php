<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX adults_name_trgm_index ON adults USING gin (lower(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX children_name_trgm_index ON children USING gin (lower(name) gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS adults_name_trgm_index');
            DB::statement('DROP INDEX IF EXISTS children_name_trgm_index');
        }
    }
};
