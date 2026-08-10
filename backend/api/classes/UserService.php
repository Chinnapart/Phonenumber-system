<?php

declare(strict_types=1);

/**
 * ConnectPro User Service
 *
 * File: api/classes/UserService.php
 *
 * Business logic for user search, retrieval, creation, updates,
 * status and role management, password reset, deletion, session
 * revocation, validation, and activity logging.
 *
 * Authorization must be enforced by the API controller through
 * config/permissions.php before calling administrative methods.
 */
final class UserService
{
    private const SORT_COLUMNS = [
        'name_asc' => 'u.display_name ASC',
        'name_desc' => 'u.display_name DESC',
        'username_asc' => 'u.username ASC',
        'role' => 'u.role ASC, u.display_name ASC',
        'department' => 'd.name ASC, u.display_name ASC',
        'last_login_desc' => 'u.last_login_at DESC',
        'created_desc' => 'u.created_at DESC',
        'updated_desc' => 'u.updated_at DESC',
    ];

    private const ALLOWED_ROLES = [
        'user',
        'editor',
        'admin',
        'super_admin',
    ];

    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
        'suspended',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Search users with filtering, safe sorting, and pagination.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function search(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $defaultPerPage = max(
            1,
            (int) ($this->config['default_per_page'] ?? 20)
        );
        $maxPerPage = max(1, (int) ($this->config['max_per_page'] ?? 100));
        $perPage = min(
            max(1, (int) ($filters['per_page'] ?? $defaultPerPage)),
            $maxPerPage
        );
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildSearchConditions($filters);
        $sortKey = (string) ($filters['sort'] ?? 'name_asc');
        $orderBy = self::SORT_COLUMNS[$sortKey]
            ?? self::SORT_COLUMNS['name_asc'];

        $countStatement = $this->db->prepare(
            "SELECT COUNT(*) FROM users u "
            . "LEFT JOIN departments d ON d.id = u.department_id "
            . $whereSql
        );
        $this->bindValues($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $sql = <<<SQL
            SELECT
                u.id,
                u.username,
                u.email,
                u.display_name,
                u.role,
                u.status,
                u.department_id,
                d.code AS department_code,
                d.name AS department_name,
                u.must_change_password,
                u.failed_login_attempts,
                u.locked_until,
                u.last_login_at,
                u.last_login_ip,
                u.password_changed_at,
                u.created_at,
                u.updated_at
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            fn (array $row): array => $this->normalizeUser($row),
            $statement->fetchAll()
        );
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $sql = <<<SQL
            SELECT
                u.id,
                u.username,
                u.email,
                u.display_name,
                u.role,
                u.status,
                u.department_id,
                d.code AS department_code,
                d.name AS department_name,
                u.must_change_password,
                u.failed_login_attempts,
                u.locked_until,
                u.last_login_at,
                u.last_login_ip,
                u.password_changed_at,
                u.created_at,
                u.updated_at
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = :user_id
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
        $user = $statement->fetch();

        return is_array($user) ? $this->normalizeUser($user) : null;
    }

