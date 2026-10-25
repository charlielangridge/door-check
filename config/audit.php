<?php

use App\Models\Audit;

return [
    'implementation' => Audit::class,
    'console' => true,
    'allowed_array_values' => true,
    'exclude' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'browser_key_hash'],
];
