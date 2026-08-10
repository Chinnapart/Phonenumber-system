<?php

declare(strict_types=1);

/**
 * ConnectPro Permission Middleware
 *
 * File: api/middleware/permission.php
 *
 * Responsibilities:
 * - Reuse the authentication middleware
 * - Enforce one or more roles and permissions
 * - Support all/any permission matching
 * - Support resource ownership and custom policy callbacks
 * - Return the normalized authentication context
 * - Return consistent JSON errors without exposing internals
 *
 * Usage:
 *
 * $permission = require __DIR__ . '/../middleware/permission.php';
 *
 * $auth = $permission('contacts.view');
 *
 * $auth = $permission([
 *     'permissions' => ['contacts.create', 'contacts.update'],
 *     'mode' => 'any',
 *     'roles' => ['editor', 'admin', 'super_admin'],
 * ]);
 */

$authMiddlewarePath = __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

if (!is_file($authMiddlewarePath)) {
    throw new RuntimeException('Authentication middleware is missing.');
}

/** @var Closure(array<string, mixed>): array<string, mixed> $authenticate */
$authenticate = require $authMiddlewarePath;

if (!function_exists('connectpro_permission_middleware_error')) {
    /**
     * Send a consistent authorization error response.
     *
     * @param array<string, mixed> $details
     */
    function connectpro_permission_middleware_error(
        int $status,
        string $code,
        string $message,
        array $details = []
    ): never {
        if (function_exists('connectpro_auth_json_error')) {
            connectpro_auth_json_error($status, $code, $message, $details);
        }

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

if (!function_exists('connectpro_permission_normalize_list')) {
    /**
     * @return list<string>
     */
    function connectpro_permission_normalize_list(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = strtolower(trim($item));

            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }
}

if (!function_exists('connectpro_permission_validate_mode')) {
    function connectpro_permission_validate_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        if (!in_array($mode, ['all', 'any'], true)) {
            throw new InvalidArgumentException(
                'Permission mode must be all or any.'
            );
        }

        return $mode;
    }
}

if (!function_exists('connectpro_permission_has_roles')) {
    /**
     * @param list<string> $requiredRoles
     * @param array<string, mixed> $user
     */
    function connectpro_permission_has_roles(
        array $requiredRoles,
        array $user,
        string $mode = 'any'
    ): bool {
        if ($requiredRoles === []) {
            return true;
        }

        $matches = 0;

        foreach ($requiredRoles as $role) {
            if (connectpro_has_role($role, $user)) {
                $matches++;
            }
        }

        return $mode === 'all'
            ? $matches === count($requiredRoles)
            : $matches > 0;
    }
}

if (!function_exists('connectpro_permission_has_permissions')) {
    /**
     * @param list<string> $requiredPermissions
     * @param array<string, mixed> $user
     */
    function connectpro_permission_has_permissions(
        array $requiredPermissions,
        array $user,
        string $mode = 'all'
    ): bool {
        if ($requiredPermissions === []) {
            return true;
        }

        return $mode === 'any'
            ? connectpro_has_any_permission($requiredPermissions, $user)
            : connectpro_has_all_permissions($requiredPermissions, $user);
    }
}

if (!function_exists('connectpro_permission_check_ownership')) {
    /**
     * Verify that the authenticated user owns the requested resource.
     *
     * Supported options:
     * - owner_id: direct owner user ID
     * - owner_resolver: callable(array $auth, array $options): int|null
     * - allow_roles: roles allowed to bypass ownership
     *
     * @param array<string, mixed> $authContext
     * @param array<string, mixed> $options
     */
    function connectpro_permission_check_ownership(
        array $authContext,
        array $options
    ): void {
        $ownershipRequired = array_key_exists('owner_id', $options)
            || isset($options['owner_resolver']);

        if (!$ownershipRequired) {
            return;
        }

        $bypassRoles = connectpro_permission_normalize_list(
            $options['owner_bypass_roles']
                ?? ['admin', 'super_admin']
        );
        $user = is_array($authContext['user'] ?? null)
            ? $authContext['user']
            : [];

        if (connectpro_permission_has_roles($bypassRoles, $user, 'any')) {
            return;
        }

        $ownerId = $options['owner_id'] ?? null;
        $resolver = $options['owner_resolver'] ?? null;

        if ($resolver !== null) {
            if (!is_callable($resolver)) {
                throw new InvalidArgumentException(
                    'owner_resolver must be callable.'
                );
            }

            $ownerId = $resolver($authContext, $options);
        }

        if (
            filter_var($ownerId, FILTER_VALIDATE_INT) === false
            || (int) $ownerId < 1
        ) {
            connectpro_permission_middleware_error(
                403,
                'RESOURCE_OWNER_UNRESOLVED',
                'ไม่สามารถตรวจสอบเจ้าของข้อมูลได้'
            );
        }

        if ((int) $authContext['user_id'] !== (int) $ownerId) {
            connectpro_permission_middleware_error(
                403,
                'RESOURCE_ACCESS_DENIED',
                'บัญชีปัจจุบันไม่มีสิทธิ์เข้าถึงข้อมูลนี้'
            );
        }
    }
}

if (!function_exists('connectpro_permission_run_policy')) {
    /**
     * Run an optional custom policy callback.
     *
     * Callback signature:
     * callable(array $authContext, array $options): bool|array
     *
     * Returning true allows access. Returning false denies access.
     * An array may contain allowed, code, message, and details.
     *
     * @param array<string, mixed> $authContext
     * @param array<string, mixed> $options
     */
    function connectpro_permission_run_policy(
        array $authContext,
        array $options
    ): void {
        $policy = $options['policy'] ?? null;

        if ($policy === null) {
            return;
        }

        if (!is_callable($policy)) {
            throw new InvalidArgumentException('policy must be callable.');
        }

        $result = $policy($authContext, $options);

        if ($result === true) {
            return;
        }

        if ($result === false) {
            connectpro_permission_middleware_error(
                403,
                'POLICY_DENIED',
                'นโยบายความปลอดภัยไม่อนุญาตให้ดำเนินการนี้'
            );
        }

        if (!is_array($result)) {
            throw new UnexpectedValueException(
                'Policy result must be boolean or array.'
            );
        }

        if ((bool) ($result['allowed'] ?? false)) {
            return;
        }

        $details = $result['details'] ?? [];

        connectpro_permission_middleware_error(
            403,
            (string) ($result['code'] ?? 'POLICY_DENIED'),
            (string) ($result['message']
                ?? 'นโยบายความปลอดภัยไม่อนุญาตให้ดำเนินการนี้'),
            is_array($details) ? $details : []
        );
    }
}

/**
 * Permission middleware callable.
 *
 * Input may be a permission string or an options array.
 *
 * Options:
 * - permissions: string|list<string>
 * - mode: all|any
 * - roles: string|list<string>
 * - role_mode: all|any
 * - auth: options forwarded to auth.php
 * - csrf: bool|null, forwarded when auth.csrf is not set
 * - owner_id: int
 * - owner_resolver: callable
 * - owner_bypass_roles: list<string>
 * - policy: callable
 * - expose_requirements: bool
 *
 * @return Closure(string|array<string, mixed>): array<string, mixed>
 */
return static function (string|array $requirements = []) use (
    $authenticate
): array {
    $options = is_string($requirements)
        ? ['permissions' => [$requirements]]
        : $requirements;

    $requiredPermissions = connectpro_permission_normalize_list(
        $options['permissions'] ?? []
    );
    $requiredRoles = connectpro_permission_normalize_list(
        $options['roles'] ?? []
    );
    $permissionMode = connectpro_permission_validate_mode(
        (string) ($options['mode'] ?? 'all')
    );
    $roleMode = connectpro_permission_validate_mode(
        (string) ($options['role_mode'] ?? 'any')
    );

    $authOptions = $options['auth'] ?? [];

    if (!is_array($authOptions)) {
        throw new InvalidArgumentException('auth options must be an array.');
    }

    if (
        array_key_exists('csrf', $options)
        && !array_key_exists('csrf', $authOptions)
    ) {
        $authOptions['csrf'] = $options['csrf'];
    }

    // Authentication is performed first. Permission checks are kept here
    // so ownership and custom policy rules remain centralized.
    $authContext = $authenticate($authOptions);
    $user = is_array($authContext['user'] ?? null)
        ? $authContext['user']
        : [];

    if (!connectpro_permission_has_roles(
        $requiredRoles,
        $user,
        $roleMode
    )) {
        $details = !empty($options['expose_requirements'])
            ? [
                'required_roles' => $requiredRoles,
                'role_mode' => $roleMode,
            ]
            : [];

        connectpro_permission_middleware_error(
            403,
            'ROLE_REQUIRED',
            'บัญชีปัจจุบันไม่มี Role ที่จำเป็น',
            $details
        );
    }

    if (!connectpro_permission_has_permissions(
        $requiredPermissions,
        $user,
        $permissionMode
    )) {
        $details = !empty($options['expose_requirements'])
            ? [
                'required_permissions' => $requiredPermissions,
                'permission_mode' => $permissionMode,
            ]
            : [];

        connectpro_permission_middleware_error(
            403,
            'PERMISSION_DENIED',
            'บัญชีปัจจุบันไม่มีสิทธิ์ดำเนินการนี้',
            $details
        );
    }

    connectpro_permission_check_ownership($authContext, $options);
    connectpro_permission_run_policy($authContext, $options);

    $authContext['authorization'] = [
        'required_roles' => $requiredRoles,
        'role_mode' => $roleMode,
        'required_permissions' => $requiredPermissions,
        'permission_mode' => $permissionMode,
        'ownership_checked' => array_key_exists('owner_id', $options)
            || isset($options['owner_resolver']),
        'policy_checked' => isset($options['policy']),
    ];

    $GLOBALS['connectpro_auth'] = $authContext;

    return $authContext;
};
