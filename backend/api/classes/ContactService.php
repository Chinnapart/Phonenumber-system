<?php

declare(strict_types=1);

/**
 * ConnectPro Contact Service
 *
 * File: api/classes/ContactService.php
 *
 * Business logic for contact search, retrieval, creation, update,
 * deletion, favorites, validation, and activity logging.
 */
final class ContactService
{
    private const SORT_COLUMNS = [
        'name_asc' => 'c.display_name ASC',
        'name_desc' => 'c.display_name DESC',
        'department' => 'd.name ASC, c.display_name ASC',
        'recent' => 'c.updated_at DESC',
        'updated_desc' => 'c.updated_at DESC',
        'created_desc' => 'c.created_at DESC',
    ];

    private const CONTACT_FIELDS = [
        'employee_code',
        'display_name',
        'position',
        'department_id',
        'location_id',
        'extension_number',
        'mobile_number',
        'email',
        'ip_address',
        'status',
        'notes',
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
     * Search contacts with safe filtering, sorting, and pagination.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function search(array $filters = [], ?int $currentUserId = null): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $defaultPerPage = (int) ($this->config['default_per_page'] ?? 20);
        $maxPerPage = (int) ($this->config['max_per_page'] ?? 100);
        $perPage = min(
            max(1, (int) ($filters['per_page'] ?? $defaultPerPage)),
            max(1, $maxPerPage)
        );
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildSearchConditions(
            $filters,
            $currentUserId
        );

        $favoriteSelect = $currentUserId !== null
            ? 'CASE WHEN f.user_id IS NULL THEN 0 ELSE 1 END AS is_favorite'
            : '0 AS is_favorite';
        $favoriteJoin = $currentUserId !== null
            ? 'LEFT JOIN favorites f ON f.contact_id = c.id AND f.user_id = :favorite_user_id'
            : '';

        if ($currentUserId !== null) {
            $params['favorite_user_id'] = $currentUserId;
        }

        $sortKey = (string) ($filters['sort'] ?? 'name_asc');
        $orderBy = self::SORT_COLUMNS[$sortKey]
            ?? self::SORT_COLUMNS['name_asc'];

        $countSql = <<<SQL
            SELECT COUNT(DISTINCT c.id)
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
            {$favoriteJoin}
            {$whereSql}
            SQL;

        $countStatement = $this->db->prepare($countSql);
        $this->bindValues($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $sql = <<<SQL
            SELECT
                c.id,
                c.employee_code,
                c.display_name,
                c.position,
                c.department_id,
                d.code AS department_code,
                d.name AS department_name,
                c.location_id,
                l.code AS location_code,
                l.name AS location_name,
                c.extension_number,
                c.mobile_number,
                c.email,
                c.ip_address,
                c.status,
                c.notes,
                c.created_at,
                c.updated_at,
                {$favoriteSelect}
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
            {$favoriteJoin}
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
            fn (array $row): array => $this->normalizeContact($row),
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
    public function findById(int $contactId, ?int $currentUserId = null): ?array
    {
        if ($contactId < 1) {
            return null;
        }

        $favoriteSelect = $currentUserId !== null
            ? 'CASE WHEN f.user_id IS NULL THEN 0 ELSE 1 END AS is_favorite'
            : '0 AS is_favorite';
        $favoriteJoin = $currentUserId !== null
            ? 'LEFT JOIN favorites f ON f.contact_id = c.id AND f.user_id = :user_id'
            : '';

        $sql = <<<SQL
            SELECT
                c.*,
                d.code AS department_code,
                d.name AS department_name,
                l.code AS location_code,
                l.name AS location_name,
                {$favoriteSelect}
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
            {$favoriteJoin}
            WHERE c.id = :contact_id
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':contact_id', $contactId, PDO::PARAM_INT);

        if ($currentUserId !== null) {
            $statement->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        }

        $statement->execute();
        $contact = $statement->fetch();

        return is_array($contact) ? $this->normalizeContact($contact) : null;
    }

    /** @return array<string, mixed> */
    public function create(array $input, ?int $actorUserId = null): array
    {
        $data = $this->prepareContactData($input);
        $errors = $this->validate($data);

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => $errors],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        $this->assertUniqueFields($data);
        $this->assertReferencesExist($data);

        return $this->transactional(function () use ($data, $actorUserId): array {
            $columns = array_keys($data);
            $placeholders = array_map(
                static fn (string $column): string => ':' . $column,
                $columns
            );

            $sql = sprintf(
                'INSERT INTO contacts (%s, created_at, updated_at) '
                . 'VALUES (%s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $statement = $this->db->prepare($sql);
            $this->bindValues($statement, $data);
            $statement->execute();

            $contactId = (int) $this->db->lastInsertId();
            $this->writeActivityLog(
                $actorUserId,
                'create',
                'contact',
                $contactId,
                'สร้างผู้ติดต่อ ' . $data['display_name'],
                null,
                $this->redactSensitiveData($data)
            );

            $contact = $this->findById($contactId, $actorUserId);

            if ($contact === null) {
                throw new RuntimeException('Created contact could not be loaded.');
            }

            return $contact;
        });
    }

    /** @return array<string, mixed> */
    public function update(
        int $contactId,
        array $input,
        ?int $actorUserId = null
    ): array {
        $existing = $this->findById($contactId, $actorUserId);

        if ($existing === null) {
            throw new OutOfBoundsException('Contact not found.');
        }

        $data = $this->prepareContactData($input);
        $errors = $this->validate($data);

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => $errors],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        $this->assertUniqueFields($data, $contactId);
        $this->assertReferencesExist($data);

        return $this->transactional(function () use (
            $contactId,
            $data,
            $existing,
            $actorUserId
        ): array {
            $assignments = array_map(
                static fn (string $column): string => $column . ' = :' . $column,
                array_keys($data)
            );

            $sql = 'UPDATE contacts SET '
                . implode(', ', $assignments)
                . ', updated_at = CURRENT_TIMESTAMP WHERE id = :contact_id';

            $statement = $this->db->prepare($sql);
            $this->bindValues($statement, $data);
            $statement->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
            $statement->execute();

            $this->writeActivityLog(
                $actorUserId,
                'update',
                'contact',
                $contactId,
                'แก้ไขผู้ติดต่อ ' . $data['display_name'],
                $this->redactSensitiveData($existing),
                $this->redactSensitiveData($data)
            );

            $contact = $this->findById($contactId, $actorUserId);

            if ($contact === null) {
                throw new RuntimeException('Updated contact could not be loaded.');
            }

            return $contact;
        });
    }