    /** @return array<string, mixed> */
    public function create(array $input, ?int $actorUserId = null): array
    {
        $data = $this->prepareUserData($input, true);
        $errors = $this->validate($data, true);

        if ($errors !== []) {
            throw $this->validationException($errors);
        }

        $this->assertUniqueIdentity($data);
        $this->assertDepartmentExists($data['department_id']);

        return $this->transactional(function () use ($data, $actorUserId): array {
            $passwordHash = password_hash(
                (string) $data['password'],
                $this->passwordAlgorithm(),
                $this->passwordOptions()
            );

            $sql = <<<SQL
                INSERT INTO users (
                    username, email, display_name, password_hash, role,
                    status, department_id, must_change_password,
                    failed_login_attempts, created_at, updated_at
                ) VALUES (
                    :username, :email, :display_name, :password_hash, :role,
                    :status, :department_id, :must_change_password,
                    0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                SQL;

            $statement = $this->db->prepare($sql);
            $statement->bindValue(':username', $data['username']);
            $statement->bindValue(':email', $data['email']);
            $statement->bindValue(':display_name', $data['display_name']);
            $statement->bindValue(':password_hash', $passwordHash);
            $statement->bindValue(':role', $data['role']);
            $statement->bindValue(':status', $data['status']);
            $statement->bindValue(
                ':department_id',
                $data['department_id'],
                $data['department_id'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_INT
            );
            $statement->bindValue(
                ':must_change_password',
                $data['must_change_password'] ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->execute();

            $userId = (int) $this->db->lastInsertId();
            $this->writeActivityLog(
                $actorUserId,
                'create',
                $userId,
                'สร้างบัญชีผู้ใช้ ' . $data['username'],
                null,
                $this->safeAuditData($data)
            );

            $user = $this->findById($userId);

            if ($user === null) {
                throw new RuntimeException('Created user could not be loaded.');
            }

            return $user;
        });
    }

    /** @return array<string, mixed> */
    public function update(
        int $userId,
        array $input,
        ?int $actorUserId = null
    ): array {
        $existing = $this->findById($userId);

        if ($existing === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $data = $this->prepareUserData($input, false, $existing);
        $errors = $this->validate($data, false);

        if ($errors !== []) {
            throw $this->validationException($errors);
        }

        $this->assertUniqueIdentity($data, $userId);
        $this->assertDepartmentExists($data['department_id']);
        $this->assertPrivilegedAccountChangeIsSafe($existing, $data);

        return $this->transactional(function () use (
            $userId,
            $data,
            $existing,
            $actorUserId
        ): array {
            $sql = <<<SQL
                UPDATE users SET
                    username = :username,
                    email = :email,
                    display_name = :display_name,
                    role = :role,
                    status = :status,
                    department_id = :department_id,
                    must_change_password = :must_change_password,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
                SQL;

            $statement = $this->db->prepare($sql);
            $statement->bindValue(':username', $data['username']);
            $statement->bindValue(':email', $data['email']);
            $statement->bindValue(':display_name', $data['display_name']);
            $statement->bindValue(':role', $data['role']);
            $statement->bindValue(':status', $data['status']);
            $statement->bindValue(
                ':department_id',
                $data['department_id'],
                $data['department_id'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_INT
            );
            $statement->bindValue(
                ':must_change_password',
                $data['must_change_password'] ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->execute();

            if (
                $existing['status'] !== $data['status']
                || $existing['role'] !== $data['role']
            ) {
                $this->revokeUserSessions($userId);
            }

            $this->writeActivityLog(
                $actorUserId,
                'update',
                $userId,
                'แก้ไขบัญชีผู้ใช้ ' . $data['username'],
                $this->safeAuditData($existing),
                $this->safeAuditData($data)
            );

            $user = $this->findById($userId);

            if ($user === null) {
                throw new RuntimeException('Updated user could not be loaded.');
            }

            return $user;
        });
    }

    /** @return array<string, mixed> */
    public function setStatus(
        int $userId,
        string $status,
        int $actorUserId
    ): array {
        $user = $this->findById($userId);

        if ($user === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $status = strtolower(trim($status));

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid user status.');
        }

        if ($userId === $actorUserId && $status !== 'active') {
            throw new DomainException('ไม่สามารถปิดใช้งานบัญชีของตนเองได้');
        }

        $next = array_merge($user, ['status' => $status]);
        $this->assertPrivilegedAccountChangeIsSafe($user, $next);

        return $this->transactional(function () use (
            $userId,
            $status,
            $actorUserId,
            $user
        ): array {
            $statement = $this->db->prepare(
                'UPDATE users SET status = :status, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :user_id'
            );
            $statement->execute([
                'status' => $status,
                'user_id' => $userId,
            ]);

            if ($status !== 'active') {
                $this->revokeUserSessions($userId);
            }

            $this->writeActivityLog(
                $actorUserId,
                'status_update',
                $userId,
                'เปลี่ยนสถานะบัญชีผู้ใช้เป็น ' . $status,
                ['status' => $user['status']],
                ['status' => $status]
            );

            return $this->findById($userId)
                ?? throw new RuntimeException('Updated user could not be loaded.');
        });
    }

    /** @return array<string, mixed> */
    public function assignRole(
        int $userId,
        string $role,
        int $actorUserId
    ): array {
        $user = $this->findById($userId);

        if ($user === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $role = strtolower(trim($role));

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid user role.');
        }

        $next = array_merge($user, ['role' => $role]);
        $this->assertPrivilegedAccountChangeIsSafe($user, $next);

        return $this->transactional(function () use (
            $userId,
            $role,
            $actorUserId,
            $user
        ): array {
            $statement = $this->db->prepare(
                'UPDATE users SET role = :role, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :user_id'
            );
            $statement->execute([
                'role' => $role,
                'user_id' => $userId,
            ]);

            $this->revokeUserSessions($userId);
            $this->writeActivityLog(
                $actorUserId,
                'role_update',
                $userId,
                'เปลี่ยน Role บัญชีผู้ใช้เป็น ' . $role,
                ['role' => $user['role']],
                ['role' => $role]
            );

            return $this->findById($userId)
                ?? throw new RuntimeException('Updated user could not be loaded.');
        });
    }

    public function resetPassword(
        int $userId,
        string $newPassword,
        int $actorUserId,
        bool $mustChangePassword = true
    ): void {
        $user = $this->findById($userId);

        if ($user === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $errors = $this->validatePassword($newPassword, $user);

        if ($errors !== []) {
            throw $this->validationException([
                'password' => implode(' ', $errors),
            ]);
        }

        $this->transactional(function () use (
            $userId,
            $newPassword,
            $actorUserId,
            $mustChangePassword,
            $user
        ): void {
            $passwordHash = password_hash(
                $newPassword,
                $this->passwordAlgorithm(),
                $this->passwordOptions()
            );
            $statement = $this->db->prepare(
                'UPDATE users SET password_hash = :password_hash, '
                . 'must_change_password = :must_change_password, '
                . 'failed_login_attempts = 0, locked_until = NULL, '
                . 'password_changed_at = CURRENT_TIMESTAMP, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :user_id'
            );
            $statement->bindValue(':password_hash', $passwordHash);
            $statement->bindValue(
                ':must_change_password',
                $mustChangePassword ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->execute();

            $this->revokeUserSessions($userId);
            $this->writeActivityLog(
                $actorUserId,
                'password_reset',
                $userId,
                'รีเซ็ตรหัสผ่านบัญชีผู้ใช้ ' . $user['username']
            );
        });
    }

    public function unlock(int $userId, int $actorUserId): void
    {
        if ($this->findById($userId) === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $statement = $this->db->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :user_id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();

        $this->writeActivityLog(
            $actorUserId,
            'account_unlocked',
            $userId,
            'ปลดล็อกบัญชีผู้ใช้'
        );
    }

    public function delete(int $userId, int $actorUserId): bool
    {
        if ($userId === $actorUserId) {
            throw new DomainException('ไม่สามารถลบบัญชีของตนเองได้');
        }

        $user = $this->findById($userId);

        if ($user === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $this->assertPrivilegedAccountCanBeRemoved($user);

        return $this->transactional(function () use (
            $userId,
            $actorUserId,
            $user
        ): bool {
            $this->revokeUserSessions($userId);

            if ($this->tableEnabled('favorites_table_enabled', true)) {
                $statement = $this->db->prepare(
                    'DELETE FROM favorites WHERE user_id = :user_id'
                );
                $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $statement->execute();
            }

            $statement = $this->db->prepare(
                'DELETE FROM users WHERE id = :user_id'
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->execute();

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('User could not be deleted.');
            }

            $this->writeActivityLog(
                $actorUserId,
                'delete',
                $userId,
                'ลบบัญชีผู้ใช้ ' . $user['username'],
                $this->safeAuditData($user),
                null
            );

            return true;
        });
    }

    /** @return array<string, string> */
    public function validate(array $data, bool $creating = false): array
    {
        $errors = [];
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $role = (string) ($data['role'] ?? '');
        $status = (string) ($data['status'] ?? '');

        if (
            mb_strlen($username) < 3
            || mb_strlen($username) > 100
            || preg_match('/^[A-Za-z0-9._-]+$/', $username) !== 1
        ) {
            $errors['username'] = 'Username ต้องมี 3-100 ตัว และใช้ตัวอักษรอังกฤษ ตัวเลข จุด ขีดกลาง หรือขีดล่าง';
        }

        if (
            $email === ''
            || mb_strlen($email) > 190
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        }

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 150) {
            $errors['display_name'] = 'ชื่อที่แสดงต้องมี 2-150 ตัวอักษร';
        }

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            $errors['role'] = 'Role ไม่ถูกต้อง';
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $errors['status'] = 'สถานะบัญชีไม่ถูกต้อง';
        }

        $departmentId = $data['department_id'] ?? null;

        if ($departmentId !== null && (int) $departmentId < 1) {
            $errors['department_id'] = 'แผนกไม่ถูกต้อง';
        }

        if ($creating) {
            $passwordErrors = $this->validatePassword(
                (string) ($data['password'] ?? ''),
                $data
            );

            if ($passwordErrors !== []) {
                $errors['password'] = implode(' ', $passwordErrors);
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public function validatePassword(string $password, array $user = []): array
    {
        $errors = [];
        $minimumLength = max(
            8,
            (int) ($this->config['password_min_length'] ?? 12)
        );

        if (mb_strlen($password) < $minimumLength) {
            $errors[] = sprintf(
                'รหัสผ่านต้องมีอย่างน้อย %d ตัวอักษร',
                $minimumLength
            );
        }

        if (preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'ต้องมีตัวพิมพ์ใหญ่อย่างน้อย 1 ตัว';
        }

        if (preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'ต้องมีตัวพิมพ์เล็กอย่างน้อย 1 ตัว';
        }

        if (preg_match('/\d/', $password) !== 1) {
            $errors[] = 'ต้องมีตัวเลขอย่างน้อย 1 ตัว';
        }

        if (preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            $errors[] = 'ต้องมีอักขระพิเศษอย่างน้อย 1 ตัว';
        }

        $lowerPassword = mb_strtolower($password);

        foreach (['username', 'email', 'display_name'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));

            if ($field === 'email' && str_contains($value, '@')) {
                $value = strstr($value, '@', true) ?: '';
            }

            if (
                mb_strlen($value) >= 4
                && str_contains($lowerPassword, mb_strtolower($value))
            ) {
                $errors[] = 'รหัสผ่านต้องไม่มีข้อมูลส่วนตัวของผู้ใช้';
                break;
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, int> */
    public function getRoleCounts(): array
    {
        $statement = $this->db->query(
            'SELECT role, COUNT(*) AS total FROM users GROUP BY role'
        );
        $counts = array_fill_keys(self::ALLOWED_ROLES, 0);

        foreach ($statement->fetchAll() as $row) {
            $role = (string) $row['role'];

            if (array_key_exists($role, $counts)) {
                $counts[$role] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildSearchConditions(array $filters): array
    {
        $conditions = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $conditions[] = '('
                . 'u.username LIKE :search '
                . 'OR u.email LIKE :search '
                . 'OR u.display_name LIKE :search'
                . ')';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
        }

        $role = strtolower(trim((string) ($filters['role'] ?? '')));

        if (in_array($role, self::ALLOWED_ROLES, true)) {
            $conditions[] = 'u.role = :role';
            $params['role'] = $role;
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        if (in_array($status, self::ALLOWED_STATUSES, true)) {
            $conditions[] = 'u.status = :status';
            $params['status'] = $status;
        }

        $departmentId = (int) ($filters['department_id'] ?? 0);

        if ($departmentId > 0) {
            $conditions[] = 'u.department_id = :department_id';
            $params['department_id'] = $departmentId;
        }

        if (!empty($filters['locked_only'])) {
            $conditions[] = 'u.locked_until IS NOT NULL '
                . 'AND u.locked_until > CURRENT_TIMESTAMP';
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    /** @return array<string, mixed> */
    private function prepareUserData(
        array $input,
        bool $creating,
        array $existing = []
    ): array {
        $data = [
            'username' => trim((string) (
                $input['username'] ?? $existing['username'] ?? ''
            )),
            'email' => mb_strtolower(trim((string) (
                $input['email'] ?? $existing['email'] ?? ''
            ))),
            'display_name' => trim((string) (
                $input['display_name'] ?? $existing['display_name'] ?? ''
            )),
            'role' => strtolower(trim((string) (
                $input['role'] ?? $existing['role'] ?? 'user'
            ))),
            'status' => strtolower(trim((string) (
                $input['status'] ?? $existing['status'] ?? 'active'
            ))),
            'department_id' => $input['department_id']
                ?? $existing['department_id']
                ?? null,
            'must_change_password' => filter_var(
                $input['must_change_password']
                    ?? $existing['must_change_password']
                    ?? $creating,
                FILTER_VALIDATE_BOOL
            ),
        ];

        $data['department_id'] = $data['department_id'] === null
            || $data['department_id'] === ''
            ? null
            : (int) $data['department_id'];

        if ($creating) {
            $data['password'] = (string) ($input['password'] ?? '');
        }

        return $data;
    }

    private function assertUniqueIdentity(
        array $data,
        ?int $ignoreUserId = null
    ): void {
        $sql = 'SELECT id, username, email FROM users '
            . 'WHERE (username = :username OR email = :email)';

        if ($ignoreUserId !== null) {
            $sql .= ' AND id <> :ignore_id';
        }

        $sql .= ' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':username', $data['username']);
        $statement->bindValue(':email', $data['email']);

        if ($ignoreUserId !== null) {
            $statement->bindValue(':ignore_id', $ignoreUserId, PDO::PARAM_INT);
        }

        $statement->execute();
        $duplicate = $statement->fetch();

        if (!is_array($duplicate)) {
            return;
        }

        $field = strcasecmp(
            (string) $duplicate['username'],
            (string) $data['username']
        ) === 0 ? 'username' : 'email';

        throw new DomainException($field . ' already exists.');
    }

    private function assertDepartmentExists(?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT 1 FROM departments WHERE id = :department_id '
            . 'AND status = :status LIMIT 1'
        );
        $statement->execute([
            'department_id' => $departmentId,
            'status' => 'active',
        ]);

        if ($statement->fetchColumn() === false) {
            throw new DomainException('แผนกไม่ถูกต้องหรือถูกปิดใช้งาน');
        }
    }

    private function assertPrivilegedAccountChangeIsSafe(
        array $existing,
        array $next
    ): void {
        $wasPrivileged = in_array(
            (string) ($existing['role'] ?? ''),
            ['admin', 'super_admin'],
            true
        ) && ($existing['status'] ?? '') === 'active';
        $willRemainPrivileged = in_array(
            (string) ($next['role'] ?? ''),
            ['admin', 'super_admin'],
            true
        ) && ($next['status'] ?? '') === 'active';

        if ($wasPrivileged && !$willRemainPrivileged) {
            $this->assertAnotherActiveAdministratorExists((int) $existing['id']);
        }
    }

    private function assertPrivilegedAccountCanBeRemoved(array $user): void
    {
        if (
            in_array((string) $user['role'], ['admin', 'super_admin'], true)
            && $user['status'] === 'active'
        ) {
            $this->assertAnotherActiveAdministratorExists((int) $user['id']);
        }
    }

    private function assertAnotherActiveAdministratorExists(int $excludeUserId): void
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE id <> :user_id "
            . "AND status = 'active' AND role IN ('admin', 'super_admin')"
        );
        $statement->bindValue(':user_id', $excludeUserId, PDO::PARAM_INT);
        $statement->execute();

        if ((int) $statement->fetchColumn() < 1) {
            throw new DomainException(
                'ต้องมีบัญชี Administrator ที่ Active อย่างน้อย 1 บัญชี'
            );
        }
    }

    private function revokeUserSessions(int $userId): void
    {
        if (!$this->tableEnabled('session_table_enabled', false)) {
            return;
        }

        $statement = $this->db->prepare(
            'DELETE FROM user_sessions WHERE user_id = :user_id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function tableEnabled(string $key, bool $default): bool
    {
        return (bool) ($this->config[$key] ?? $default);
    }

    /** @return array<string, mixed> */
    private function normalizeUser(array $row): array
    {
        foreach (['id', 'department_id', 'failed_login_attempts'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        $row['must_change_password'] = (bool) (
            $row['must_change_password'] ?? false
        );
        $row['is_locked'] = !empty($row['locked_until'])
            && strtotime((string) $row['locked_until']) > time();
        unset($row['password_hash']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function safeAuditData(array $data): array
    {
        unset(
            $data['password'],
            $data['password_hash'],
            $data['csrf_token'],
            $data['remember_token']
        );

        return $data;
    }

    private function validationException(array $errors): InvalidArgumentException
    {
        return new InvalidArgumentException(json_encode(
            ['validation_errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function passwordAlgorithm(): string|int|null
    {
        return $this->config['password_algorithm'] ?? PASSWORD_DEFAULT;
    }

    /** @return array<string, int> */
    private function passwordOptions(): array
    {
        $options = [];

        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT) {
            $options['cost'] = max(
                10,
                min(14, (int) ($this->config['bcrypt_cost'] ?? 12))
            );
        }

        return $options;
    }

    private function writeActivityLog(
        ?int $actorUserId,
        string $action,
        int $entityId,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        if (empty($this->config['activity_log_enabled'])) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO activity_logs (
                user_id, action, entity_type, entity_id, description,
                old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, 'user', :entity_id, :description,
                :old_values, :new_values, :ip_address, :user_agent,
                CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(
            ':user_id',
            $actorUserId,
            $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':action', $action);
        $statement->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $statement->bindValue(':description', $description);
        $statement->bindValue(
            ':old_values',
            $this->encodeAuditValues($oldValues),
            $oldValues === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':new_values',
            $this->encodeAuditValues($newValues),
            $newValues === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(':ip_address', $this->resolveClientIp());
        $statement->bindValue(
            ':user_agent',
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
        );
        $statement->execute();
    }

    private function encodeAuditValues(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        return json_encode(
            $this->safeAuditData($values),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    private function resolveClientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    private function bindValues(PDOStatement $statement, array $values): void
    {
        foreach ($values as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue(
                ':' . ltrim((string) $key, ':'),
                $value,
                $type
            );
        }
    }

    /** @return mixed */
    private function transactional(callable $callback): mixed
    {
        $startedTransaction = !$this->db->inTransaction();

        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $callback();

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }
}
