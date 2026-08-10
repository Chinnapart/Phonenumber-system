<?php

declare(strict_types=1);

/**
 * ConnectPro Presence Service
 *
 * File: api/classes/PresenceService.php
 *
 * Responsibilities:
 * - Read presence summaries and contact presence lists
 * - Update presence from trusted application events or integrations
 * - Record heartbeat timestamps
 * - Apply validated bulk presence updates
 * - Mark stale presence records as offline
 * - Write security-safe activity logs
 *
 * This service does not scan networks, ping arbitrary hosts, or execute
 * operating-system commands. Presence data must come from trusted sources.
 *
 * Expected contact columns:
 * - presence_status
 * - presence_message
 * - presence_source
 * - presence_updated_at
 * - last_seen_at
 */
final class PresenceService
{
    private const ALLOWED_STATUSES = [
        'online',
        'busy',
        'away',
        'offline',
        'unknown',
    ];

    private const ALLOWED_SOURCES = [
        'manual',
        'heartbeat',
        'system',
        'microsoft_graph',
        'import',
    ];

    private const SORT_COLUMNS = [
        'name_asc' => 'c.display_name ASC',
        'name_desc' => 'c.display_name DESC',
        'status' => 'FIELD(c.presence_status, '
            . "'online', 'busy', 'away', 'offline', 'unknown') ASC, "
            . 'c.display_name ASC',
        'department' => 'd.name ASC, c.display_name ASC',
        'recent' => 'c.presence_updated_at DESC, c.display_name ASC',
        'last_seen' => 'c.last_seen_at DESC, c.display_name ASC',
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
     * Return presence totals and percentages for active contacts.
     *
     * @return array<string, int|float|string>
     */
    public function getSummary(): array
    {
        $sql = <<<SQL
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN presence_status = 'online' THEN 1 ELSE 0 END)
                    AS online,
                SUM(CASE WHEN presence_status = 'busy' THEN 1 ELSE 0 END)
                    AS busy,
                SUM(CASE WHEN presence_status = 'away' THEN 1 ELSE 0 END)
                    AS away,
                SUM(CASE WHEN presence_status = 'offline' THEN 1 ELSE 0 END)
                    AS offline,
                SUM(CASE
                    WHEN presence_status IS NULL OR presence_status = 'unknown'
                    THEN 1 ELSE 0 END) AS unknown,
                MAX(presence_updated_at) AS last_updated_at
            FROM contacts
            WHERE status = 'active'
            SQL;

        $row = $this->db->query($sql)->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Unable to load presence summary.');
        }

        $total = (int) ($row['total'] ?? 0);
        $summary = [
            'total' => $total,
            'online' => (int) ($row['online'] ?? 0),
            'busy' => (int) ($row['busy'] ?? 0),
            'away' => (int) ($row['away'] ?? 0),
            'offline' => (int) ($row['offline'] ?? 0),
            'unknown' => (int) ($row['unknown'] ?? 0),
            'last_updated_at' => (string) ($row['last_updated_at'] ?? ''),
        ];

        foreach (self::ALLOWED_STATUSES as $status) {
            $summary[$status . '_percent'] = $total > 0
                ? round(((int) $summary[$status] / $total) * 100, 2)
                : 0.0;
        }

