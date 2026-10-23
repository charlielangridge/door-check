<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('collection_matching.automatic_matching_threshold', 95.0);
    }

    public function down(): void
    {
        $this->migrator->delete('collection_matching.automatic_matching_threshold');
    }
};