    public function delete(int $contactId, ?int $actorUserId = null): bool
    {
        $existing = $this->findById($contactId, $actorUserId);

        if ($existing === null) {
            throw new OutOfBoundsException('Contact not found.');
        }

        return $this->transactional(function () use (
            $contactId,
            $actorUserId,
            $existing
        ): bool {
            $this->db->prepare(
                'DELETE FROM favorites WHERE contact_id = :contact_id'
            )->execute(['contact_id' => $contactId]);

            $statement = $this->db->prepare(
                'DELETE FROM contacts WHERE id = :contact_id'
            );
            $statement->execute(['contact_id' => $contactId]);

            $deleted = $statement->rowCount() === 1;

            if ($deleted) {
                $this->writeActivityLog(
                    $actorUserId,
                    'delete',
                    'contact',
                    $contactId,
                    'ลบผู้ติดต่อ ' . (string) $existing['display_name'],
                    $this->redactSensitiveData($existing),
                    null
                );
            }

            return $deleted;
        });
    }

    public function setFavorite(
        int $contactId,
        int $userId,
        bool $favorite
    ): bool {
        if ($contactId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Invalid contact or user ID.');
        }

        if ($this->findById($contactId, $userId) === null) {
            throw new OutOfBoundsException('Contact not found.');
        }

        if ($favorite) {
            $sql = <<<SQL
                INSERT INTO favorites (user_id, contact_id, created_at)
                VALUES (:user_id, :contact_id, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE created_at = created_at
                SQL;
        } else {
            $sql = <<<SQL
                DELETE FROM favorites
                WHERE user_id = :user_id AND contact_id = :contact_id
                SQL;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'contact_id' => $contactId,
        ]);

        return $favorite;
    }

