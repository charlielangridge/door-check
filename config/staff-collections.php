<?php

return [
    'timezone' => env('COLLECTION_TIMEZONE', 'Europe/London'),
    'day_starts_at' => 6,
    'session_minutes' => 1440,
    'photo_retention_days' => env('COLLECTION_PHOTO_RETENTION_DAYS'),
    'audit_retention_days' => env('COLLECTION_AUDIT_RETENTION_DAYS'),
];
