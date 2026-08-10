<?php

declare(strict_types=1);

/**
 * ConnectPro Login Endpoint
 * File: api/auth/login.php
 * Method: POST
 */

$apiRoot = dirname(__DIR__);

require_once $apiRoot . '/helpers/response.php';
require_once $apiRoot . '/helpers/validation.php';
require_once $apiRoot . '/helpers/security.php';
require_once $apiRoot . '/classes/AuthService.php';

try {
    $databaseResult = require $apiRoot . '/config/database.php';

    if ($databaseResult instanceof PDO) {
        $pdo = $databaseResult;
    }

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $appConfig = require $apiRoot . '/config/app.php';
    $sessionContext = require $apiRoot . '/config/session.php';

    if (!is_array($appConfig)) {
        $appConfig = [];
    }

    if (
        session_status() !== PHP_SESSION_ACTIVE
        || !is_array($sessionContext)
        || empty($sessionContext['started'])
    ) {
        throw new RuntimeException('Secure session could not be started.');
    }

    $loggingConfig = is_array($appConfig['logging'] ?? null)
        ? $appConfig['logging']
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

    $securityConfig = is_array($appConfig['security'] ?? null)
        ? $appConfig['security']
        : [];
    connectpro_security_headers([
        'no_cache' => true,
        'hsts' => (bool) ($securityConfig['hsts_enabled'] ?? false),
        'hsts_max_age' => (int) (
            $securityConfig['hsts_max_age'] ?? 31536000
        ),
    ]);

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        connectpro_response_method_not_allowed(['POST']);
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

    $csrfTokenName = (string) (
        $securityConfig['csrf_token_name'] ?? 'connectpro_csrf_token'
    );
    $csrfHeaderName = (string) (
        $securityConfig['csrf_header_name'] ?? 'X-CSRF-Token'
    );

    if (
        (bool) ($securityConfig['csrf_enabled'] ?? true)
        && !connectpro_security_validate_csrf(
            null,
            $csrfTokenName,
            $csrfHeaderName
        )
    ) {
        connectpro_response_error(
            'CSRF_TOKEN_MISMATCH',
            'CSRF Token ไม่ถูกต้องหรือหมดอายุ',
            419
        );
    }

    $clientIp = connectpro_security_client_ip(
        (bool) ($securityConfig['trust_proxy_headers'] ?? false)
    ) ?? 'unknown';
    $rateLimitRoot = is_array($appConfig['rate_limit'] ?? null)
        ? $appConfig['rate_limit']
        : [];
    $rateLimitConfig = is_array($rateLimitRoot['login'] ?? null)
        ? $rateLimitRoot['login']
        : [];
    $rateLimit = connectpro_security_rate_limit(
        'login:' . $clientIp,
        max(1, (int) ($rateLimitConfig['attempts'] ?? 10)),
        max(1, (int) ($rateLimitConfig['window_seconds'] ?? 60)),
        $apiRoot . '/storage/rate-limit'
    );
    connectpro_security_apply_rate_headers($rateLimit);

    if (!$rateLimit['allowed']) {
        connectpro_response_too_many_requests(
            $rateLimit['retry_after'],
            'เข้าสู่ระบบบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่'
        );
    }

    $data = connectpro_validation_request([
        'login' => 'required|string|min_length:3|max_length:190',
        'password' => 'required|string|min_length:1|max_length:4096',
        'remember' => 'nullable|boolean',
    ], [
        'max_body_bytes' => 16384,
        'allowed_fields' => ['login', 'password', 'remember'],
        'reject_unknown_fields' => true,
        'labels' => [
            'login' => 'ชื่อผู้ใช้หรืออีเมล',
            'password' => 'รหัสผ่าน',
            'remember' => 'จดจำการเข้าสู่ระบบ',
        ],
        'casts' => [
            'login' => 'string',
            'remember' => 'boolean',
        ],
    ]);

    $authConfig = is_array($appConfig['auth'] ?? null)
        ? $appConfig['auth']
        : [];
    $sessionConfig = is_array($appConfig['session'] ?? null)
        ? $appConfig['session']
        : [];
    $authService = new AuthService($pdo, [
        'password_algorithm' => PASSWORD_DEFAULT,
        'password_min_length' => max(
            8,
            (int) ($authConfig['password_min_length'] ?? 12)
        ),
        'bcrypt_cost' => max(
            10,
            min(14, (int) ($authConfig['bcrypt_cost'] ?? 12))
        ),
        'login_max_attempts' => max(
            1,
            (int) ($authConfig['login_max_attempts'] ?? 5)
        ),
        'login_lockout_minutes' => max(
            1,
            (int) ($authConfig['login_lockout_minutes'] ?? 15)
        ),
        'session_lifetime_minutes' => max(
            1,
            (int) ($sessionConfig['lifetime_minutes'] ?? 30)
        ),
        'remember_lifetime_minutes' => max(
            60,
            (int) ($sessionConfig['remember_lifetime_minutes'] ?? 43200)
        ),
        'session_table_enabled' => (bool) (
            $sessionConfig['registry_enabled'] ?? false
        ),
        'activity_log_enabled' => (bool) (
            $loggingConfig['activity_enabled'] ?? true
        ),
        'dummy_password_hash' => (string) (
            $authConfig['dummy_password_hash'] ?? ''
        ),
    ]);

    $user = $authService->login(
        (string) $data['login'],
        (string) $data['password'],
        (bool) ($data['remember'] ?? false)
    );
    $csrfToken = connectpro_security_rotate_csrf($csrfTokenName);
    $role = strtolower((string) ($user['role'] ?? 'user'));
    $redirects = is_array($appConfig['redirects'] ?? null)
        ? $appConfig['redirects']
        : [];
    $redirectTo = in_array($role, ['admin', 'super_admin'], true)
        ? (string) ($redirects['admin_after_login']
            ?? '/connectpro/admin/dashboard.html')
        : (string) ($redirects['user_after_login']
            ?? '/connectpro/user/dashboard.html');

    connectpro_response_success([
        'user' => $user,
        'csrf_token' => $csrfToken,
        'must_change_password' => (bool) (
            $user['must_change_password'] ?? false
        ),
        'redirect_to' => $redirectTo,
    ], 'เข้าสู่ระบบสำเร็จ');
} catch (DomainException $exception) {
    // Authentication failures use one generic response to prevent account
    // enumeration. The detailed reason remains in the activity log.
    connectpro_response_error(
        'INVALID_CREDENTIALS',
        'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
        401
    );
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