    /** @return array<string, string> */
    public function validate(array $data): array
    {
        $errors = [];
        $employeeCode = trim((string) ($data['employee_code'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $extension = trim((string) ($data['extension_number'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $ipAddress = trim((string) ($data['ip_address'] ?? ''));
        $status = (string) ($data['status'] ?? '');

        if ($employeeCode === '' || mb_strlen($employeeCode) > 50) {
            $errors['employee_code'] = 'กรุณาระบุรหัสพนักงานไม่เกิน 50 ตัวอักษร';
        }

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 150) {
            $errors['display_name'] = 'ชื่อผู้ติดต่อต้องมี 2-150 ตัวอักษร';
        }

        if ((int) ($data['department_id'] ?? 0) < 1) {
            $errors['department_id'] = 'กรุณาเลือกแผนก';
        }

        if ((int) ($data['location_id'] ?? 0) < 1) {
            $errors['location_id'] = 'กรุณาเลือกสถานที่';
        }

        if ($extension === '' || !preg_match('/^[0-9+#*()\-]{1,20}$/', $extension)) {
            $errors['extension_number'] = 'รูปแบบเบอร์ต่อไม่ถูกต้อง';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        }

        if ($ipAddress !== '' && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $errors['ip_address'] = 'รูปแบบ IP Address ไม่ถูกต้อง';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'สถานะข้อมูลไม่ถูกต้อง';
        }

        if (mb_strlen((string) ($data['notes'] ?? '')) > 1000) {
            $errors['notes'] = 'หมายเหตุต้องไม่เกิน 1,000 ตัวอักษร';
        }

        return $errors;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildSearchConditions(
        array $filters,
        ?int $currentUserId
    ): array {
        $conditions = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $conditions[] = '('
                . 'c.display_name LIKE :search '
                . 'OR c.employee_code LIKE :search '
                . 'OR c.extension_number LIKE :search '
                . 'OR c.mobile_number LIKE :search '
                . 'OR c.email LIKE :search '
                . 'OR c.ip_address LIKE :search'
                . ')';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
        }

        foreach (['department_id', 'location_id'] as $field) {
            $value = (int) ($filters[$field] ?? 0);

            if ($value > 0) {
                $conditions[] = 'c.' . $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }

        $status = (string) ($filters['status'] ?? 'active');

        if (in_array($status, ['active', 'inactive'], true)) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $status;
        }

        if (!empty($filters['favorite_only'])) {
            if ($currentUserId === null) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'f.user_id IS NOT NULL';
            }
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    /** @return array<string, mixed> */
    private function prepareContactData(array $input): array
    {
        $data = [];

        foreach (self::CONTACT_FIELDS as $field) {
            $data[$field] = $input[$field] ?? null;
        }

        foreach ([
            'employee_code', 'display_name', 'position', 'extension_number',
            'mobile_number', 'email', 'ip_address', 'status', 'notes',
        ] as $field) {
            $value = $data[$field];
            $data[$field] = is_string($value) ? trim($value) : $value;
        }

        $data['department_id'] = (int) $data['department_id'];
        $data['location_id'] = (int) $data['location_id'];
        $data['status'] = $data['status'] ?: 'active';

        foreach (['position', 'mobile_number', 'email', 'ip_address', 'notes'] as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function assertUniqueFields(array $data, ?int $ignoreId = null): void
    {
        $sql = 'SELECT id, employee_code, email FROM contacts '
            . 'WHERE (employee_code = :employee_code '
            . 'OR (:email IS NOT NULL AND email = :email))';

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
        }

        $sql .= ' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':employee_code', $data['employee_code']);
        $statement->bindValue(
            ':email',
            $data['email'],
            $data['email'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );

        if ($ignoreId !== null) {
            $statement->bindValue(':ignore_id', $ignoreId, PDO::PARAM_INT);
        }

        $statement->execute();
        $duplicate = $statement->fetch();

        if (!is_array($duplicate)) {
            return;
        }

        $field = $duplicate['employee_code'] === $data['employee_code']
            ? 'employee_code'
            : 'email';

        throw new DomainException(sprintf('%s already exists.', $field));
    }

    private function assertReferencesExist(array $data): void
    {
        $checks = [
            'department_id' => ['departments', 'แผนก'],
            'location_id' => ['locations', 'สถานที่'],
        ];

        foreach ($checks as $field => [$table, $label]) {
            $sql = sprintf(
                'SELECT 1 FROM %s WHERE id = :id AND status = :status LIMIT 1',
                $table
            );
            $statement = $this->db->prepare($sql);
            $statement->execute([
                'id' => $data[$field],
                'status' => 'active',
            ]);

            if ($statement->fetchColumn() === false) {
                throw new DomainException($label . 'ไม่ถูกต้องหรือถูกปิดใช้งาน');
            }
        }
    }

    private function writeActivityLog(
        ?int $actorUserId,
        string $action,
        string $entityType,
        int $entityId,
        string $description,
        ?array $oldValues,
        ?array $newValues
    ): void {
        if ($actorUserId === null || empty($this->config['activity_log_enabled'])) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO activity_logs (
                user_id, action, entity_type, entity_id, description,
                old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, :entity_type, :entity_id, :description,
                :old_values, :new_values, :ip_address, :user_agent,
                CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'old_values' => $oldValues === null ? null : json_encode(
                $oldValues,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'new_values' => $newValues === null ? null : json_encode(
                $newValues,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'ip_address' => $this->resolveClientIp(),
            'user_agent' => mb_substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalizeContact(array $row): array
    {
        foreach (['id', 'department_id', 'location_id'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        $row['is_favorite'] = (bool) ($row['is_favorite'] ?? false);

        return $row;
    }

    /** @return array<string, mixed> */
    private function redactSensitiveData(array $data): array
    {
        unset($data['password'], $data['password_hash'], $data['csrf_token']);

        if (isset($data['mobile_number']) && is_string($data['mobile_number'])) {
            $data['mobile_number'] = preg_replace(
                '/.(?=.{4})/u',
                '*',
                $data['mobile_number']
            );
        }

        return $data;
    }

    private function resolveClientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function bindValues(PDOStatement $statement, array $values): void
    {
        foreach ($values as $key => $value) {
            $parameter = ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($parameter, $value, $type);
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
