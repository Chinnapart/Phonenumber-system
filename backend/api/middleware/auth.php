<?php

declare(strict_types=1);

/**
 * ConnectPro Authentication Middleware
 *
 * File: api/middleware/auth.php
 *
 * Responsibilities:
 * - Load secure session and RBAC configuration
 * - Require an authenticated and active user
 * - Validate optional role and permission requirements
 * - Validate CSRF tokens for state-changing requests
 * - Reject revoked server-side sessions when enabled
 * - Attach normalized authentication context to the request
 * - Return consistent JSON errors without exposing internals
 *
 * Usage:
 *
 * $auth = require __DIR__ . '/../middleware/auth.php';
 * $user = $auth();
 *
 * $user = $auth([
 *     'permissions' => ['contacts.create'],
 *     'permission_mode' => 'all',
 *     'csrf' => true,
 * ]);
 */

$apiRoot = dirname(__DIR__);
$sessionPath = $apiRoot . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'session.php';
$permissionsPath = $apiRoot . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'permissions.php';
$appPath = $apiRoot . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'app.php';

foreach ([$appPath, $sessionPath, $permissionsPath] as $requiredFile) {
    if (!is_file($requiredFile)) {
        throw new RuntimeException('Required authentication configuration is missing.');
    }
}

/** @var array<string, mixed> $appConfig */
$appConfig = require $appPath;
/** @var array<string, mixed> $sessionContext */
$sessionContext = require $sessionPath;
/** @var array<string, mixed> $permissionContext */
$permissionContext = require $permissionsPath;

