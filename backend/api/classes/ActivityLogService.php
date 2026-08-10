<?php

declare(strict_types=1);

/**
 * ConnectPro Activity Log Service
 *
 * File: api/classes/ActivityLogService.php
 *
 * Responsibilities:
 * - Search and paginate audit records
 * - Retrieve a single activity with decoded old/new values
 * - Record security-safe activity events
 * - Provide action, entity, and user filter options
 * - Export filtered activity logs to UTF-8 CSV
 * - Delete expired records according to the retention policy
 *
 * Authorization must be checked by the API controller before calling
 * administrative methods such as search(), exportCsv(), and cleanup().
 */
final class ActivityLogService
{
    private const SORT_COLUMNS = [
        'newest' => 'a.created_at DESC, a.id DESC',
        'oldest' => 'a.created_at ASC, a.id ASC',
        'action' => 'a.action ASC, a.created_at DESC',
        'user' => 'u.display_name ASC, a.created_at DESC',
        'entity' => 'a.entity_type ASC, a.created_at DESC',
    ];

    private const ALLOWED_ACTIONS = [
        'create',
        'update',
        'delete',
        'import',
        'export',
        'login',
        'logout',
        'login_failed',
        'password_changed',
        'password_reset',
        'status_update',
        'role_update',
        'account_unlocked',
        'system',
    ];

