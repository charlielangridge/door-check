<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class CollectionMatchingSettings extends Settings
{
    public float $automatic_matching_threshold;

    public static function group(): string
    {
        return 'collection_matching';
    }
}
