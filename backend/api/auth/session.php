<?php

declare(strict_types=1);

/**
 * ConnectPro Current Session Endpoint
 * File: api/auth/session.php
 * Method: GET
 *
 * Returns the authenticated user, roles, permissions, CSRF token,
 * expiration metadata, and the appropriate application redirect.
 */

$apiRoot = dirname(__DIR__);

require_once $apiRoot . '/helpers/response.php';
require_once $apiRoot . '/helpers/security.php';

try {
    $databaseResult = require $apiRoot . '/config/database.php';

    if ($databaseResult instanceof PDO) {
        $pdo = $databaseResult;
    }

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $appConfig = require $apiRoot . '/config/app.php';

    if (!is_array($appConfig)) {
        $appConfig = [];
    }

    $loggingConfig = is_array($appConfig['logging'] ?? null)
        ? $appConfig['logging']
        : [];
    $securityConfig = is_array($appConfig['security'] ?? null)
        ? $appConfig['security']
        : [];
    $sessionConfig = is_array($appConfig['session'] ?? null)
        ? $appConfig['session']
        : [];

    $requestLog = require $apiRoot . '/middleware/request-log.php';
    $requestLog([
        'pdo' => $pdo,
        'database_enabled' => (bool) (
            $loggingConfig['request_database_enabled'] ?? false
        ),
        'file_enabled' => (bool) (
            $loggingConfig['request_file_enabled'] ?? true
        ),
        'log_path' => $apiRoot . '/storage/logs',
        'log_request_body' => false,
        'sample_rate' => 1.0,
        'slow_request_ms' => 1000.0,
    ]);

    connectpro_security_headers([
        'no_cache' => true,
        'hsts' => (bool) ($securityConfig['hsts_enabled'] ?? false),
        'hsts_max_age' => (int) (
            $securityConfig['hsts_max_age'] ?? 31536000
        ),
    ]);

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        connectpro_response_method_not_allowed(['GET']);
    }

    $allowedOrigins = $securityConfig['allowed_origins'] ?? [];

    if (
        is_array($allowedOrigins)
        && $allowedOrigins !== []
        && !connectpro_security_origin_allowed($allowedOrigins, true)
    ) {
        connectpro_response_forbidden(
            'Origin ไม่ได้รับอนุญาต',
            'ORIGIN_NOT_ALLOWED'
        );
    }

    $auth = require $apiRoot . '/middleware/auth.php';
    $authContext = $auth([
        'pdo' => $pdo,
        'refresh_user' => true,
        'session_registry_enabled' => (bool) (
            $sessionConfig['registry_enabled'] ?? false
        ),
        'touch_session_registry' => (bool) (
            $sessionConfig['touch_registry_on_check'] ?? true
        ),
        'csrf' => false,
        'allow_password_change_only' => true,
    ]);

    $user = is_array($authContext['user'] ?? null)
        ? $authContext['user']
        : [];
    $roles = is_array($authContext['roles'] ?? null)
        ? array_values($authContext['roles'])
        : [];
    $permissions = is_array($authContext['permissions'] ?? null)
        ? array_values($authContext['permissions'])
        : [];
    $csrfTokenName = (string) (
        $securityConfig['csrf_token_name'] ?? 'connectpro_csrf_token'
    );
    $csrfToken = connectpro_security_csrf_token($csrfTokenName);
    $sessionExpiresAt = (int) (
        $authContext['session_expires_at'] ?? 0
    );
    $remainingSeconds = $sessionExpiresAt > 0
        ? max(0, $sessionExpiresAt - time())
        : null;
    $role = strtolower((string) ($user['role'] ?? 'user'));
    $redirects = is_array($appConfig['redirects'] ?? null)
        ? $appConfig['redirects']
        : [];
    $redirectTo = in_array($role, ['admin', 'super_admin'], true)
        ? (string) ($redirects['admin_after_login']
            ?? '/connectpro/admin/dashboard.html')
        : (string) ($redirects['user_after_login']
            ?? '/connectpro/user/dashboard.html');

    // Never expose authentication internals if a deployment stores them
    // inside the session user payload.
    unset(
        $user['password'],
        $user['password_hash'],
        $user['remember_token'],
        $user['access_token'],
        $user['refresh_token']
    );

    connectpro_response_success([
        'authenticated' => true,
        'user' => $user,
        'roles' => $roles,
        'permissions' => $permissions,
        'csrf_token' => $csrfToken,
        'must_change_password' => (bool) (
            $user['must_change_password'] ?? false
        ),
        'session' => [
            'authenticated_at' => (int) (
                $authContext['authenticated_at'] ?? 0
            ),
            'expires_at' => $sessionExpiresAt > 0
                ? $sessionExpiresAt
                : null,
            'remaining_seconds' => $remainingSeconds,
        ],
        'redirect_to' => $redirectTo,
    ], 'Session ยังใช้งานได้');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
