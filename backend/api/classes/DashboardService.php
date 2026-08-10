<?php

declare(strict_types=1);

/**
 * ConnectPro Dashboard Service
 *
 * File: api/classes/DashboardService.php
 *
 * Aggregates dashboard statistics, recent activities, presence summaries,
 * department distributions, trends, and quick-action counters.
 */
final class DashboardService
{
    private const TREND_PERIODS = [7, 14, 30, 90];
    private const ACTIVITY_LIMIT_MAX = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Return all primary dashboard data in one structured response.
     *
     * @return array<string, mixed>
     */
    public function getOverview(?int $currentUserId = null): array
    {
        $cacheKey = 'dashboard_overview_' . ($currentUserId ?? 0);
        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            $cached['meta']['cached'] = true;

            return $cached;
        }

        $result = [
            'summary' => $this->getSummary($currentUserId),
            'presence' => $this->getPresenceSummary(),
            'departments' => $this->getDepartmentDistribution(8),
            'recent_activities' => $this->getRecentActivities(10),
            'trends' => $this->getContactTrends(30),
            'quick_actions' => $this->getQuickActionCounts(),
            'meta' => [
                'generated_at' => gmdate('c'),
                'cached' => false,
            ],
        ];

        $this->writeCache($cacheKey, $result);

