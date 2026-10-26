<?php

/**
 * Venter App Configuration
 * Update database credentials below for your server (e.g. Hostinger / cPanel / Localhost)
 */

return [
    'app_name' => 'Venter App API',
    'app_env' => 'production', // local or production
    'app_url' => 'http://127.0.0.1:8000',

    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'venter_app',
        'user' => 'root',
        'pass' => '',
    ],

    // CORS allowed origins (comma-separated or '*' for public API)
    'cors_origins' => '*',
];
