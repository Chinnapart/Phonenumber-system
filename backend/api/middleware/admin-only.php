<?php

declare(strict_types=1);

/**
 * ConnectPro Admin-Only Middleware
 *
 * File: api/middleware/admin-only.php
 *
 * Responsibilities:
 * - Require an authenticated administrator
 * - Allow admin and super_admin roles by default
 * - Reuse permission.php and auth.php security controls
 * - Support additional permissions and custom policies
 * - Support fresh database validation and session registry checks
 * - Require recent password confirmation for sensitive operations
 *
 * Usage:
 *
 * $adminOnly = require __DIR__ . '/../middleware/admin-only.php';
 * $auth = $adminOnly();
 *
 * $auth = $adminOnly([
 *     'permissions' => ['users.delete'],
 *     'require_password_confirmation' => true,
 *     'pdo' => $pdo,
 * ]);
 */

$permissionMiddlewarePath = __DIR__
    . DIRECTORY_SEPARATOR
    . 'permission.php';

if (!is_file($permissionMiddlewarePath)) {
    throw new RuntimeException('Permission middleware is missing.');
}

/** @var Closure(string|array<string, mixed>): array<string, mixed> $permission */
$permission = require $permissionMiddlewarePath;

if (!function_exists('connectpro_admin_normalize_permissions')) {
    /**
     * @return list<string>
     */
    function connectpro_admin_normalize_permissions(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $permissions = [];

        foreach ($value as $permission) {
            if (!is_string($permission)) {
                continue;
            }

            $permission = strtolower(trim($permission));

            if ($permission !== '') {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }
}

if (!function_exists('connectpro_admin_security_headers')) {
    function connectpro_admin_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
}

/**
 * Admin-only middleware callable.
 *
 * Options:
 * - permissions: string|list<string>
 * - permission_mode: all|any
 * - roles: list<string>, defaults to admin and super_admin
 * - csrf: bool|null
 * - pdo: PDO
 * - refresh_user: bool, defaults to true when PDO is supplied
 * - session_registry_enabled: bool
 * - touch_session_registry: bool
 * - require_password_confirmation: bool
 * - password_confirmation_seconds: int
 * - policy: callable
 * - expose_requirements: bool
 *
 * @return Closure(array<string, mixed>): array<string, mixed>
 */
return static function (array $options = []) use ($permission): array {
    connectpro_admin_security_headers();

    $roles = $options['roles'] ?? ['admin', 'super_admin'];

    if (is_string($roles)) {
        $roles = [$roles];
    }

    if (!is_array($roles) || $roles === []) {
        throw new InvalidArgumentException(
            'Admin middleware requires at least one administrator role.'
        );
    }

    $normalizedRoles = [];

    foreach ($roles as $role) {
        if (!is_string($role)) {
            continue;
        }

        $role = strtolower(trim($role));

        if (in_array($role, ['admin', 'super_admin'], true)) {
            $normalizedRoles[] = $role;
        }
    }

    $normalizedRoles = array_values(array_unique($normalizedRoles));

    if ($normalizedRoles === []) {
        throw new InvalidArgumentException(
            'Only admin and super_admin roles are allowed.'
        );
    }

    $requiredPermissions = connectpro_admin_normalize_permissions(
        $options['permissions'] ?? []
    );
    $permissionMode = strtolower(trim((string) (
        $options['permission_mode'] ?? 'all'
    )));

    if (!in_array($permissionMode, ['all', 'any'], true)) {
        throw new InvalidArgumentException(
            'Permission mode must be all or any.'
        );
    }

    $pdo = $options['pdo'] ?? null;

    if ($pdo !== null && !$pdo instanceof PDO) {
        throw new InvalidArgumentException('pdo must be an instance of PDO.');
    }

    $authOptions = [
        'csrf' => $options['csrf'] ?? null,
        'refresh_user' => $options['refresh_user']
            ?? ($pdo instanceof PDO),
        'session_registry_enabled' => (bool) (
            $options['session_registry_enabled'] ?? false
        ),
        'touch_session_registry' => (bool) (
            $options['touch_session_registry'] ?? false
        ),
        'require_password_confirmation' => (bool) (
            $options['require_password_confirmation'] ?? false
        ),
        'password_confirmation_seconds' => max(
            60,
            (int) ($options['password_confirmation_seconds'] ?? 900)
        ),
        'allow_password_change_only' => false,
    ];

    if ($pdo instanceof PDO) {
        $authOptions['pdo'] = $pdo;
    }

    if (
        $authOptions['session_registry_enabled']
        && !$pdo instanceof PDO
    ) {
        throw new InvalidArgumentException(
            'PDO is required when session registry validation is enabled.'
        );
    }

    $requirements = [
        'roles' => $normalizedRoles,
        'role_mode' => 'any',
        'permissions' => $requiredPermissions,
        'mode' => $permissionMode,
        'auth' => $authOptions,
        'expose_requirements' => (bool) (
            $options['expose_requirements'] ?? false
        ),
    ];

    if (isset($options['policy'])) {
        if (!is_callable($options['policy'])) {
            throw new InvalidArgumentException('policy must be callable.');
        }

        $requirements['policy'] = $options['policy'];
    }

    $authContext = $permission($requirements);
    $currentRoles = $authContext['roles'] ?? [];

    if (!is_array($currentRoles)) {
        $currentRoles = [];
    }

    $isAdministrator = count(array_intersect(
        ['admin', 'super_admin'],
        array_map(
            static fn (mixed $role): string => strtolower((string) $role),
            $currentRoles
        )
    )) > 0;

    if (!$isAdministrator) {
        connectpro_permission_middleware_error(
            403,
            'ADMIN_ACCESS_REQUIRED',
            'ส่วนนี้อนุญาตเฉพาะผู้ดูแลระบบ'
        );
    }

    $authContext['admin'] = [
        'is_admin' => true,
        'is_super_admin' => in_array(
            'super_admin',
            $currentRoles,
            true
        ),
        'required_permissions' => $requiredPermissions,
        'permission_mode' => $permissionMode,
    ];

    $GLOBALS['connectpro_auth'] = $authContext;

    return $authContext;
};
