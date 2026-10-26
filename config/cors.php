<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // Configure VENTER_FRONTEND_ORIGINS as comma-separated exact origins in the host environment.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('VENTER_FRONTEND_ORIGINS', 'http://localhost:3000,http://localhost:3001,http://127.0.0.1:3000'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