if (!function_exists('connectpro_auth_json_error')) {
    /**
     * Send a consistent authentication or authorization error.
     *
     * @param array<string, mixed> $details
     */
    function connectpro_auth_json_error(
        int $status,
        string $code,
        string $message,
        array $details = []
    ): never {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode(
            [
                'success' => false,
                'error' => array_merge(
                    [
                        'code' => $code,
                        'message' => $message,
                    ],
                    $details
                ),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

if (!function_exists('connectpro_auth_request_method')) {
    function connectpro_auth_request_method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}

if (!function_exists('connectpro_auth_header')) {
    function connectpro_auth_header(string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));
        $serverKey = 'HTTP_' . $normalized;
        $value = $_SERVER[$serverKey] ?? null;

        if ($value === null && $normalized === 'CONTENT_TYPE') {
            $value = $_SERVER['CONTENT_TYPE'] ?? null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

if (!function_exists('connectpro_auth_is_state_changing')) {
    function connectpro_auth_is_state_changing(?string $method = null): bool
    {
        $method ??= connectpro_auth_request_method();

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}

if (!function_exists('connectpro_auth_validate_csrf')) {
    /**
     * Validate the CSRF token using constant-time comparison.
     *
     * @param array<string, mixed> $appConfig
     */
    function connectpro_auth_validate_csrf(array $appConfig): void
    {
        $security = is_array($appConfig['security'] ?? null)
            ? $appConfig['security']
            : [];

        if (!(bool) ($security['csrf_enabled'] ?? true)) {
            return;
        }

        $tokenName = (string) (
            $security['csrf_token_name'] ?? 'connectpro_csrf_token'
        );
        $headerName = (string) (
            $security['csrf_header_name'] ?? 'X-CSRF-Token'
        );
        $expected = $_SESSION[$tokenName] ?? null;
        $provided = connectpro_auth_header($headerName);

        if ($provided === null && isset($_POST[$tokenName])) {
            $postedToken = $_POST[$tokenName];
            $provided = is_string($postedToken) ? $postedToken : null;
        }

        if (
            !is_string($expected)
            || strlen($expected) < 64
            || !is_string($provided)
            || !hash_equals($expected, $provided)
        ) {
            connectpro_auth_json_error(
                419,
                'CSRF_TOKEN_MISMATCH',
                'CSRF Token ไม่ถูกต้องหรือหมดอายุ'
            );
        }
    }
}

if (!function_exists('connectpro_auth_validate_session_registry')) {
    /**
     * Validate the current session against user_sessions when enabled.
     *
     * @param array<string, mixed> $options
     */
    function connectpro_auth_validate_session_registry(
        array $options,
        array $user
    ): void {
        if (empty($options['session_registry_enabled'])) {
            return;
        }

        $pdo = $options['pdo'] ?? null;

        if (!$pdo instanceof PDO) {
            throw new RuntimeException(
                'PDO is required when session registry validation is enabled.'
            );
        }

        $sessionId = session_id();

        if ($sessionId === '') {
            connectpro_auth_json_error(
                401,
                'SESSION_INVALID',
                'Session ไม่ถูกต้อง กรุณาเข้าสู่ระบบอีกครั้ง'
            );
        }

        $statement = $pdo->prepare(
            'SELECT expires_at FROM user_sessions '
            . 'WHERE id = :session_id AND user_id = :user_id LIMIT 1'
        );
        $statement->bindValue(
            ':session_id',
            hash('sha256', $sessionId),
            PDO::PARAM_STR
        );
        $statement->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
        $statement->execute();
        $session = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($session)) {
            connectpro_destroy_session();
            connectpro_auth_json_error(
                401,
                'SESSION_REVOKED',
                'Session ถูกยกเลิก กรุณาเข้าสู่ระบบอีกครั้ง'
            );
        }

        $expiresAt = strtotime((string) ($session['expires_at'] ?? ''));

        if ($expiresAt === false || $expiresAt <= time()) {
            $delete = $pdo->prepare(
                'DELETE FROM user_sessions '
                . 'WHERE id = :session_id AND user_id = :user_id'
            );
            $delete->execute([
                'session_id' => hash('sha256', $sessionId),
                'user_id' => (int) $user['id'],
            ]);
            connectpro_destroy_session();
            connectpro_auth_json_error(
                401,
                'SESSION_EXPIRED',
                'Session หมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง'
            );
        }

        if (!empty($options['touch_session_registry'])) {
            $touch = $pdo->prepare(
                'UPDATE user_sessions SET last_activity_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :session_id AND user_id = :user_id'
            );
            $touch->execute([
                'session_id' => hash('sha256', $sessionId),
                'user_id' => (int) $user['id'],
            ]);
        }
    }
}

if (!function_exists('connectpro_auth_validate_account')) {
    /**
     * Optionally refresh account status from the database.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function connectpro_auth_validate_account(
        array $options,
        array $user
    ): array {
        if (empty($options['refresh_user'])) {
            if (($user['status'] ?? 'active') !== 'active') {
                connectpro_destroy_session();
                connectpro_auth_json_error(
                    403,
                    'ACCOUNT_INACTIVE',
                    'บัญชีนี้ถูกปิดใช้งาน'
                );
            }

            return $user;
        }

        $pdo = $options['pdo'] ?? null;

        if (!$pdo instanceof PDO) {
            throw new RuntimeException(
                'PDO is required when user refresh is enabled.'
            );
        }

        $statement = $pdo->prepare(
            'SELECT u.id, u.username, u.email, u.display_name, u.status, '
            . 'u.role, u.department_id, d.name AS department_name, '
            . 'u.must_change_password, u.last_login_at, '
            . 'u.password_changed_at '
            . 'FROM users u '
            . 'LEFT JOIN departments d ON d.id = u.department_id '
            . 'WHERE u.id = :user_id LIMIT 1'
        );
        $statement->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
        $statement->execute();
        $fresh = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($fresh) || ($fresh['status'] ?? '') !== 'active') {
            connectpro_destroy_session();
            connectpro_auth_json_error(
                403,
                'ACCOUNT_INACTIVE',
                'บัญชีนี้ถูกปิดใช้งานหรือไม่พบในระบบ'
            );
        }

        $role = strtolower(trim((string) ($fresh['role'] ?? 'user')));
        $fresh['id'] = (int) $fresh['id'];
        $fresh['department_id'] = empty($fresh['department_id'])
            ? null
            : (int) $fresh['department_id'];
        $fresh['must_change_password'] = (bool) (
            $fresh['must_change_password'] ?? false
        );
        $fresh['role'] = $role;
        $fresh['roles'] = [$role];
        $_SESSION['auth_user'] = $fresh;

        return $fresh;
    }
}

/**
 * Authentication middleware callable.
 *
 * Options:
 * - roles: list<string>
 * - permissions: list<string>
 * - permission_mode: all|any
 * - csrf: bool|null, null means automatic for state-changing requests
 * - refresh_user: bool
 * - pdo: PDO, required for refresh and session registry checks
 * - session_registry_enabled: bool
 * - touch_session_registry: bool
 * - allow_password_change_only: bool
 * - require_password_confirmation: bool
 * - password_confirmation_seconds: int
 *
 * @return Closure(array<string, mixed>): array<string, mixed>
 */
return static function (array $options = []) use (
    $appConfig,
    $sessionContext,
    $permissionContext
): array {
    if (
        session_status() !== PHP_SESSION_ACTIVE
        || empty($sessionContext['started'])
    ) {
        connectpro_auth_json_error(
            401,
            'SESSION_NOT_STARTED',
            'ไม่สามารถเริ่ม Session ได้'
        );
    }

    $user = connectpro_current_user();

    if ($user === null) {
        connectpro_auth_json_error(
            401,
            'AUTHENTICATION_REQUIRED',
            'กรุณาเข้าสู่ระบบก่อนใช้งาน'
        );
    }

    $user = connectpro_auth_validate_account($options, $user);
    connectpro_auth_validate_session_registry($options, $user);

    $requiresPasswordChange = (bool) (
        $user['must_change_password'] ?? false
    );

    if (
        $requiresPasswordChange
        && empty($options['allow_password_change_only'])
    ) {
        connectpro_auth_json_error(
            403,
            'PASSWORD_CHANGE_REQUIRED',
            'กรุณาเปลี่ยนรหัสผ่านก่อนใช้งานส่วนอื่นของระบบ'
        );
    }

    $requiredRoles = $options['roles'] ?? [];

    if (is_string($requiredRoles)) {
        $requiredRoles = [$requiredRoles];
    }

    if (is_array($requiredRoles) && $requiredRoles !== []) {
        $hasRequiredRole = false;

        foreach ($requiredRoles as $role) {
            if (is_string($role) && connectpro_has_role($role, $user)) {
                $hasRequiredRole = true;
                break;
            }
        }

        if (!$hasRequiredRole) {
            connectpro_auth_json_error(
                403,
                'ROLE_REQUIRED',
                'บัญชีปัจจุบันไม่มี Role ที่จำเป็น',
                ['required_roles' => array_values($requiredRoles)]
            );
        }
    }

    $requiredPermissions = $options['permissions'] ?? [];

    if (is_string($requiredPermissions)) {
        $requiredPermissions = [$requiredPermissions];
    }

    if (is_array($requiredPermissions) && $requiredPermissions !== []) {
        $requiredPermissions = array_values(array_filter(
            $requiredPermissions,
            static fn (mixed $permission): bool => is_string($permission)
                && trim($permission) !== ''
        ));
        $permissionMode = strtolower((string) (
            $options['permission_mode'] ?? 'all'
        ));
        $allowed = $permissionMode === 'any'
            ? connectpro_has_any_permission($requiredPermissions, $user)
            : connectpro_has_all_permissions($requiredPermissions, $user);

        if (!$allowed) {
            connectpro_auth_json_error(
                403,
                'PERMISSION_DENIED',
                'บัญชีปัจจุบันไม่มีสิทธิ์ดำเนินการนี้',
                [
                    'required_permissions' => $requiredPermissions,
                    'permission_mode' => $permissionMode === 'any'
                        ? 'any'
                        : 'all',
                ]
            );
        }
    }

    if (!empty($options['require_password_confirmation'])) {
        $confirmedAt = (int) (
            $_SESSION['_password_confirmed_at'] ?? 0
        );
        $confirmationSeconds = max(
            60,
            (int) ($options['password_confirmation_seconds'] ?? 900)
        );

        if (
            $confirmedAt < 1
            || (time() - $confirmedAt) > $confirmationSeconds
        ) {
            connectpro_auth_json_error(
                403,
                'PASSWORD_CONFIRMATION_REQUIRED',
                'กรุณายืนยันรหัสผ่านก่อนดำเนินการนี้'
            );
        }
    }

    $csrfOption = $options['csrf'] ?? null;
    $requiresCsrf = is_bool($csrfOption)
        ? $csrfOption
        : connectpro_auth_is_state_changing();

    if ($requiresCsrf) {
        connectpro_auth_validate_csrf($appConfig);
    }

    $permissions = connectpro_user_permissions($user);
    $roles = connectpro_user_roles($user);
    $requestId = connectpro_auth_header('X-Request-Id');

    if ($requestId === null) {
        $requestId = bin2hex(random_bytes(16));
    }

    if (!headers_sent()) {
        header('X-Request-Id: ' . $requestId);
    }

    $context = [
        'user' => $user,
        'user_id' => (int) $user['id'],
        'roles' => $roles,
        'permissions' => $permissions,
        'session_id_hash' => hash('sha256', session_id()),
        'csrf_token' => $sessionContext['csrf_token'] ?? null,
        'request_id' => $requestId,
        'authenticated_at' => (int) (
            $_SESSION['_authenticated_at'] ?? 0
        ),
        'session_expires_at' => (int) (
            $sessionContext['expires_at'] ?? 0
        ),
    ];

    $GLOBALS['connectpro_auth'] = $context;

    return $context;
};