        return $result;
    }

    /** @return array<string, int|float> */
    public function getSummary(?int $currentUserId = null): array
    {
        $sql = <<<SQL
            SELECT
                (SELECT COUNT(*) FROM contacts) AS total_contacts,
                (SELECT COUNT(*) FROM contacts WHERE status = 'active')
                    AS active_contacts,
                (SELECT COUNT(*) FROM contacts WHERE status = 'inactive')
                    AS inactive_contacts,
                (SELECT COUNT(*) FROM departments) AS total_departments,
                (SELECT COUNT(*) FROM departments WHERE status = 'active')
                    AS active_departments,
                (SELECT COUNT(*) FROM locations WHERE status = 'active')
                    AS active_locations,
                (SELECT COUNT(*) FROM users WHERE status = 'active')
                    AS active_users,
                (SELECT COUNT(*) FROM contacts
                    WHERE created_at >= CURRENT_DATE) AS contacts_created_today,
                (SELECT COUNT(*) FROM contacts
                    WHERE updated_at >= CURRENT_DATE) AS contacts_updated_today
            SQL;

        $row = $this->db->query($sql)->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Unable to load dashboard summary.');
        }

        $summary = $this->normalizeIntegerFields($row);
        $summary['favorite_contacts'] = $currentUserId === null
            ? 0
            : $this->countUserFavorites($currentUserId);
        $summary['contact_growth_percent'] = $this->calculateContactGrowth();

        return $summary;
    }

    /** @return array<string, int|float> */
    public function getPresenceSummary(): array
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
                SUM(CASE WHEN presence_status IS NULL THEN 1 ELSE 0 END)
                    AS unknown
            FROM contacts
            WHERE status = 'active'
            SQL;

        try {
            $row = $this->db->query($sql)->fetch();
        } catch (PDOException $exception) {
            if (!$this->isMissingColumnError($exception)) {
                throw $exception;
            }

            return [
                'total' => 0,
                'online' => 0,
                'busy' => 0,
                'away' => 0,
                'offline' => 0,
                'unknown' => 0,
                'online_percent' => 0.0,
            ];
        }

        $presence = is_array($row)
            ? $this->normalizeIntegerFields($row)
            : [];
        $total = (int) ($presence['total'] ?? 0);
        $presence['online_percent'] = $total > 0
            ? round(((int) ($presence['online'] ?? 0) / $total) * 100, 2)
            : 0.0;

        return $presence;
    }

    /** @return list<array<string, mixed>> */
    public function getDepartmentDistribution(int $limit = 8): array
    {
        $limit = min(max(1, $limit), 50);
        $sql = <<<SQL
            SELECT
                d.id,
                d.code,
                d.name,
                d.status,
                COUNT(c.id) AS contact_count,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END)
                    AS active_contact_count
            FROM departments d
            LEFT JOIN contacts c ON c.department_id = d.id
            GROUP BY d.id, d.code, d.name, d.status
            ORDER BY contact_count DESC, d.name ASC
            LIMIT :limit
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        $grandTotal = array_sum(array_map(
            static fn (array $row): int => (int) $row['contact_count'],
            $rows
        ));

        return array_map(
            static function (array $row) use ($grandTotal): array {
                $row['id'] = (int) $row['id'];
                $row['contact_count'] = (int) $row['contact_count'];
                $row['active_contact_count'] = (int) (
                    $row['active_contact_count'] ?? 0
                );
                $row['percentage'] = $grandTotal > 0
                    ? round(($row['contact_count'] / $grandTotal) * 100, 2)
                    : 0.0;

                return $row;
            },
            $rows
        );
    }

    /** @return list<array<string, mixed>> */
    public function getRecentActivities(
        int $limit = 10,
        ?string $action = null
    ): array {
        $limit = min(max(1, $limit), self::ACTIVITY_LIMIT_MAX);
        $conditions = [];
        $params = [];

        if ($action !== null && trim($action) !== '') {
            $allowedActions = [
                'create', 'update', 'delete', 'import', 'export',
                'login', 'logout', 'system',
            ];

            if (in_array($action, $allowedActions, true)) {
                $conditions[] = 'a.action = :action';
                $params['action'] = $action;
            }
        }

        $whereSql = $conditions === []
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions);

        $sql = <<<SQL
            SELECT
                a.id,
                a.user_id,
                u.display_name AS user_name,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                a.ip_address,
                a.created_at
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
            {$whereSql}
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT :limit
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['user_id'] = $row['user_id'] === null
                    ? null
                    : (int) $row['user_id'];
                $row['entity_id'] = $row['entity_id'] === null
                    ? null
                    : (int) $row['entity_id'];
                $row['user_name'] = $row['user_name'] ?: 'System';

                return $row;
            },
            $statement->fetchAll()
        );
    }

    /** @return list<array{date: string, created: int, updated: int}> */
    public function getContactTrends(int $days = 30): array
    {
        $days = in_array($days, self::TREND_PERIODS, true) ? $days : 30;
        $startDate = (new DateTimeImmutable('today'))
            ->modify('-' . ($days - 1) . ' days');

        $sql = <<<SQL
            SELECT
                DATE(event_date) AS activity_date,
                SUM(created_count) AS created_count,
                SUM(updated_count) AS updated_count
            FROM (
                SELECT created_at AS event_date, 1 AS created_count,
                    0 AS updated_count
                FROM contacts
                WHERE created_at >= :created_start
                UNION ALL
                SELECT updated_at AS event_date, 0 AS created_count,
                    1 AS updated_count
                FROM contacts
                WHERE updated_at >= :updated_start
            ) contact_events
            GROUP BY DATE(event_date)
            ORDER BY activity_date ASC
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'created_start' => $startDate->format('Y-m-d 00:00:00'),
            'updated_start' => $startDate->format('Y-m-d 00:00:00'),
        ]);

        $indexed = [];

        foreach ($statement->fetchAll() as $row) {
            $indexed[(string) $row['activity_date']] = [
                'created' => (int) $row['created_count'],
                'updated' => (int) $row['updated_count'],
            ];
        }

        $result = [];

        for ($index = 0; $index < $days; $index++) {
            $date = $startDate->modify('+' . $index . ' days')->format('Y-m-d');
            $result[] = [
                'date' => $date,
                'created' => $indexed[$date]['created'] ?? 0,
                'updated' => $indexed[$date]['updated'] ?? 0,
            ];
        }

        return $result;
    }

    /** @return array<string, int> */
    public function getQuickActionCounts(): array
    {
        $sql = <<<SQL
            SELECT
                (SELECT COUNT(*) FROM contacts WHERE status = 'active')
                    AS contacts,
                (SELECT COUNT(*) FROM departments WHERE status = 'active')
                    AS departments,
                (SELECT COUNT(*) FROM users WHERE status = 'active')
                    AS users,
                (SELECT COUNT(*) FROM activity_logs
                    WHERE created_at >= CURRENT_DATE) AS activities_today,
                (SELECT COUNT(*) FROM notifications
                    WHERE is_read = 0) AS unread_notifications
            SQL;

        try {
            $row = $this->db->query($sql)->fetch();
        } catch (PDOException $exception) {
            if (!$this->isMissingTableError($exception)) {
                throw $exception;
            }

            $fallbackSql = <<<SQL
                SELECT
                    (SELECT COUNT(*) FROM contacts WHERE status = 'active')
                        AS contacts,
                    (SELECT COUNT(*) FROM departments WHERE status = 'active')
                        AS departments,
                    (SELECT COUNT(*) FROM users WHERE status = 'active')
                        AS users,
                    (SELECT COUNT(*) FROM activity_logs
                        WHERE created_at >= CURRENT_DATE) AS activities_today
                SQL;
            $row = $this->db->query($fallbackSql)->fetch();
            $row['unread_notifications'] = 0;
        }

        return is_array($row) ? $this->normalizeIntegerFields($row) : [];
    }

    public function clearCache(?int $currentUserId = null): void
    {
        $this->deleteCache('dashboard_overview_' . ($currentUserId ?? 0));
    }

    private function countUserFavorites(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM favorites WHERE user_id = :user_id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function calculateContactGrowth(): float
    {
        $sql = <<<SQL
            SELECT
                SUM(CASE WHEN created_at >= CURRENT_DATE - INTERVAL 30 DAY
                    THEN 1 ELSE 0 END) AS current_period,
                SUM(CASE WHEN created_at >= CURRENT_DATE - INTERVAL 60 DAY
                    AND created_at < CURRENT_DATE - INTERVAL 30 DAY
                    THEN 1 ELSE 0 END) AS previous_period
            FROM contacts
            SQL;

        $row = $this->db->query($sql)->fetch();
        $current = (int) ($row['current_period'] ?? 0);
        $previous = (int) ($row['previous_period'] ?? 0);

        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /** @return array<string, int> */
    private function normalizeIntegerFields(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[(string) $key] = (int) ($value ?? 0);
        }

        return $normalized;
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

    /** @return array<string, mixed>|null */
    private function readCache(string $key): ?array
    {
        if (empty($this->config['cache_enabled'])) {
            return null;
        }

        $file = $this->cacheFile($key);
        $ttl = max(1, (int) ($this->config['cache_ttl_seconds'] ?? 60));

        if (!is_file($file) || (time() - filemtime($file)) > $ttl) {
            return null;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }

            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function writeCache(string $key, array $data): void
    {
        if (empty($this->config['cache_enabled'])) {
            return;
        }

        $directory = $this->cacheDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
        $temporary = tempnam($directory, 'dashboard_');

        if ($temporary === false) {
            return;
        }

        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                return;
            }

            chmod($temporary, 0640);
            rename($temporary, $this->cacheFile($key));
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function deleteCache(string $key): void
    {
        $file = $this->cacheFile($key);

        if (is_file($file)) {
            unlink($file);
        }
    }

    private function cacheDirectory(): string
    {
        return rtrim(
            (string) ($this->config['cache_path'] ?? sys_get_temp_dir()),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . 'connectpro-dashboard';
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDirectory()
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.json';
    }

    private function isMissingColumnError(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['42S22', '42703'], true);
    }

    private function isMissingTableError(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['42S02', '42P01'], true);
    }
}