    private const ALLOWED_ENTITY_TYPES = [
        'contact',
        'department',
        'user',
        'setting',
        'notification',
        'session',
        'system',
    ];

    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'current_password',
        'new_password',
        'password_confirmation',
        'csrf_token',
        'remember_token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'secret',
        'api_key',
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
     * Search audit records with safe filtering, sorting, and pagination.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, filters: array<string, mixed>}
     */
    public function search(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $defaultPerPage = max(
            1,
            (int) ($this->config['default_per_page'] ?? 25)
        );
        $maxPerPage = max(1, (int) ($this->config['max_per_page'] ?? 100));
        $perPage = min(
            max(1, (int) ($filters['per_page'] ?? $defaultPerPage)),
            $maxPerPage
        );
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params, $normalizedFilters] =
            $this->buildSearchConditions($filters);

        $sortKey = (string) ($filters['sort'] ?? 'newest');
        $orderBy = self::SORT_COLUMNS[$sortKey]
            ?? self::SORT_COLUMNS['newest'];

        $countSql = <<<SQL
            SELECT COUNT(*)
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
            {$whereSql}
            SQL;

        $countStatement = $this->db->prepare($countSql);
        $this->bindValues($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $sql = <<<SQL
            SELECT
                a.id,
                a.user_id,
                u.username,
                u.display_name AS user_name,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                a.ip_address,
                a.user_agent,
                a.created_at
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
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
            fn (array $row): array => $this->normalizeListItem($row),
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
            'filters' => array_merge(
                $normalizedFilters,
                ['sort' => array_key_exists($sortKey, self::SORT_COLUMNS)
                    ? $sortKey
                    : 'newest']
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $activityId): ?array
    {
        if ($activityId < 1) {
            return null;
        }

        $sql = <<<SQL
            SELECT
                a.id,
                a.user_id,
                u.username,
                u.display_name AS user_name,
                u.email AS user_email,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                a.old_values,
                a.new_values,
                a.ip_address,
                a.user_agent,
                a.created_at
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = :activity_id
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':activity_id', $activityId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] === null
            ? null
            : (int) $row['user_id'];
        $row['entity_id'] = $row['entity_id'] === null
            ? null
            : (int) $row['entity_id'];
        $row['user_name'] = $row['user_name'] ?: 'System';
        $row['old_values'] = $this->decodeValues($row['old_values']);
        $row['new_values'] = $this->decodeValues($row['new_values']);
        $row['changes'] = $this->buildChangeSet(
            $row['old_values'],
            $row['new_values']
        );

        return $row;
    }

    /**
     * Record an activity event after recursively removing sensitive data.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        $action = strtolower(trim($action));
        $entityType = strtolower(trim($entityType));
        $description = trim($description);

        if ($action === '' || mb_strlen($action) > 50) {
            throw new InvalidArgumentException('Invalid activity action.');
        }

        if ($entityType === '' || mb_strlen($entityType) > 50) {
            throw new InvalidArgumentException('Invalid entity type.');
        }

        if ($description === '' || mb_strlen($description) > 1000) {
            throw new InvalidArgumentException('Invalid activity description.');
        }

        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        if ($entityId !== null && $entityId < 1) {
            throw new InvalidArgumentException('Invalid entity ID.');
        }

        $ipAddress ??= $this->resolveClientIp();
        $userAgent ??= (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $userAgent = mb_substr($userAgent, 0, 500);

        if (
            $ipAddress !== null
            && filter_var($ipAddress, FILTER_VALIDATE_IP) === false
        ) {
            $ipAddress = null;
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
        $statement->bindValue(
            ':user_id',
            $userId,
            $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':action', $action, PDO::PARAM_STR);
        $statement->bindValue(':entity_type', $entityType, PDO::PARAM_STR);
        $statement->bindValue(
            ':entity_id',
            $entityId,
            $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':description', $description, PDO::PARAM_STR);
        $statement->bindValue(
            ':old_values',
            $this->encodeValues($oldValues),
            $oldValues === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':new_values',
            $this->encodeValues($newValues),
            $newValues === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':ip_address',
            $ipAddress,
            $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(':user_agent', $userAgent, PDO::PARAM_STR);
        $statement->execute();

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed> */
    public function getFilterOptions(): array
    {
        $actionStatement = $this->db->query(
            'SELECT action, COUNT(*) AS total FROM activity_logs '
            . 'GROUP BY action ORDER BY action ASC'
        );
        $entityStatement = $this->db->query(
            'SELECT entity_type, COUNT(*) AS total FROM activity_logs '
            . 'GROUP BY entity_type ORDER BY entity_type ASC'
        );
        $userStatement = $this->db->query(
            'SELECT DISTINCT u.id, u.username, u.display_name '
            . 'FROM activity_logs a INNER JOIN users u ON u.id = a.user_id '
            . 'ORDER BY u.display_name ASC'
        );

        return [
            'actions' => array_map(
                static fn (array $row): array => [
                    'value' => (string) $row['action'],
                    'label' => ucfirst(str_replace('_', ' ', (string) $row['action'])),
                    'total' => (int) $row['total'],
                ],
                $actionStatement->fetchAll()
            ),
            'entity_types' => array_map(
                static fn (array $row): array => [
                    'value' => (string) $row['entity_type'],
                    'label' => ucfirst((string) $row['entity_type']),
                    'total' => (int) $row['total'],
                ],
                $entityStatement->fetchAll()
            ),
            'users' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    'display_name' => (string) $row['display_name'],
                ],
                $userStatement->fetchAll()
            ),
            'allowed_actions' => self::ALLOWED_ACTIONS,
            'allowed_entity_types' => self::ALLOWED_ENTITY_TYPES,
        ];
    }

    /**
     * Return activity counts grouped by day and action.
     *
     * @return list<array{date: string, action: string, total: int}>
     */
    public function getSummary(int $days = 30): array
    {
        $days = min(max(1, $days), 365);
        $startDate = (new DateTimeImmutable('today'))
            ->modify('-' . ($days - 1) . ' days')
            ->format('Y-m-d 00:00:00');

        $sql = <<<SQL
            SELECT DATE(created_at) AS activity_date, action, COUNT(*) AS total
            FROM activity_logs
            WHERE created_at >= :start_date
            GROUP BY DATE(created_at), action
            ORDER BY activity_date ASC, action ASC
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'date' => (string) $row['activity_date'],
                'action' => (string) $row['action'],
                'total' => (int) $row['total'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * Export filtered activity logs to a secure temporary CSV file.
     *
     * @return array{path: string, filename: string, mime_type: string, row_count: int, size: int}
     */
    public function exportCsv(array $filters, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        [$whereSql, $params] = $this->buildSearchConditions($filters);

        $sql = <<<SQL
            SELECT
                a.id,
                a.created_at,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                u.username,
                u.display_name AS user_name,
                a.ip_address,
                a.user_agent
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
            {$whereSql}
            ORDER BY a.created_at DESC, a.id DESC
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->execute();

        $directory = $this->temporaryDirectory();
        $this->ensureDirectory($directory);
        $path = tempnam($directory, 'activity_logs_');

        if ($path === false) {
            throw new RuntimeException('Unable to create export file.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException('Unable to open export file.');
        }

        $rowCount = 0;

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID',
                'Date and Time',
                'User',
                'Username',
                'Action',
                'Entity Type',
                'Entity ID',
                'Description',
                'IP Address',
                'User Agent',
            ]);

            while (($row = $statement->fetch()) !== false) {
                fputcsv($handle, [
                    (int) $row['id'],
                    $this->sanitizeCsvValue($row['created_at']),
                    $this->sanitizeCsvValue($row['user_name'] ?: 'System'),
                    $this->sanitizeCsvValue($row['username']),
                    $this->sanitizeCsvValue($row['action']),
                    $this->sanitizeCsvValue($row['entity_type']),
                    $row['entity_id'] === null ? '' : (int) $row['entity_id'],
                    $this->sanitizeCsvValue($row['description']),
                    $this->sanitizeCsvValue($row['ip_address']),
                    $this->sanitizeCsvValue($row['user_agent']),
                ]);
                $rowCount++;
            }
        } finally {
            fclose($handle);
        }

        $filename = 'connectpro-activity-logs-'
            . (new DateTimeImmutable())->format('Ymd-His')
            . '.csv';

        $this->record(
            $actorUserId,
            'export',
            'system',
            null,
            'ส่งออก Activity Log',
            null,
            [
                'filename' => $filename,
                'row_count' => $rowCount,
                'filters' => $this->safeFilterMetadata($filters),
            ]
        );

        return [
            'path' => $path,
            'filename' => $filename,
            'mime_type' => 'text/csv; charset=utf-8',
            'row_count' => $rowCount,
            'size' => (int) filesize($path),
        ];
    }

    /**
     * Delete records older than the configured retention period.
     */
    public function cleanup(int $actorUserId, ?int $retentionDays = null): int
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        $minimumRetention = max(
            30,
            (int) ($this->config['minimum_retention_days'] ?? 90)
        );
        $retentionDays ??= (int) (
            $this->config['retention_days'] ?? 365
        );
        $retentionDays = max($minimumRetention, min($retentionDays, 3650));
        $cutoff = (new DateTimeImmutable('now'))
            ->modify('-' . $retentionDays . ' days')
            ->format('Y-m-d H:i:s');

        return $this->transactional(function () use (
            $actorUserId,
            $retentionDays,
            $cutoff
        ): int {
            $statement = $this->db->prepare(
                'DELETE FROM activity_logs WHERE created_at < :cutoff'
            );
            $statement->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $statement->execute();
            $deleted = $statement->rowCount();

            $this->record(
                $actorUserId,
                'delete',
                'system',
                null,
                'ล้าง Activity Log ตาม Retention Policy',
                null,
                [
                    'retention_days' => $retentionDays,
                    'cutoff' => $cutoff,
                    'deleted_records' => $deleted,
                ]
            );

            return $deleted;
        });
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildSearchConditions(array $filters): array
    {
        $conditions = [];
        $params = [];
        $normalized = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $conditions[] = '('
                . 'a.description LIKE :search '
                . 'OR a.action LIKE :search '
                . 'OR a.entity_type LIKE :search '
                . 'OR a.ip_address LIKE :search '
                . 'OR u.username LIKE :search '
                . 'OR u.display_name LIKE :search'
                . ')';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
            $normalized['search'] = $search;
        }

        $action = strtolower(trim((string) ($filters['action'] ?? '')));

        if ($action !== '') {
            $conditions[] = 'a.action = :action';
            $params['action'] = $action;
            $normalized['action'] = $action;
        }

        $entityType = strtolower(trim((string) (
            $filters['entity_type'] ?? ''
        )));

        if ($entityType !== '') {
            $conditions[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
            $normalized['entity_type'] = $entityType;
        }

        $userId = (int) ($filters['user_id'] ?? 0);

        if ($userId > 0) {
            $conditions[] = 'a.user_id = :user_id';
            $params['user_id'] = $userId;
            $normalized['user_id'] = $userId;
        }

        $entityId = (int) ($filters['entity_id'] ?? 0);

        if ($entityId > 0) {
            $conditions[] = 'a.entity_id = :entity_id';
            $params['entity_id'] = $entityId;
            $normalized['entity_id'] = $entityId;
        }

        $ipAddress = trim((string) ($filters['ip_address'] ?? ''));

        if ($ipAddress !== '') {
            if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Invalid IP address filter.');
            }

            $conditions[] = 'a.ip_address = :ip_address';
            $params['ip_address'] = $ipAddress;
            $normalized['ip_address'] = $ipAddress;
        }

        $dateFrom = $this->normalizeDateFilter(
            $filters['date_from'] ?? null,
            false
        );
        $dateTo = $this->normalizeDateFilter(
            $filters['date_to'] ?? null,
            true
        );

        if ($dateFrom !== null) {
            $conditions[] = 'a.created_at >= :date_from';
            $params['date_from'] = $dateFrom;
            $normalized['date_from'] = substr($dateFrom, 0, 10);
        }

        if ($dateTo !== null) {
            $conditions[] = 'a.created_at <= :date_to';
            $params['date_to'] = $dateTo;
            $normalized['date_to'] = substr($dateTo, 0, 10);
        }

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new InvalidArgumentException(
                'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด'
            );
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
            $normalized,
        ];
    }

    private function normalizeDateFilter(mixed $value, bool $endOfDay): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('รูปแบบวันที่ต้องเป็น YYYY-MM-DD');
        }

        return $date->setTime(
            $endOfDay ? 23 : 0,
            $endOfDay ? 59 : 0,
            $endOfDay ? 59 : 0
        )->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function normalizeListItem(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] === null
            ? null
            : (int) $row['user_id'];
        $row['entity_id'] = $row['entity_id'] === null
            ? null
            : (int) $row['entity_id'];
        $row['user_name'] = $row['user_name'] ?: 'System';
        $row['action_label'] = ucfirst(str_replace(
            '_',
            ' ',
            (string) $row['action']
        ));

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function decodeValues(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $this->sanitizeValues($value);
        }

        try {
            $decoded = json_decode(
                (string) $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return ['_raw' => '[Invalid JSON]'];
        }

        return is_array($decoded) ? $this->sanitizeValues($decoded) : null;
    }

    private function encodeValues(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        return json_encode(
            $this->sanitizeValues($values),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string, mixed> */
    private function sanitizeValues(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeValues($value);
                continue;
            }

            if (is_string($value) && mb_strlen($value) > 5000) {
                $sanitized[$key] = mb_substr($value, 0, 5000)
                    . '[TRUNCATED]';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @return list<array{field: string, old_value: mixed, new_value: mixed}>
     */
    private function buildChangeSet(?array $oldValues, ?array $newValues): array
    {
        if ($oldValues === null && $newValues === null) {
            return [];
        }

        $oldValues ??= [];
        $newValues ??= [];
        $fields = array_values(array_unique(array_merge(
            array_keys($oldValues),
            array_keys($newValues)
        )));
        $changes = [];

        foreach ($fields as $field) {
            $oldValue = $oldValues[$field] ?? null;
            $newValue = $newValues[$field] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = [
                'field' => (string) $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ];
        }

        return $changes;
    }

    private function sanitizeCsvValue(mixed $value): string
    {
        $text = str_replace("\0", '', (string) ($value ?? ''));
        $trimmed = ltrim($text);

        if (
            $trimmed !== ''
            && in_array($trimmed[0], ['=', '+', '-', '@'], true)
        ) {
            return "'" . $text;
        }

        return $text;
    }

    /** @return array<string, mixed> */
    private function safeFilterMetadata(array $filters): array
    {
        return array_intersect_key($filters, array_flip([
            'search',
            'action',
            'entity_type',
            'entity_id',
            'user_id',
            'ip_address',
            'date_from',
            'date_to',
            'sort',
        ]));
    }

    private function temporaryDirectory(): string
    {
        return rtrim(
            (string) ($this->config['temp_path'] ?? sys_get_temp_dir()),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . 'connectpro-activity-logs';
    }

    private function ensureDirectory(string $directory): void
    {
        if (
            !is_dir($directory)
            && !mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException('Unable to create temporary directory.');
        }
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