        return $summary;
    }

    /**
     * Search contact presence records with pagination.
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
        $sortKey = (string) ($filters['sort'] ?? 'status');
        $orderBy = self::SORT_COLUMNS[$sortKey]
            ?? self::SORT_COLUMNS['status'];

        $countSql = <<<SQL
            SELECT COUNT(*)
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
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
                c.email,
                c.ip_address,
                c.status AS contact_status,
                COALESCE(c.presence_status, 'unknown') AS presence_status,
                c.presence_message,
                c.presence_source,
                c.presence_updated_at,
                c.last_seen_at
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
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
            fn (array $row): array => $this->normalizePresence($row),
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
    public function findByContactId(int $contactId): ?array
    {
        if ($contactId < 1) {
            return null;
        }

        $sql = <<<SQL
            SELECT
                c.id,
                c.employee_code,
                c.display_name,
                c.position,
                c.department_id,
                d.name AS department_name,
                c.location_id,
                l.name AS location_name,
                c.extension_number,
                c.email,
                c.ip_address,
                c.status AS contact_status,
                COALESCE(c.presence_status, 'unknown') AS presence_status,
                c.presence_message,
                c.presence_source,
                c.presence_updated_at,
                c.last_seen_at
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
            WHERE c.id = :contact_id
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizePresence($row) : null;
    }

    /**
     * Update one contact's presence from a trusted source.
     *
     * @return array<string, mixed>
     */
    public function updateStatus(
        int $contactId,
        string $status,
        ?string $message = null,
        string $source = 'manual',
        ?int $actorUserId = null,
        ?DateTimeInterface $occurredAt = null
    ): array {
        $existing = $this->findByContactId($contactId);

        if ($existing === null) {
            throw new OutOfBoundsException('Contact not found.');
        }

        $status = $this->validateStatus($status);
        $source = $this->validateSource($source);
        $message = $this->normalizeMessage($message);
        $timestamp = ($occurredAt ?? new DateTimeImmutable())
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'UPDATE contacts SET presence_status = :presence_status, '
            . 'presence_message = :presence_message, '
            . 'presence_source = :presence_source, '
            . 'presence_updated_at = :presence_updated_at, '
            . 'last_seen_at = CASE '
            . "WHEN :active_presence_status IN ('online', 'busy', 'away') "
            . 'THEN :last_seen_at ELSE last_seen_at END, '
            . 'updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :contact_id AND status = :contact_status'
        );
        $statement->bindValue(':presence_status', $status, PDO::PARAM_STR);
        $statement->bindValue(
            ':active_presence_status',
            $status,
            PDO::PARAM_STR
        );
        $statement->bindValue(
            ':presence_message',
            $message,
            $message === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(':presence_source', $source, PDO::PARAM_STR);
        $statement->bindValue(':presence_updated_at', $timestamp, PDO::PARAM_STR);
        $statement->bindValue(':last_seen_at', $timestamp, PDO::PARAM_STR);
        $statement->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $statement->bindValue(':contact_status', 'active', PDO::PARAM_STR);
        $statement->execute();

        if ((string) ($existing['contact_status'] ?? '') !== 'active') {
            throw new DomainException('Contact is inactive.');
        }

        $this->writeActivityLog(
            $actorUserId,
            'update',
            $contactId,
            'อัปเดต Presence ของ ' . $existing['display_name'],
            [
                'presence_status' => $existing['presence_status'],
                'presence_message' => $existing['presence_message'],
                'presence_source' => $existing['presence_source'],
            ],
            [
                'presence_status' => $status,
                'presence_message' => $message,
                'presence_source' => $source,
            ]
        );

        return $this->findByContactId($contactId)
            ?? throw new RuntimeException('Updated presence could not be loaded.');
    }

    /**
     * Record a trusted heartbeat and mark the contact online.
     *
     * @return array<string, mixed>
     */
    public function heartbeat(
        int $contactId,
        ?string $message = null,
        string $source = 'heartbeat'
    ): array {
        return $this->updateStatus(
            $contactId,
            'online',
            $message,
            $source,
            null,
            new DateTimeImmutable()
        );
    }

    /**
     * Apply multiple trusted presence updates atomically.
     *
     * @param list<array<string, mixed>> $updates
     * @return array{updated: int, items: list<array<string, mixed>>}
     */
    public function bulkUpdate(
        array $updates,
        ?int $actorUserId = null,
        string $defaultSource = 'system'
    ): array {
        if ($updates === []) {
            throw new InvalidArgumentException('No presence updates provided.');
        }

        $maximumBatch = max(
            1,
            (int) ($this->config['max_batch_size'] ?? 500)
        );

        if (count($updates) > $maximumBatch) {
            throw new InvalidArgumentException(
                'จำนวน Presence Update เกินขนาด Batch ที่กำหนด'
            );
        }

        $defaultSource = $this->validateSource($defaultSource);
        $normalized = [];
        $errors = [];

        foreach ($updates as $index => $update) {
            if (!is_array($update)) {
                $errors[$index] = 'รูปแบบรายการไม่ถูกต้อง';
                continue;
            }

            try {
                $contactId = (int) ($update['contact_id'] ?? 0);

                if ($contactId < 1) {
                    throw new InvalidArgumentException('Contact ID ไม่ถูกต้อง');
                }

                $occurredAt = $this->normalizeOccurredAt(
                    $update['occurred_at'] ?? null
                );
                $normalized[] = [
                    'contact_id' => $contactId,
                    'status' => $this->validateStatus(
                        (string) ($update['status'] ?? '')
                    ),
                    'message' => $this->normalizeMessage(
                        isset($update['message'])
                            ? (string) $update['message']
                            : null
                    ),
                    'source' => $this->validateSource(
                        (string) ($update['source'] ?? $defaultSource)
                    ),
                    'occurred_at' => $occurredAt,
                ];
            } catch (InvalidArgumentException $exception) {
                $errors[$index] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => $errors],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        return $this->transactional(function () use (
            $normalized,
            $actorUserId
        ): array {
            $items = [];

            foreach ($normalized as $update) {
                $items[] = $this->updateStatus(
                    $update['contact_id'],
                    $update['status'],
                    $update['message'],
                    $update['source'],
                    $actorUserId,
                    $update['occurred_at']
                );
            }

            return [
                'updated' => count($items),
                'items' => $items,
            ];
        });
    }

    /**
     * Mark active, non-offline records as offline when their presence data
     * has not been refreshed within the configured timeout.
     */
    public function markStaleOffline(
        ?int $timeoutSeconds = null,
        ?int $actorUserId = null
    ): int {
        $minimumTimeout = max(
            30,
            (int) ($this->config['minimum_stale_seconds'] ?? 60)
        );
        $timeoutSeconds ??= (int) (
            $this->config['stale_after_seconds'] ?? 300
        );
        $timeoutSeconds = max(
            $minimumTimeout,
            min($timeoutSeconds, 86400)
        );
        $cutoff = (new DateTimeImmutable())
            ->modify('-' . $timeoutSeconds . ' seconds')
            ->format('Y-m-d H:i:s');

        return $this->transactional(function () use (
            $cutoff,
            $timeoutSeconds,
            $actorUserId
        ): int {
            $statement = $this->db->prepare(
                'UPDATE contacts SET presence_status = :offline, '
                . 'presence_message = NULL, presence_source = :source, '
                . 'presence_updated_at = CURRENT_TIMESTAMP, '
                . 'updated_at = CURRENT_TIMESTAMP '
                . 'WHERE status = :contact_status '
                . "AND COALESCE(presence_status, 'unknown') "
                . "NOT IN ('offline', 'unknown') "
                . 'AND (presence_updated_at IS NULL '
                . 'OR presence_updated_at < :cutoff)'
            );
            $statement->execute([
                'offline' => 'offline',
                'source' => 'system',
                'contact_status' => 'active',
                'cutoff' => $cutoff,
            ]);
            $updated = $statement->rowCount();

            if ($updated > 0) {
                $this->writeActivityLog(
                    $actorUserId,
                    'system',
                    null,
                    'ปรับ Presence ที่หมดอายุเป็น Offline',
                    null,
                    [
                        'updated_records' => $updated,
                        'timeout_seconds' => $timeoutSeconds,
                        'cutoff' => $cutoff,
                    ]
                );
            }

            return $updated;
        });
    }

    /**
     * Reset selected records to unknown, useful after integration changes.
     *
     * @param list<int> $contactIds
     */
    public function resetToUnknown(
        array $contactIds,
        int $actorUserId
    ): int {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $contactIds),
            static fn (int $id): bool => $id > 0
        )));
        $maximumBatch = max(
            1,
            (int) ($this->config['max_batch_size'] ?? 500)
        );

        if ($ids === [] || count($ids) > $maximumBatch) {
            throw new InvalidArgumentException('Contact ID batch is invalid.');
        }

        $placeholders = [];
        $params = [
            'presence_status' => 'unknown',
            'presence_source' => 'manual',
        ];

        foreach ($ids as $index => $id) {
            $key = 'contact_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return $this->transactional(function () use (
            $placeholders,
            $params,
            $actorUserId,
            $ids
        ): int {
            $sql = 'UPDATE contacts SET '
                . 'presence_status = :presence_status, '
                . 'presence_message = NULL, '
                . 'presence_source = :presence_source, '
                . 'presence_updated_at = CURRENT_TIMESTAMP, '
                . 'updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id IN (' . implode(', ', $placeholders) . ')';
            $statement = $this->db->prepare($sql);
            $this->bindValues($statement, $params);
            $statement->execute();
            $updated = $statement->rowCount();

            $this->writeActivityLog(
                $actorUserId,
                'update',
                null,
                'รีเซ็ต Presence เป็น Unknown',
                null,
                [
                    'contact_ids' => $ids,
                    'updated_records' => $updated,
                ]
            );

            return $updated;
        });
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildSearchConditions(array $filters): array
    {
        $conditions = ['c.status = :contact_status'];
        $params = ['contact_status' => 'active'];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $conditions[] = '('
                . 'c.display_name LIKE :search '
                . 'OR c.employee_code LIKE :search '
                . 'OR c.extension_number LIKE :search '
                . 'OR c.email LIKE :search '
                . 'OR c.ip_address LIKE :search'
                . ')';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
        }

        $presenceStatus = strtolower(trim((string) (
            $filters['presence_status'] ?? ''
        )));

        if (in_array($presenceStatus, self::ALLOWED_STATUSES, true)) {
            if ($presenceStatus === 'unknown') {
                $conditions[] = "COALESCE(c.presence_status, 'unknown') = :presence_status";
            } else {
                $conditions[] = 'c.presence_status = :presence_status';
            }

            $params['presence_status'] = $presenceStatus;
        }

        $source = strtolower(trim((string) ($filters['source'] ?? '')));

        if (in_array($source, self::ALLOWED_SOURCES, true)) {
            $conditions[] = 'c.presence_source = :presence_source';
            $params['presence_source'] = $source;
        }

        foreach (['department_id', 'location_id'] as $field) {
            $value = (int) ($filters[$field] ?? 0);

            if ($value > 0) {
                $conditions[] = 'c.' . $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }

        if (!empty($filters['stale_only'])) {
            $staleSeconds = max(
                60,
                min(
                    (int) ($filters['stale_seconds']
                        ?? $this->config['stale_after_seconds']
                        ?? 300),
                    86400
                )
            );
            $conditions[] = '(c.presence_updated_at IS NULL '
                . 'OR c.presence_updated_at < :stale_cutoff)';
            $params['stale_cutoff'] = (new DateTimeImmutable())
                ->modify('-' . $staleSeconds . ' seconds')
                ->format('Y-m-d H:i:s');
        }

        return [
            'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    private function validateStatus(string $status): string
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Presence status ไม่ถูกต้อง');
        }

        return $status;
    }

    private function validateSource(string $source): string
    {
        $source = strtolower(trim($source));

        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException('Presence source ไม่ถูกต้อง');
        }

        return $source;
    }

    private function normalizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = trim($message);

        if ($message === '') {
            return null;
        }

        if (mb_strlen($message) > 255) {
            throw new InvalidArgumentException(
                'Presence message ต้องไม่เกิน 255 ตัวอักษร'
            );
        }

        return $message;
    }

    private function normalizeOccurredAt(mixed $value): DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return new DateTimeImmutable();
        }

        try {
            $date = new DateTimeImmutable((string) $value);
        } catch (Exception) {
            throw new InvalidArgumentException('occurred_at ไม่ถูกต้อง');
        }

        $maximumFutureSeconds = max(
            0,
            (int) ($this->config['maximum_future_seconds'] ?? 60)
        );

        if ($date->getTimestamp() > time() + $maximumFutureSeconds) {
            throw new InvalidArgumentException(
                'occurred_at ไม่สามารถเป็นเวลาในอนาคตได้'
            );
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function normalizePresence(array $row): array
    {
        foreach (['id', 'department_id', 'location_id'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        $row['presence_status'] = in_array(
            (string) ($row['presence_status'] ?? 'unknown'),
            self::ALLOWED_STATUSES,
            true
        ) ? (string) $row['presence_status'] : 'unknown';
        $row['presence_source'] = $row['presence_source'] ?: null;
        $row['is_stale'] = $this->isStale($row['presence_updated_at'] ?? null);

        return $row;
    }

    private function isStale(mixed $updatedAt): bool
    {
        if (!is_string($updatedAt) || $updatedAt === '') {
            return true;
        }

        $timestamp = strtotime($updatedAt);

        if ($timestamp === false) {
            return true;
        }

        $staleSeconds = max(
            60,
            (int) ($this->config['stale_after_seconds'] ?? 300)
        );

        return (time() - $timestamp) > $staleSeconds;
    }

    private function writeActivityLog(
        ?int $actorUserId,
        string $action,
        ?int $entityId,
        string $description,
        ?array $oldValues,
        ?array $newValues
    ): void {
        if (empty($this->config['activity_log_enabled'])) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO activity_logs (
                user_id, action, entity_type, entity_id, description,
                old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, 'contact', :entity_id, :description,
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
        $statement->bindValue(':action', $action, PDO::PARAM_STR);
        $statement->bindValue(
            ':entity_id',
            $entityId,
            $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':description', $description, PDO::PARAM_STR);
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
            $values,
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
