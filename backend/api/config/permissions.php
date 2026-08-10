<?php

declare(strict_types=1);

/**
 * ConnectPro Role-Based Access Control Configuration
 *
 * File: api/config/permissions.php
 *
 * Responsibilities:
 * - Define roles and permissions in one place
 * - Resolve inherited permissions
 * - Read the authenticated user from the active session
 * - Provide reusable authorization helpers
 * - Return consistent HTTP 401 and 403 JSON responses
 *
 * Authorization must always be validated on the server. Frontend
 * data-permission attributes are only for user-interface rendering.
 */

if (!function_exists('connectpro_permission_response')) {
    /**
     * Send a consistent authorization error response.
     *
     * @param array<string, mixed> $details
     */
    function connectpro_permission_response(
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

$permissions = [
    // Dashboard
    'dashboard.view',

    // Contacts
    'contacts.view',
    'contacts.create',
    'contacts.update',
    'contacts.delete',
    'contacts.import',
    'contacts.export',

    // Departments
    'departments.view',
    'departments.create',
    'departments.update',
    'departments.delete',

    // Users and roles
    'users.view',
    'users.create',
    'users.update',
    'users.delete',
    'users.reset_password',
    'users.assign_roles',

    // Activity logs
    'activity_logs.view',
    'activity_logs.export',

    // System settings
    'settings.view',
    'settings.update',

    // Notifications
    'notifications.view',
    'notifications.manage',

    // Presence monitoring
    'presence.view',
    'presence.update',

    // Maintenance
    'system.health.view',
    'system.backup',
    'system.restore',
];

/**
 * Role inheritance is intentionally explicit.
 *
 * user        Basic directory usage.
 * editor      User permissions plus contact and department maintenance.
 * admin       Editor permissions plus administration capabilities.
 * super_admin All registered permissions.
 */
$roles = [
    'user' => [
        'label' => 'User',
        'description' => 'ผู้ใช้งานสมุดรายชื่อทั่วไป',
        'inherits' => [],
        'permissions' => [
            'dashboard.view',
            'contacts.view',
            'departments.view',
            'notifications.view',
            'presence.view',
        ],
    ],

    'editor' => [
        'label' => 'Editor',
        'description' => 'ผู้ดูแลข้อมูลผู้ติดต่อและแผนก',
        'inherits' => ['user'],
        'permissions' => [
            'contacts.create',
            'contacts.update',
            'contacts.import',
            'contacts.export',
            'departments.create',
            'departments.update',
            'presence.update',
        ],
    ],

    'admin' => [
        'label' => 'Administrator',
        'description' => 'ผู้ดูแลระบบ ConnectPro',
        'inherits' => ['editor'],
        'permissions' => [
            'contacts.delete',
            'departments.delete',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.reset_password',
            'users.assign_roles',
            'activity_logs.view',
            'activity_logs.export',
            'settings.view',
            'settings.update',
            'notifications.manage',
            'system.health.view',
            'system.backup',
        ],
    ],

    'super_admin' => [
        'label' => 'Super Administrator',
        'description' => 'ผู้ดูแลระบบระดับสูงสุด',
        'inherits' => ['admin'],
        'permissions' => $permissions,
    ],
];

if (!function_exists('connectpro_resolve_role_permissions')) {
    /**
     * Resolve a role and all inherited permissions.
     *
     * @param array<string, array<string, mixed>> $roleDefinitions
     * @param list<string> $visited
     * @return list<string>
     */
    function connectpro_resolve_role_permissions(
        string $role,
        array $roleDefinitions,
        array $visited = []
    ): array {
        if (!isset($roleDefinitions[$role]) || in_array($role, $visited, true)) {
            return [];
        }

        $visited[] = $role;
        $definition = $roleDefinitions[$role];
        $resolved = [];

        foreach ((array) ($definition['inherits'] ?? []) as $parentRole) {
            if (!is_string($parentRole)) {
                continue;
            }

            $resolved = array_merge(
                $resolved,
                connectpro_resolve_role_permissions(
                    $parentRole,
                    $roleDefinitions,
                    $visited
                )
            );
        }

        foreach ((array) ($definition['permissions'] ?? []) as $permission) {
            if (is_string($permission) && $permission !== '') {
                $resolved[] = $permission;
            }
        }

        return array_values(array_unique($resolved));
    }
}

if (!function_exists('connectpro_current_user')) {
    /**
     * Return the normalized authenticated user stored in the session.
     *
     * @return array<string, mixed>|null
     */
    function connectpro_current_user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $user = $_SESSION['auth_user'] ?? $_SESSION['user'] ?? null;

        if (!is_array($user)) {
            return null;
        }

        $userId = $user['id'] ?? $user['user_id'] ?? null;

        if ($userId === null || $userId === '') {
            return null;
        }

        return $user;
    }
}

if (!function_exists('connectpro_user_roles')) {
    /**
     * Extract normalized role keys from a user record.
     *
     * @param array<string, mixed>|null $user
     * @return list<string>
     */
    function connectpro_user_roles(?array $user = null): array
    {
        $user ??= connectpro_current_user();

        if ($user === null) {
            return [];
        }

        $rawRoles = $user['roles'] ?? $user['role'] ?? $user['role_name'] ?? [];

        if (is_string($rawRoles)) {
            $rawRoles = [$rawRoles];
        }

        if (!is_array($rawRoles)) {
            return [];
        }

        $roles = [];

        foreach ($rawRoles as $role) {
            if (!is_string($role)) {
                continue;
            }

            $normalized = strtolower(trim($role));

            if ($normalized !== '') {
                $roles[] = $normalized;
            }
        }

        return array_values(array_unique($roles));
    }
}

if (!function_exists('connectpro_user_permissions')) {
    /**
     * Resolve role permissions plus explicit session permissions.
     *
     * Explicit denied_permissions always wins.
     *
     * @param array<string, mixed>|null $user
     * @param array<string, array<string, mixed>>|null $roleDefinitions
     * @return list<string>
     */
    function connectpro_user_permissions(
        ?array $user = null,
        ?array $roleDefinitions = null
    ): array {
        global $roles;

        $user ??= connectpro_current_user();
        $roleDefinitions ??= $roles;

        if ($user === null || !is_array($roleDefinitions)) {
            return [];
        }

        $resolved = [];

        foreach (connectpro_user_roles($user) as $role) {
            $resolved = array_merge(
                $resolved,
                connectpro_resolve_role_permissions($role, $roleDefinitions)
            );
        }

        $explicitPermissions = $user['permissions'] ?? [];

        if (is_array($explicitPermissions)) {
            foreach ($explicitPermissions as $permission) {
                if (is_string($permission) && $permission !== '') {
                    $resolved[] = $permission;
                }
            }
        }

        $resolved = array_values(array_unique($resolved));
        $deniedPermissions = $user['denied_permissions'] ?? [];

        if (is_array($deniedPermissions)) {
            $deniedPermissions = array_values(array_filter(
                $deniedPermissions,
                static fn (mixed $permission): bool => is_string($permission)
                    && $permission !== ''
            ));

            $resolved = array_values(array_diff($resolved, $deniedPermissions));
        }

        return $resolved;
    }
}

if (!function_exists('connectpro_is_authenticated')) {
    function connectpro_is_authenticated(): bool
    {
        return connectpro_current_user() !== null;
    }
}

if (!function_exists('connectpro_has_role')) {
    function connectpro_has_role(string $role, ?array $user = null): bool
    {
        return in_array(
            strtolower(trim($role)),
            connectpro_user_roles($user),
            true
        );
    }
}

if (!function_exists('connectpro_has_permission')) {
    function connectpro_has_permission(
        string $permission,
        ?array $user = null
    ): bool {
        $permission = trim($permission);

        if ($permission === '') {
            return false;
        }

        return in_array(
            $permission,
            connectpro_user_permissions($user),
            true
        );
    }
}

if (!function_exists('connectpro_has_any_permission')) {
    /**
     * @param list<string> $permissions
     */
    function connectpro_has_any_permission(
        array $permissions,
        ?array $user = null
    ): bool {
        foreach ($permissions as $permission) {
            if (connectpro_has_permission($permission, $user)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('connectpro_has_all_permissions')) {
    /**
     * @param list<string> $permissions
     */
    function connectpro_has_all_permissions(
        array $permissions,
        ?array $user = null
    ): bool {
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (!connectpro_has_permission($permission, $user)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('connectpro_require_authentication')) {
    /**
     * @return array<string, mixed>
     */
    function connectpro_require_authentication(): array
    {
        $user = connectpro_current_user();

        if ($user === null) {
            connectpro_permission_response(
                401,
                'AUTHENTICATION_REQUIRED',
                'กรุณาเข้าสู่ระบบก่อนใช้งาน'
            );
        }

        return $user;
    }
}

if (!function_exists('connectpro_require_permission')) {
    /**
     * @return array<string, mixed>
     */
    function connectpro_require_permission(string $permission): array
    {
        $user = connectpro_require_authentication();

        if (!connectpro_has_permission($permission, $user)) {
            connectpro_permission_response(
                403,
                'PERMISSION_DENIED',
                'บัญชีปัจจุบันไม่มีสิทธิ์ดำเนินการนี้',
                ['required_permission' => $permission]
            );
        }

        return $user;
    }
}

if (!function_exists('connectpro_require_any_permission')) {
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    function connectpro_require_any_permission(array $permissions): array
    {
        $user = connectpro_require_authentication();

        if (!connectpro_has_any_permission($permissions, $user)) {
            connectpro_permission_response(
                403,
                'PERMISSION_DENIED',
                'บัญชีปัจจุบันไม่มีสิทธิ์ดำเนินการนี้',
                ['required_permissions' => array_values($permissions)]
            );
        }

        return $user;
    }
}

if (!function_exists('connectpro_require_role')) {
    /**
     * @return array<string, mixed>
     */
    function connectpro_require_role(string $role): array
    {
        $user = connectpro_require_authentication();

        if (!connectpro_has_role($role, $user)) {
            connectpro_permission_response(
                403,
                'ROLE_REQUIRED',
                'บัญชีปัจจุบันไม่มีบทบาทที่จำเป็น',
                ['required_role' => strtolower(trim($role))]
            );
        }

        return $user;
    }
}

$resolvedRoles = [];

foreach (array_keys($roles) as $roleKey) {
    $resolvedRoles[$roleKey] = array_merge(
        $roles[$roleKey],
        [
            'resolved_permissions' => connectpro_resolve_role_permissions(
                $roleKey,
                $roles
            ),
        ]
    );
}

return [
    'permissions' => $permissions,
    'roles' => $resolvedRoles,
    'default_role' => 'user',
    'current_user' => static fn (): ?array => connectpro_current_user(),
    'current_permissions' => static fn (): array => connectpro_user_permissions(),
    'is_authenticated' => static fn (): bool => connectpro_is_authenticated(),
    'has_role' => static fn (string $role): bool => connectpro_has_role($role),
    'has_permission' => static fn (string $permission): bool =>
        connectpro_has_permission($permission),
    'has_any_permission' => static fn (array $required): bool =>
        connectpro_has_any_permission($required),
    'has_all_permissions' => static fn (array $required): bool =>
        connectpro_has_all_permissions($required),
    'require_authentication' => static fn (): array =>
        connectpro_require_authentication(),
    'require_permission' => static fn (string $permission): array =>
        connectpro_require_permission($permission),
    'require_any_permission' => static fn (array $required): array =>
        connectpro_require_any_permission($required),
    'require_role' => static fn (string $role): array =>
        connectpro_require_role($role),
];
