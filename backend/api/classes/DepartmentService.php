<?php

declare(strict_types=1);

/**
 * ConnectPro Department Service
 *
 * File: api/classes/DepartmentService.php
 *
 * Business logic for department search, retrieval, creation, update,
 * deletion, validation, reference checks, and activity logging.
 */
final class DepartmentService
{
    private const SORT_COLUMNS = [
        'sort_order' => 'd.sort_order ASC, d.name ASC',
        'name_asc' => 'd.name ASC',
        'name_desc' => 'd.name DESC',
        'contacts_desc' => 'contact_count DESC, d.name ASC',
        'contacts_asc' => 'contact_count ASC, d.name ASC',
        'updated_desc' => 'd.updated_at DESC',
        'created_desc' => 'd.created_at DESC',
    ];

    private const DEPARTMENT_FIELDS = [
        'code',
        'name',
        'location_id',
        'sort_order',
        'status',
        'description',
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
     * Search departments with contact counts, filtering, and pagination.
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

        [$whereSql, $havingSql, $params] = $this->buildSearchConditions(
            $filters
        );

        $sortKey = (string) ($filters['sort'] ?? 'sort_order');
        $orderBy = self::SORT_COLUMNS[$sortKey]
            ?? self::SORT_COLUMNS['sort_order'];

        $countSql = <<<SQL
            SELECT COUNT(*)
            FROM (
                SELECT d.id, COUNT(c.id) AS contact_count
                FROM departments d
                LEFT JOIN locations l ON l.id = d.location_id
                LEFT JOIN contacts c ON c.department_id = d.id
                {$whereSql}
                GROUP BY d.id
                {$havingSql}
            ) department_results
            SQL;

        $countStatement = $this->db->prepare($countSql);
        $this->bindValues($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $sql = <<<SQL
            SELECT
                d.id,
                d.code,
                d.name,
                d.description,
                d.location_id,
                l.code AS location_code,
                l.name AS location_name,
                d.sort_order,
                d.status,
                d.created_at,
                d.updated_at,
                COUNT(c.id) AS contact_count,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END)
                    AS active_contact_count
            FROM departments d
            LEFT JOIN locations l ON l.id = d.location_id
            LEFT JOIN contacts c ON c.department_id = d.id
            {$whereSql}
            GROUP BY
                d.id, d.code, d.name, d.description, d.location_id,
                l.code, l.name, d.sort_order, d.status,
                d.created_at, d.updated_at
            {$havingSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            fn (array $row): array => $this->normalizeDepartment($row),
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
    public function findById(int $departmentId): ?array
    {
        if ($departmentId < 1) {
            return null;
        }

        $sql = <<<SQL
            SELECT
                d.id,
                d.code,
                d.name,
                d.description,
                d.location_id,
                l.code AS location_code,
                l.name AS location_name,
                d.sort_order,
                d.status,
                d.created_at,
                d.updated_at,
                COUNT(c.id) AS contact_count,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END)
                    AS active_contact_count
            FROM departments d
            LEFT JOIN locations l ON l.id = d.location_id
            LEFT JOIN contacts c ON c.department_id = d.id
            WHERE d.id = :department_id
            GROUP BY
                d.id, d.code, d.name, d.description, d.location_id,
                l.code, l.name, d.sort_order, d.status,
                d.created_at, d.updated_at
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(
            ':department_id',
            $departmentId,
            PDO::PARAM_INT
        );
        $statement->execute();
        $department = $statement->fetch();

        return is_array($department)
            ? $this->normalizeDepartment($department)
            : null;
    }

    /** @return list<array<string, mixed>> */
    public function getActiveOptions(?int $locationId = null): array
    {
        $conditions = ['d.status = :status'];
        $params = ['status' => 'active'];

        if ($locationId !== null && $locationId > 0) {
            $conditions[] = 'd.location_id = :location_id';
            $params['location_id'] = $locationId;
        }

        $sql = 'SELECT d.id, d.code, d.name, d.location_id '
            . 'FROM departments d WHERE '
            . implode(' AND ', $conditions)
            . ' ORDER BY d.sort_order ASC, d.name ASC';

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->execute();

        return array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['location_id'] = $row['location_id'] === null
                    ? null
                    : (int) $row['location_id'];

                return $row;
            },
            $statement->fetchAll()
        );
    }

    /** @return array<string, mixed> */
    public function create(array $input, ?int $actorUserId = null): array
    {
        $data = $this->prepareDepartmentData($input);
        $errors = $this->validate($data);

        if ($errors !== []) {
            throw $this->validationException($errors);
        }

        $this->assertUniqueFields($data);
        $this->assertLocationExists($data['location_id']);

        return $this->transactional(function () use ($data, $actorUserId): array {
            $columns = array_keys($data);
            $placeholders = array_map(
                static fn (string $column): string => ':' . $column,
                $columns
            );

            $sql = sprintf(
                'INSERT INTO departments (%s, created_at, updated_at) '
                . 'VALUES (%s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $statement = $this->db->prepare($sql);
            $this->bindValues($statement, $data);
            $statement->execute();

            $departmentId = (int) $this->db->lastInsertId();
            $this->writeActivityLog(
                $actorUserId,
                'create',
                $departmentId,
                'สร้างแผนก ' . $data['name'],
                null,
                $data
            );

            $department = $this->findById($departmentId);

            if ($department === null) {
                throw new RuntimeException(
                    'Created department could not be loaded.'
                );
            }

            return $department;
        });
    }

    /** @return array<string, mixed> */
    public function update(
        int $departmentId,
        array $input,
        ?int $actorUserId = null
    ): array {
        $existing = $this->findById($departmentId);

        if ($existing === null) {
            throw new OutOfBoundsException('Department not found.');
        }

        $data = $this->prepareDepartmentData($input);
        $errors = $this->validate($data);

        if ($errors !== []) {
            throw $this->validationException($errors);
        }

        $this->assertUniqueFields($data, $departmentId);
        $this->assertLocationExists($data['location_id']);

        return $this->transactional(function () use (
            $departmentId,
            $data,
            $existing,
            $actorUserId
        ): array {
            $assignments = array_map(
                static fn (string $column): string => $column . ' = :' . $column,
                array_keys($data)
            );

            $sql = 'UPDATE departments SET '
                . implode(', ', $assignments)
                . ', updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :department_id';

            $statement = $this->db->prepare($sql);
            $this->bindValues($statement, $data);
            $statement->bindValue(
                ':department_id',
                $departmentId,
                PDO::PARAM_INT
            );
            $statement->execute();

            $this->writeActivityLog(
                $actorUserId,
                'update',
                $departmentId,
                'แก้ไขแผนก ' . $data['name'],
                $existing,
                $data
            );

            $department = $this->findById($departmentId);

            if ($department === null) {
                throw new RuntimeException(
                    'Updated department could not be loaded.'
                );
            }

            return $department;
        });
    }

    public function delete(int $departmentId, ?int $actorUserId = null): bool
    {
        $existing = $this->findById($departmentId);

        if ($existing === null) {
            throw new OutOfBoundsException('Department not found.');
        }

        if ((int) $existing['contact_count'] > 0) {
            throw new DomainException(
                'ไม่สามารถลบแผนกที่ยังมีผู้ติดต่ออยู่ได้'
            );
        }

        return $this->transactional(function () use (
            $departmentId,
            $actorUserId,
            $existing
        ): bool {
            $statement = $this->db->prepare(
                'DELETE FROM departments WHERE id = :department_id '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM contacts WHERE department_id = :check_id'
                . ')'
            );
            $statement->execute([
                'department_id' => $departmentId,
                'check_id' => $departmentId,
            ]);

            $deleted = $statement->rowCount() === 1;

            if (!$deleted) {
                throw new DomainException(
                    'ไม่สามารถลบแผนกได้ เนื่องจากมีข้อมูลอ้างอิงอยู่'
                );
            }

            $this->writeActivityLog(
                $actorUserId,
                'delete',
                $departmentId,
                'ลบแผนก ' . (string) $existing['name'],
                $existing,
                null
            );

            return true;
        });
    }

    /** @return array<string, string> */
    public function validate(array $data): array
    {
        $errors = [];
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $status = (string) ($data['status'] ?? '');
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $description = (string) ($data['description'] ?? '');

        if (
            $code === ''
            || mb_strlen($code) > 50
            || preg_match('/^[A-Za-z0-9._-]+$/', $code) !== 1
        ) {
            $errors['code'] = 'รหัสแผนกต้องเป็นตัวอักษรอังกฤษ ตัวเลข จุด ขีดกลาง หรือขีดล่าง';
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'ชื่อแผนกต้องมี 2-150 ตัวอักษร';
        }

        $locationId = $data['location_id'] ?? null;

        if ($locationId !== null && (int) $locationId < 1) {
            $errors['location_id'] = 'สถานที่หลักไม่ถูกต้อง';
        }

        if ($sortOrder < 0 || $sortOrder > 9999) {
            $errors['sort_order'] = 'ลำดับการแสดงต้องอยู่ระหว่าง 0-9999';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'สถานะแผนกไม่ถูกต้อง';
        }

        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'รายละเอียดต้องไม่เกิน 1,000 ตัวอักษร';
        }

        return $errors;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function buildSearchConditions(array $filters): array
    {
        $conditions = [];
        $havingConditions = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $conditions[] = '('
                . 'd.name LIKE :search '
                . 'OR d.code LIKE :search '
                . 'OR d.description LIKE :search'
                . ')';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
        }

        $locationId = (int) ($filters['location_id'] ?? 0);

        if ($locationId > 0) {
            $conditions[] = 'd.location_id = :location_id';
            $params['location_id'] = $locationId;
        }

        $status = (string) ($filters['status'] ?? 'active');

        if (in_array($status, ['active', 'inactive'], true)) {
            $conditions[] = 'd.status = :status';
            $params['status'] = $status;
        }

        if (!empty($filters['has_contacts'])) {
            $havingConditions[] = 'COUNT(c.id) > 0';
        }

        $contactRange = (string) ($filters['contact_range'] ?? '');

        $rangeCondition = match ($contactRange) {
            'empty' => 'COUNT(c.id) = 0',
            '1-10' => 'COUNT(c.id) BETWEEN 1 AND 10',
            '11-50' => 'COUNT(c.id) BETWEEN 11 AND 50',
            '51-100' => 'COUNT(c.id) BETWEEN 51 AND 100',
            '101+' => 'COUNT(c.id) >= 101',
            default => null,
        };

        if ($rangeCondition !== null) {
            $havingConditions[] = $rangeCondition;
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $havingConditions === []
                ? ''
                : 'HAVING ' . implode(' AND ', $havingConditions),
            $params,
        ];
    }

    /** @return array<string, mixed> */
    private function prepareDepartmentData(array $input): array
    {
        $data = [];

        foreach (self::DEPARTMENT_FIELDS as $field) {
            $data[$field] = $input[$field] ?? null;
        }

        $data['code'] = strtoupper(trim((string) $data['code']));
        $data['name'] = trim((string) $data['name']);
        $data['description'] = trim((string) ($data['description'] ?? ''));
        $data['location_id'] = $data['location_id'] === null
            || $data['location_id'] === ''
            ? null
            : (int) $data['location_id'];
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['status'] = trim((string) ($data['status'] ?? 'active'));

        if ($data['description'] === '') {
            $data['description'] = null;
        }

        return $data;
    }

    private function assertUniqueFields(
        array $data,
        ?int $ignoreId = null
    ): void {
        $sql = 'SELECT id, code, name FROM departments '
            . 'WHERE (code = :code OR name = :name)';

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
        }

        $sql .= ' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':code', $data['code'], PDO::PARAM_STR);
        $statement->bindValue(':name', $data['name'], PDO::PARAM_STR);

        if ($ignoreId !== null) {
            $statement->bindValue(':ignore_id', $ignoreId, PDO::PARAM_INT);
        }

        $statement->execute();
        $duplicate = $statement->fetch();

        if (!is_array($duplicate)) {
            return;
        }

        $field = strcasecmp((string) $duplicate['code'], $data['code']) === 0
            ? 'code'
            : 'name';

        throw new DomainException($field . ' already exists.');
    }

    private function assertLocationExists(?int $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT 1 FROM locations '
            . 'WHERE id = :location_id AND status = :status LIMIT 1'
        );
        $statement->execute([
            'location_id' => $locationId,
            'status' => 'active',
        ]);

        if ($statement->fetchColumn() === false) {
            throw new DomainException('สถานที่ไม่ถูกต้องหรือถูกปิดใช้งาน');
        }
    }

    private function validationException(array $errors): InvalidArgumentException
    {
        return new InvalidArgumentException(json_encode(
            ['validation_errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function writeActivityLog(
        ?int $actorUserId,
        string $action,
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
                :user_id, :action, 'department', :entity_id, :description,
                :old_values, :new_values, :ip_address, :user_agent,
                CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $actorUserId,
            'action' => $action,
            'entity_id' => $entityId,
            'description' => $description,
            'old_values' => $this->encodeLogValues($oldValues),
            'new_values' => $this->encodeLogValues($newValues),
            'ip_address' => $this->resolveClientIp(),
            'user_agent' => mb_substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ),
        ]);
    }

    private function encodeLogValues(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        unset($values['password'], $values['password_hash'], $values['csrf_token']);

        return json_encode(
            $values,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string, mixed> */
    private function normalizeDepartment(array $row): array
    {
        foreach (['id', 'location_id', 'sort_order'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        $row['contact_count'] = (int) ($row['contact_count'] ?? 0);
        $row['active_contact_count'] = (int) (
            $row['active_contact_count'] ?? 0
        );

        return $row;
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
