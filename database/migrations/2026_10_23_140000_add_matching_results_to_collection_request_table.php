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
        Schema::table('collection_request', function (Blueprint $table): void {
            $table->string('status')->default('pending')->index();
            $table->double('matching_threshold')->nullable();

            $table->string('adult_match_status')->default('pending');
            $table->double('adult_match_confidence')->nullable();
            $table->json('adult_match_candidates')->nullable();
            $table->boolean('adult_candidates_truncated')->default(false);
            $table->string('child_match_status')->default('pending');
            $table->double('child_match_confidence')->nullable();
            $table->json('child_match_candidates')->nullable();
            $table->boolean('child_candidates_truncated')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_request', function (Blueprint $table): void {
            $table->dropColumn(['status', 'matching_threshold']);

            foreach (['adult', 'child'] as $person) {
                $table->dropColumn([
                    $person.'_match_status',
                    $person.'_match_confidence',
                    $person.'_match_candidates',
                    $person.'_candidates_truncated',
                ]);
            }
        });
    }
};
