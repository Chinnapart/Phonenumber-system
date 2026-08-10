<?php

declare(strict_types=1);

/**
 * ConnectPro Current User Endpoint
 * File: api/auth/current-user.php
 * Method: GET
 *
 * Returns a sanitized profile of the currently authenticated user,
 * including roles, permissions, department, session metadata, and CSRF token.
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
    $userId = (int) ($authContext['user_id'] ?? 0);

    if ($userId < 1) {
        connectpro_response_unauthorized();
    }

    $statement = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.display_name, u.role, '
        . 'u.status, u.department_id, d.code AS department_code, '
        . 'd.name AS department_name, u.must_change_password, '
        . 'u.last_login_at, u.password_changed_at, '
        . 'u.created_at, u.updated_at '
        . 'FROM users u '
        . 'LEFT JOIN departments d ON d.id = u.department_id '
        . 'WHERE u.id = :user_id AND u.status = :status LIMIT 1'
    );
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':status', 'active', PDO::PARAM_STR);
    $statement->execute();
    $profile = $statement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($profile)) {
        connectpro_destroy_session();
        connectpro_response_unauthorized(
            'ไม่พบบัญชีผู้ใช้หรือบัญชีถูกปิดใช้งาน',
            'ACCOUNT_INACTIVE'
        );
    }

    $profile['id'] = (int) $profile['id'];
    $profile['department_id'] = $profile['department_id'] === null
        ? null
        : (int) $profile['department_id'];
    $profile['must_change_password'] = (bool) (
        $profile['must_change_password'] ?? false
    );

    $roles = is_array($authContext['roles'] ?? null)
        ? array_values(array_unique(array_map(
            static fn (mixed $role): string => strtolower((string) $role),
            $authContext['roles']
        )))
        : [];
    $permissions = is_array($authContext['permissions'] ?? null)
        ? array_values(array_unique(array_map(
            static fn (mixed $permission): string => (string) $permission,
            $authContext['permissions']
        )))
        : [];
    sort($roles);
    sort($permissions);

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
    $role = strtolower((string) ($profile['role'] ?? 'user'));
    $redirects = is_array($appConfig['redirects'] ?? null)
        ? $appConfig['redirects']
        : [];
    $homeUrl = in_array($role, ['admin', 'super_admin'], true)
        ? (string) ($redirects['admin_after_login']
            ?? '/connectpro/admin/dashboard.html')
        : (string) ($redirects['user_after_login']
            ?? '/connectpro/user/dashboard.html');

    $capabilities = [
        'is_admin' => in_array('admin', $roles, true)
            || in_array('super_admin', $roles, true),
        'is_super_admin' => in_array('super_admin', $roles, true),
        'can_manage_contacts' => in_array('contacts.update', $permissions, true)
            || in_array('contacts.create', $permissions, true),
        'can_manage_users' => in_array('users.update', $permissions, true)
            || in_array('users.create', $permissions, true),
        'can_view_activity_logs' => in_array(
            'activity_logs.view',
            $permissions,
            true
        ),
        'can_manage_settings' => in_array(
            'settings.update',
            $permissions,
            true
        ),
    ];

    // Keep the refreshed profile synchronized with the active session while
    // never storing credentials or access tokens in the response payload.
    $_SESSION['auth_user'] = array_merge($user, $profile, [
        'roles' => $roles,
    ]);

    unset(
        $profile['password'],
        $profile['password_hash'],
        $profile['remember_token'],
        $profile['access_token'],
        $profile['refresh_token']
    );

    connectpro_response_success([
        'user' => $profile,
        'roles' => $roles,
        'permissions' => $permissions,
        'capabilities' => $capabilities,
        'csrf_token' => $csrfToken,
        'session' => [
            'authenticated_at' => (int) (
                $authContext['authenticated_at'] ?? 0
            ),
            'expires_at' => $sessionExpiresAt > 0
                ? $sessionExpiresAt
                : null,
            'remaining_seconds' => $remainingSeconds,
        ],
        'home_url' => $homeUrl,
    ], 'โหลดข้อมูลผู้ใช้ปัจจุบันสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
