<?php

/**
 * Venter App Configuration
 * Update database credentials below for your server (e.g. Hostinger / cPanel / Localhost)
 */

return [
    'app_name' => 'Venter App API',
    'app_env' => 'production', // local or production
    'app_url' => 'https://knowlipop.shop',

    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'venter_app',
        'user' => 'root',
        'pass' => '',
    ],

    // CORS allowed origins (comma-separated or '*' for public API)
    'cors_origins' => '*',
];
