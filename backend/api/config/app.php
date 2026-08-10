<?php

declare(strict_types=1);

/**
 * ConnectPro Application Configuration
 *
 * File: api/config/app.php
 *
 * This file returns application-level settings only.
 * Database credentials and other secrets must come from environment
 * variables and must never be committed to source control.
 */

if (!function_exists('connectpro_env')) {
    /**
     * Read and normalize an environment variable.
     *
     * @return mixed
     */
    function connectpro_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('connectpro_env_int')) {
    function connectpro_env_int(string $key, int $default): int
    {
        $value = connectpro_env($key, $default);

        return filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : $default;
    }
}

if (!function_exists('connectpro_env_list')) {
    /**
     * Parse a comma-separated environment variable into a clean list.
     *
     * @return list<string>
     */
    function connectpro_env_list(string $key, array $default = []): array
    {
        $value = connectpro_env($key);

        if (!is_string($value) || trim($value) === '') {
            return array_values($default);
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }
}

$environment = (string) connectpro_env('APP_ENV', 'production');
$isProduction = $environment === 'production';
$appUrl = rtrim((string) connectpro_env(
    'APP_URL',
    'http://localhost/connectpro'
), '/');

$defaultAllowedOrigins = $isProduction
    ? []
    : [
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:3000',
    ];

return [
    'name' => (string) connectpro_env('APP_NAME', 'ConnectPro'),
    'environment' => $environment,
    'debug' => (bool) connectpro_env('APP_DEBUG', false),
    'timezone' => (string) connectpro_env('APP_TIMEZONE', 'Asia/Bangkok'),
    'locale' => (string) connectpro_env('APP_LOCALE', 'th_TH'),
    'fallback_locale' => (string) connectpro_env(
        'APP_FALLBACK_LOCALE',
        'en_US'
    ),
    'url' => $appUrl,
    'api_url' => rtrim((string) connectpro_env(
        'API_URL',
        $appUrl . '/api'
    ), '/'),
    'frontend_url' => rtrim((string) connectpro_env(
        'FRONTEND_URL',
        $appUrl . '/frontend'
    ), '/'),
    'version' => (string) connectpro_env('APP_VERSION', '1.0.0'),

    'paths' => [
        'root' => dirname(__DIR__, 2),
        'api' => dirname(__DIR__),
        'storage' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
        'logs' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs',
        'uploads' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'uploads',
        'temp' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'temp',
    ],

    'session' => [
        'name' => (string) connectpro_env(
            'SESSION_NAME',
            'connectpro_session'
        ),
        'lifetime_minutes' => connectpro_env_int(
            'SESSION_LIFETIME_MINUTES',
            30
        ),
        'idle_timeout_minutes' => connectpro_env_int(
            'SESSION_IDLE_TIMEOUT_MINUTES',
            20
        ),
        'regenerate_interval_minutes' => connectpro_env_int(
            'SESSION_REGENERATE_INTERVAL_MINUTES',
            10
        ),
        'cookie_path' => (string) connectpro_env(
            'SESSION_COOKIE_PATH',
            '/connectpro'
        ),
        'cookie_domain' => (string) connectpro_env(
            'SESSION_COOKIE_DOMAIN',
            ''
        ),
        'cookie_secure' => (bool) connectpro_env(
            'SESSION_COOKIE_SECURE',
            $isProduction
        ),
        'cookie_http_only' => true,
        'cookie_same_site' => (string) connectpro_env(
            'SESSION_COOKIE_SAME_SITE',
            'Lax'
        ),
    ],

    'security' => [
        'csrf_enabled' => (bool) connectpro_env('CSRF_ENABLED', true),
        'csrf_token_name' => (string) connectpro_env(
            'CSRF_TOKEN_NAME',
            'connectpro_csrf_token'
        ),
        'csrf_header_name' => (string) connectpro_env(
            'CSRF_HEADER_NAME',
            'X-CSRF-Token'
        ),
        'password_algorithm' => PASSWORD_DEFAULT,
        'password_min_length' => connectpro_env_int(
            'PASSWORD_MIN_LENGTH',
            12
        ),
        'login_max_attempts' => connectpro_env_int(
            'LOGIN_MAX_ATTEMPTS',
            5
        ),
        'login_lockout_minutes' => connectpro_env_int(
            'LOGIN_LOCKOUT_MINUTES',
            15
        ),
        'trust_proxy_headers' => (bool) connectpro_env(
            'TRUST_PROXY_HEADERS',
            false
        ),
        'content_security_policy' => (bool) connectpro_env(
            'CONTENT_SECURITY_POLICY_ENABLED',
            true
        ),
    ],

    'cors' => [
        'enabled' => (bool) connectpro_env('CORS_ENABLED', true),
        'allowed_origins' => connectpro_env_list(
            'CORS_ALLOWED_ORIGINS',
            $defaultAllowedOrigins
        ),
        'allowed_methods' => [
            'GET',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
            'OPTIONS',
        ],
        'allowed_headers' => [
            'Accept',
            'Authorization',
            'Content-Type',
            'Origin',
            'X-Requested-With',
            'X-CSRF-Token',
        ],
        'exposed_headers' => [
            'X-Request-Id',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
        ],
        'supports_credentials' => true,
        'max_age_seconds' => connectpro_env_int('CORS_MAX_AGE', 600),
    ],

    'pagination' => [
        'default_page' => 1,
        'default_per_page' => connectpro_env_int(
            'PAGINATION_DEFAULT_PER_PAGE',
            20
        ),
        'max_per_page' => connectpro_env_int(
            'PAGINATION_MAX_PER_PAGE',
            100
        ),
        'allowed_per_page' => [10, 20, 25, 50, 100],
    ],

    'upload' => [
        'max_size_bytes' => connectpro_env_int(
            'UPLOAD_MAX_SIZE_BYTES',
            10 * 1024 * 1024
        ),
        'allowed_extensions' => [
            'csv',
            'xlsx',
        ],
        'allowed_mime_types' => [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'randomize_file_names' => true,
        'scan_uploaded_files' => (bool) connectpro_env(
            'SCAN_UPLOADED_FILES',
            false
        ),
    ],

    'rate_limit' => [
        'enabled' => (bool) connectpro_env('RATE_LIMIT_ENABLED', true),
        'requests' => connectpro_env_int('RATE_LIMIT_REQUESTS', 120),
        'window_seconds' => connectpro_env_int(
            'RATE_LIMIT_WINDOW_SECONDS',
            60
        ),
        'login_requests' => connectpro_env_int(
            'LOGIN_RATE_LIMIT_REQUESTS',
            10
        ),
    ],

    'logging' => [
        'enabled' => (bool) connectpro_env('LOG_ENABLED', true),
        'level' => (string) connectpro_env(
            'LOG_LEVEL',
            $isProduction ? 'warning' : 'debug'
        ),
        'channel' => (string) connectpro_env('LOG_CHANNEL', 'daily'),
        'max_files' => connectpro_env_int('LOG_MAX_FILES', 30),
        'include_request_id' => true,
        'log_sensitive_data' => false,
    ],

    'features' => [
        'favorites' => (bool) connectpro_env('FEATURE_FAVORITES', true),
        'notifications' => (bool) connectpro_env(
            'FEATURE_NOTIFICATIONS',
            true
        ),
        'presence_monitoring' => (bool) connectpro_env(
            'FEATURE_PRESENCE_MONITORING',
            true
        ),
        'import_export' => (bool) connectpro_env(
            'FEATURE_IMPORT_EXPORT',
            true
        ),
        'activity_log' => (bool) connectpro_env(
            'FEATURE_ACTIVITY_LOG',
            true
        ),
    ],
];
