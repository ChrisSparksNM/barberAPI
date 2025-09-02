<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Production Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains production-specific configurations that override
    | default Laravel settings for optimal performance and security.
    |
    */

    'security' => [
        'force_https' => true,
        'hsts_max_age' => 31536000, // 1 year
        'content_security_policy' => [
            'default-src' => "'self'",
            'script-src' => "'self' 'unsafe-inline' https://js.stripe.com",
            'style-src' => "'self' 'unsafe-inline'",
            'img-src' => "'self' data: https:",
            'connect-src' => "'self' https://api.stripe.com",
            'frame-src' => "https://js.stripe.com https://hooks.stripe.com",
        ],
    ],

    'performance' => [
        'opcache_enabled' => true,
        'config_cache' => true,
        'route_cache' => true,
        'view_cache' => true,
        'query_cache' => true,
    ],

    'monitoring' => [
        'error_reporting' => E_ALL & ~E_DEPRECATED & ~E_STRICT,
        'log_level' => 'error',
        'slow_query_threshold' => 2000, // milliseconds
    ],

    'rate_limiting' => [
        'api_requests_per_minute' => 60,
        'login_attempts_per_minute' => 5,
        'password_reset_per_hour' => 3,
    ],
];