<?php

declare(strict_types=1);

/**
 * ConnectPro Settings Service
 *
 * File: api/classes/SettingsService.php
 *
 * Responsibilities:
 * - Read public and administrative application settings
 * - Group settings for the administration interface
 * - Validate values by type and configured rules
 * - Update one or many settings in a transaction
 * - Encrypt sensitive setting values at rest
 * - Cache non-sensitive settings with safe file locking
 * - Record changes without exposing secrets
 *
 * Expected settings table columns:
 * id, setting_key, setting_value, value_type, setting_group, label,
 * description, validation_rules, default_value, is_public, is_sensitive,
 * is_editable, sort_order, updated_by, created_at, updated_at
 */
final class SettingsService
{
    private const VALUE_TYPES = [
        'string',
        'integer',
        'float',
        'boolean',
        'json',
        'email',
        'url',
        'timezone',
        'color',
    ];

    private const GROUPS = [
        'general',
        'security',
        'session',
        'notifications',
        'directory',
        'import_export',
        'appearance',
        'maintenance',
        'integration',
    ];

    private const SENSITIVE_KEYWORDS = [
        'password',
        'secret',
        'token',
        'api_key',
        'private_key',
        'client_secret',
        'connection_string',
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
     * Return all settings, optionally filtered by group.
     * Sensitive values are masked unless explicitly requested.
     *
     * @return list<array<string, mixed>>
     */
    public function getAll(
        ?string $group = null,
        bool $includeSensitive = false
    ): array {
        $conditions = [];
        $params = [];

        if ($group !== null && trim($group) !== '') {
            $group = strtolower(trim($group));

            if (!in_array($group, self::GROUPS, true)) {
                throw new InvalidArgumentException('Invalid settings group.');
            }

            $conditions[] = 'setting_group = :setting_group';
            $params['setting_group'] = $group;
        }

        $whereSql = $conditions === []
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions);

        $sql = <<<SQL
            SELECT
                id,
                setting_key,
                setting_value,
                value_type,
                setting_group,
                label,
                description,
                validation_rules,
                default_value,
                is_public,
                is_sensitive,
                is_editable,
                sort_order,
                updated_by,
                created_at,
                updated_at
            FROM settings
            {$whereSql}
            ORDER BY setting_group ASC, sort_order ASC, setting_key ASC
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->execute();

        return array_map(
            fn (array $row): array => $this->normalizeSetting(
                $row,
                $includeSensitive
            ),
            $statement->fetchAll()
        );
    }

    /**
     * Return settings grouped for the administration interface.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getGrouped(bool $includeSensitive = false): array
    {
        $grouped = [];

        foreach ($this->getAll(null, $includeSensitive) as $setting) {
            $group = (string) $setting['group'];
            $grouped[$group] ??= [];
            $grouped[$group][] = $setting;
        }

        return $grouped;
    }

    /**
     * Return public settings as a simple key-value map.
     *
     * @return array<string, mixed>
     */
    public function getPublicSettings(bool $useCache = true): array
    {
        $cacheKey = 'public-settings';

        if ($useCache) {
            $cached = $this->readCache($cacheKey);

            if ($cached !== null) {
                return $cached;
            }
        }

        $statement = $this->db->prepare(
            'SELECT setting_key, setting_value, value_type, default_value '
            . 'FROM settings WHERE is_public = 1 AND is_sensitive = 0 '
            . 'ORDER BY sort_order ASC, setting_key ASC'
        );
        $statement->execute();
        $settings = [];

        foreach ($statement->fetchAll() as $row) {
            $rawValue = $row['setting_value'] ?? $row['default_value'] ?? null;
            $settings[(string) $row['setting_key']] = $this->castValue(
                $rawValue,
                (string) $row['value_type']
            );
        }

        $this->writeCache($cacheKey, $settings);

        return $settings;
    }

    /**
     * Get one setting value by key.
     */
    public function get(
        string $key,
        mixed $default = null,
        bool $allowSensitive = false
    ): mixed {
        $key = $this->normalizeKey($key);
        $setting = $this->findRawByKey($key);

        if ($setting === null) {
            return $default;
        }

        if ((bool) $setting['is_sensitive'] && !$allowSensitive) {
            return $default;
        }

        $rawValue = $setting['setting_value'];

        if ($rawValue === null || $rawValue === '') {
            $rawValue = $setting['default_value'] ?? null;
        }

        if ((bool) $setting['is_sensitive'] && $rawValue !== null) {
            $rawValue = $this->decryptValue((string) $rawValue);
        }

        return $this->castValue($rawValue, (string) $setting['value_type']);
    }

    /**
     * Update a single setting.
     *
     * @return array<string, mixed>
     */
    public function set(
        string $key,
        mixed $value,
        int $actorUserId
    ): array {
        $result = $this->updateMany([$key => $value], $actorUserId);

        return $result[0];
    }

    /**
     * Update multiple settings atomically.
     *
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function updateMany(array $values, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        if ($values === []) {
            throw new InvalidArgumentException('No settings were provided.');
        }

        $maximumBatch = max(
            1,
            (int) ($this->config['max_batch_size'] ?? 100)
        );

        if (count($values) > $maximumBatch) {
            throw new InvalidArgumentException(
                'จำนวน Setting ที่แก้ไขพร้อมกันเกินกำหนด'
            );
        }

        $prepared = [];
        $validationErrors = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                $validationErrors[(string) $key] = 'Setting key ไม่ถูกต้อง';
                continue;
            }

            $normalizedKey = $this->normalizeKey($key);
            $setting = $this->findRawByKey($normalizedKey);

            if ($setting === null) {
                $validationErrors[$normalizedKey] = 'ไม่พบ Setting นี้';
                continue;
            }

            if (!(bool) $setting['is_editable']) {
                $validationErrors[$normalizedKey] = 'Setting นี้ไม่อนุญาตให้แก้ไข';
                continue;
            }

            $errors = $this->validateValue($value, $setting);

            if ($errors !== []) {
                $validationErrors[$normalizedKey] = implode(' ', $errors);
                continue;
            }

            $normalizedValue = $this->normalizeValueForStorage(
                $value,
                (string) $setting['value_type']
            );
            $storedValue = (bool) $setting['is_sensitive']
                ? $this->encryptValue($normalizedValue)
                : $normalizedValue;

            $prepared[] = [
                'setting' => $setting,
                'key' => $normalizedKey,
                'old_value' => $setting['setting_value'],
                'new_value' => $storedValue,
                'audit_new_value' => (bool) $setting['is_sensitive']
                    ? '[REDACTED]'
                    : $this->castValue(
                        $normalizedValue,
                        (string) $setting['value_type']
                    ),
            ];
        }

        if ($validationErrors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => $validationErrors],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        return $this->transactional(function () use (
            $prepared,
            $actorUserId
        ): array {
            $updated = [];

            foreach ($prepared as $item) {
                $statement = $this->db->prepare(
                    'UPDATE settings SET setting_value = :setting_value, '
                    . 'updated_by = :updated_by, '
                    . 'updated_at = CURRENT_TIMESTAMP '
                    . 'WHERE id = :setting_id AND is_editable = 1'
                );
                $statement->bindValue(
                    ':setting_value',
                    $item['new_value'],
                    $item['new_value'] === null
                        ? PDO::PARAM_NULL
                        : PDO::PARAM_STR
                );
                $statement->bindValue(
                    ':updated_by',
                    $actorUserId,
                    PDO::PARAM_INT
                );
                $statement->bindValue(
                    ':setting_id',
                    (int) $item['setting']['id'],
                    PDO::PARAM_INT
                );
                $statement->execute();

                if ($statement->rowCount() > 1) {
                    throw new RuntimeException('Unexpected settings update count.');
                }

                $isSensitive = (bool) $item['setting']['is_sensitive'];
                $oldAuditValue = $isSensitive
                    ? '[REDACTED]'
                    : $this->castValue(
                        $item['old_value'],
                        (string) $item['setting']['value_type']
                    );

                $this->writeActivityLog(
                    $actorUserId,
                    (int) $item['setting']['id'],
                    (string) $item['key'],
                    $oldAuditValue,
                    $item['audit_new_value']
                );

                $fresh = $this->findRawByKey((string) $item['key']);

                if ($fresh === null) {
                    throw new RuntimeException(
                        'Updated setting could not be loaded.'
                    );
                }

                $updated[] = $this->normalizeSetting($fresh, false);
            }

            $this->clearCache();

            return $updated;
        });
    }

    /**
     * Restore one setting to its configured default value.
     *
     * @return array<string, mixed>
     */
    public function resetToDefault(string $key, int $actorUserId): array
    {
        $key = $this->normalizeKey($key);
        $setting = $this->findRawByKey($key);

        if ($setting === null) {
            throw new OutOfBoundsException('Setting not found.');
        }

        if (!(bool) $setting['is_editable']) {
            throw new DomainException('Setting นี้ไม่อนุญาตให้แก้ไข');
        }

        $defaultValue = $setting['default_value'] ?? null;

        if ((bool) $setting['is_sensitive'] && $defaultValue !== null) {
            $defaultValue = $this->decryptValue((string) $defaultValue);
        }

        return $this->set(
            $key,
            $this->castValue($defaultValue, (string) $setting['value_type']),
            $actorUserId
        );
    }

    /**
     * Create a new setting definition for controlled system extensions.
     *
     * @return array<string, mixed>
     */
    public function createDefinition(
        array $input,
        int $actorUserId
    ): array {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        $data = $this->prepareDefinition($input);
        $errors = $this->validateDefinition($data);

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => $errors],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        if ($this->findRawByKey($data['setting_key']) !== null) {
            throw new DomainException('Setting key already exists.');
        }

        $valueErrors = $this->validateValue(
            $data['setting_value'],
            $data
        );

        if ($valueErrors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => [
                    'setting_value' => implode(' ', $valueErrors),
                ]],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        $storedValue = $this->normalizeValueForStorage(
            $data['setting_value'],
            $data['value_type']
        );
        $storedDefault = $this->normalizeValueForStorage(
            $data['default_value'],
            $data['value_type']
        );

        if ($data['is_sensitive']) {
            $storedValue = $this->encryptValue($storedValue);
            $storedDefault = $storedDefault === null
                ? null
                : $this->encryptValue($storedDefault);
        }

        return $this->transactional(function () use (
            $data,
            $storedValue,
            $storedDefault,
            $actorUserId
        ): array {
            $sql = <<<SQL
                INSERT INTO settings (
                    setting_key, setting_value, value_type, setting_group,
                    label, description, validation_rules, default_value,
                    is_public, is_sensitive, is_editable, sort_order,
                    updated_by, created_at, updated_at
                ) VALUES (
                    :setting_key, :setting_value, :value_type, :setting_group,
                    :label, :description, :validation_rules, :default_value,
                    :is_public, :is_sensitive, :is_editable, :sort_order,
                    :updated_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                SQL;

            $statement = $this->db->prepare($sql);
            $statement->bindValue(':setting_key', $data['setting_key']);
            $statement->bindValue(
                ':setting_value',
                $storedValue,
                $storedValue === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $statement->bindValue(':value_type', $data['value_type']);
            $statement->bindValue(':setting_group', $data['setting_group']);
            $statement->bindValue(':label', $data['label']);
            $statement->bindValue(
                ':description',
                $data['description'],
                $data['description'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_STR
            );
            $statement->bindValue(
                ':validation_rules',
                $data['validation_rules'],
                $data['validation_rules'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_STR
            );
            $statement->bindValue(
                ':default_value',
                $storedDefault,
                $storedDefault === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $statement->bindValue(
                ':is_public',
                $data['is_public'] ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                ':is_sensitive',
                $data['is_sensitive'] ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                ':is_editable',
                $data['is_editable'] ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                ':sort_order',
                $data['sort_order'],
                PDO::PARAM_INT
            );
            $statement->bindValue(
                ':updated_by',
                $actorUserId,
                PDO::PARAM_INT
            );
            $statement->execute();

            $settingId = (int) $this->db->lastInsertId();
            $this->writeActivityLog(
                $actorUserId,
                $settingId,
                $data['setting_key'],
                null,
                $data['is_sensitive'] ? '[REDACTED]' : $data['setting_value']
            );
            $this->clearCache();

            $setting = $this->findRawByKey($data['setting_key']);

            if ($setting === null) {
                throw new RuntimeException(
                    'Created setting could not be loaded.'
                );
            }

            return $this->normalizeSetting($setting, false);
        });
    }

    /**
     * Delete a custom editable setting definition.
     */
    public function deleteDefinition(string $key, int $actorUserId): bool
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        $key = $this->normalizeKey($key);
        $setting = $this->findRawByKey($key);

        if ($setting === null) {
            throw new OutOfBoundsException('Setting not found.');
        }

        if (!(bool) $setting['is_editable']) {
            throw new DomainException('Setting นี้ไม่อนุญาตให้ลบ');
        }

        return $this->transactional(function () use (
            $key,
            $setting,
            $actorUserId
        ): bool {
            $statement = $this->db->prepare(
                'DELETE FROM settings WHERE id = :setting_id '
                . 'AND is_editable = 1'
            );
            $statement->bindValue(
                ':setting_id',
                (int) $setting['id'],
                PDO::PARAM_INT
            );
            $statement->execute();

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Setting could not be deleted.');
            }

            $this->writeActivityLog(
                $actorUserId,
                (int) $setting['id'],
                $key,
                (bool) $setting['is_sensitive']
                    ? '[REDACTED]'
                    : $this->castValue(
                        $setting['setting_value'],
                        (string) $setting['value_type']
                    ),
                null,
                'delete'
            );
            $this->clearCache();

            return true;
        });
    }

    /**
     * Validate a setting value using its data type and JSON validation rules.
     *
     * Supported rules: required, min, max, min_length, max_length,
     * allowed_values, pattern.
     *
     * @return list<string>
     */
    public function validateValue(mixed $value, array $setting): array
    {
        $errors = [];
        $type = (string) ($setting['value_type'] ?? 'string');
        $rules = $this->decodeValidationRules(
            $setting['validation_rules'] ?? null
        );
        $isEmpty = $value === null || (is_string($value) && trim($value) === '');

        if (!empty($rules['required']) && $isEmpty) {
            $errors[] = 'จำเป็นต้องระบุค่า';

            return $errors;
        }

        if ($isEmpty) {
            return [];
        }

        try {
            $castValue = $this->castInputValue($value, $type);
        } catch (InvalidArgumentException $exception) {
            return [$exception->getMessage()];
        }

        if (
            isset($rules['allowed_values'])
            && is_array($rules['allowed_values'])
            && !in_array($castValue, $rules['allowed_values'], true)
        ) {
            $errors[] = 'ค่าไม่อยู่ในรายการที่อนุญาต';
        }

        if (is_int($castValue) || is_float($castValue)) {
            if (isset($rules['min']) && $castValue < $rules['min']) {
                $errors[] = 'ค่าต้องไม่น้อยกว่า ' . $rules['min'];
            }

            if (isset($rules['max']) && $castValue > $rules['max']) {
                $errors[] = 'ค่าต้องไม่มากกว่า ' . $rules['max'];
            }
        }

        if (is_string($castValue)) {
            $length = mb_strlen($castValue);

            if (
                isset($rules['min_length'])
                && $length < (int) $rules['min_length']
            ) {
                $errors[] = 'ข้อความสั้นกว่าที่กำหนด';
            }

            if (
                isset($rules['max_length'])
                && $length > (int) $rules['max_length']
            ) {
                $errors[] = 'ข้อความยาวเกินที่กำหนด';
            }

            if (isset($rules['pattern']) && is_string($rules['pattern'])) {
                $pattern = $rules['pattern'];

                if (@preg_match($pattern, '') === false) {
                    $errors[] = 'Validation pattern ไม่ถูกต้อง';
                } elseif (preg_match($pattern, $castValue) !== 1) {
                    $errors[] = 'รูปแบบข้อมูลไม่ถูกต้อง';
                }
            }
        }

        return $errors;
    }

    public function clearCache(): void
    {
        $file = $this->cacheFile('public-settings');

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed>|null */
    private function findRawByKey(string $key): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, setting_key, setting_value, value_type, '
            . 'setting_group, label, description, validation_rules, '
            . 'default_value, is_public, is_sensitive, is_editable, '
            . 'sort_order, updated_by, created_at, updated_at '
            . 'FROM settings WHERE setting_key = :setting_key LIMIT 1'
        );
        $statement->bindValue(':setting_key', $key, PDO::PARAM_STR);
        $statement->execute();
        $setting = $statement->fetch();

        return is_array($setting) ? $setting : null;
    }

    /** @return array<string, mixed> */
    private function normalizeSetting(
        array $row,
        bool $includeSensitive
    ): array {
        $isSensitive = (bool) ($row['is_sensitive'] ?? false);
        $rawValue = $row['setting_value'] ?? $row['default_value'] ?? null;

        if ($isSensitive) {
            if ($includeSensitive && $rawValue !== null && $rawValue !== '') {
                $value = $this->decryptValue((string) $rawValue);
            } else {
                $value = $rawValue === null || $rawValue === ''
                    ? null
                    : '********';
            }
        } else {
            $value = $this->castValue(
                $rawValue,
                (string) ($row['value_type'] ?? 'string')
            );
        }

        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['setting_key'],
            'value' => $value,
            'type' => (string) $row['value_type'],
            'group' => (string) $row['setting_group'],
            'label' => (string) ($row['label'] ?? $row['setting_key']),
            'description' => $row['description'] ?? null,
            'validation_rules' => $this->decodeValidationRules(
                $row['validation_rules'] ?? null
            ),
            'default_value' => $isSensitive
                ? null
                : $this->castValue(
                    $row['default_value'] ?? null,
                    (string) $row['value_type']
                ),
            'is_public' => (bool) $row['is_public'],
            'is_sensitive' => $isSensitive,
            'is_editable' => (bool) $row['is_editable'],
            'sort_order' => (int) $row['sort_order'],
            'updated_by' => $row['updated_by'] === null
                ? null
                : (int) $row['updated_by'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));

        if (
            $key === ''
            || mb_strlen($key) > 150
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key) !== 1
        ) {
            throw new InvalidArgumentException('Invalid setting key.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function prepareDefinition(array $input): array
    {
        $key = $this->normalizeKey((string) ($input['setting_key'] ?? ''));
        $detectedSensitive = $this->keyLooksSensitive($key);
        $isSensitive = filter_var(
            $input['is_sensitive'] ?? $detectedSensitive,
            FILTER_VALIDATE_BOOL
        );
        $validationRules = $input['validation_rules'] ?? null;

        if (is_array($validationRules)) {
            $validationRules = json_encode(
                $validationRules,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        }

        return [
            'setting_key' => $key,
            'setting_value' => $input['setting_value'] ?? null,
            'value_type' => strtolower(trim((string) (
                $input['value_type'] ?? 'string'
            ))),
            'setting_group' => strtolower(trim((string) (
                $input['setting_group'] ?? 'general'
            ))),
            'label' => trim((string) ($input['label'] ?? $key)),
            'description' => $this->nullableString(
                $input['description'] ?? null
            ),
            'validation_rules' => $this->nullableString($validationRules),
            'default_value' => $input['default_value'] ?? null,
            'is_public' => filter_var(
                $input['is_public'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
            'is_sensitive' => $isSensitive,
            'is_editable' => filter_var(
                $input['is_editable'] ?? true,
                FILTER_VALIDATE_BOOL
            ),
            'sort_order' => (int) ($input['sort_order'] ?? 0),
        ];
    }

    /** @return array<string, string> */
    private function validateDefinition(array $data): array
    {
        $errors = [];

        if (!in_array($data['value_type'], self::VALUE_TYPES, true)) {
            $errors['value_type'] = 'ชนิดข้อมูลไม่ถูกต้อง';
        }

        if (!in_array($data['setting_group'], self::GROUPS, true)) {
            $errors['setting_group'] = 'กลุ่ม Setting ไม่ถูกต้อง';
        }

        if (
            mb_strlen($data['label']) < 2
            || mb_strlen($data['label']) > 150
        ) {
            $errors['label'] = 'Label ต้องมี 2-150 ตัวอักษร';
        }

        if (mb_strlen((string) ($data['description'] ?? '')) > 1000) {
            $errors['description'] = 'คำอธิบายต้องไม่เกิน 1,000 ตัวอักษร';
        }

        if ($data['sort_order'] < 0 || $data['sort_order'] > 9999) {
            $errors['sort_order'] = 'ลำดับต้องอยู่ระหว่าง 0-9999';
        }

        if ($data['is_public'] && $data['is_sensitive']) {
            $errors['is_public'] = 'ค่าลับไม่สามารถกำหนดเป็น Public ได้';
        }

        if ($data['validation_rules'] !== null) {
            try {
                $rules = json_decode(
                    $data['validation_rules'],
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($rules)) {
                    $errors['validation_rules'] = 'Validation rules ต้องเป็น JSON object';
                }
            } catch (JsonException) {
                $errors['validation_rules'] = 'Validation rules ไม่ใช่ JSON ที่ถูกต้อง';
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function decodeValidationRules(mixed $rules): array
    {
        if (is_array($rules)) {
            return $rules;
        }

        if (!is_string($rules) || trim($rules) === '') {
            return [];
        }

        try {
            $decoded = json_decode(
                $rules,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function castInputValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => trim((string) $value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value
                : throw new InvalidArgumentException('ค่าต้องเป็นจำนวนเต็ม'),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT) !== false
                ? (float) $value
                : throw new InvalidArgumentException('ค่าต้องเป็นตัวเลข'),
            'boolean' => $this->parseBoolean($value),
            'json' => $this->parseJson($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? mb_strtolower(trim((string) $value))
                : throw new InvalidArgumentException('รูปแบบอีเมลไม่ถูกต้อง'),
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false
                ? trim((string) $value)
                : throw new InvalidArgumentException('รูปแบบ URL ไม่ถูกต้อง'),
            'timezone' => in_array(
                (string) $value,
                timezone_identifiers_list(),
                true
            )
                ? (string) $value
                : throw new InvalidArgumentException('Timezone ไม่ถูกต้อง'),
            'color' => preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                (string) $value
            ) === 1
                ? strtoupper((string) $value)
                : throw new InvalidArgumentException('รูปแบบสีต้องเป็น #RRGGBB'),
            default => throw new InvalidArgumentException(
                'Unsupported setting value type.'
            ),
        };
    }

    private function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return $this->castInputValue($value, $type);
        } catch (InvalidArgumentException) {
            return $value;
        }
    }

    private function normalizeValueForStorage(
        mixed $value,
        string $type
    ): ?string {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $castValue = $this->castInputValue($value, $type);

        return match ($type) {
            'boolean' => $castValue ? '1' : '0',
            'json' => json_encode(
                $castValue,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ),
            default => (string) $castValue,
        };
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidArgumentException(
                'ค่าต้องเป็น true หรือ false'
            ),
        };
    }

    /** @return array<mixed> */
    private function parseJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode(
                (string) $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('รูปแบบ JSON ไม่ถูกต้อง');
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                'ค่า JSON ต้องเป็น Object หรือ Array'
            );
        }

        return $decoded;
    }

    private function keyLooksSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($key, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function encryptValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL extension is required.');
        }

        $key = $this->encryptionKey();
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);
        $tag = '';
        $cipherText = openssl_encrypt(
            $value,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt setting value.');
        }

        return 'enc:v1:' . base64_encode($iv . $tag . $cipherText);
    }

    private function decryptValue(string $value): string
    {
        if (!str_starts_with($value, 'enc:v1:')) {
            return $value;
        }

        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL extension is required.');
        }

        $payload = base64_decode(substr($value, 7), true);

        if ($payload === false) {
            throw new RuntimeException('Encrypted setting value is invalid.');
        }

        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16;

        if (strlen($payload) <= ($ivLength + $tagLength)) {
            throw new RuntimeException('Encrypted setting value is incomplete.');
        }

        $iv = substr($payload, 0, $ivLength);
        $tag = substr($payload, $ivLength, $tagLength);
        $cipherText = substr($payload, $ivLength + $tagLength);
        $plainText = openssl_decrypt(
            $cipherText,
            $cipher,
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Unable to decrypt setting value.');
        }

        return $plainText;
    }

    private function encryptionKey(): string
    {
        $encodedKey = trim((string) (
            $this->config['encryption_key']
            ?? getenv('SETTINGS_ENCRYPTION_KEY')
            ?: ''
        ));

        if ($encodedKey === '') {
            throw new RuntimeException(
                'SETTINGS_ENCRYPTION_KEY is required for sensitive settings.'
            );
        }

        $decodedKey = base64_decode($encodedKey, true);
        $keyMaterial = $decodedKey !== false ? $decodedKey : $encodedKey;

        return hash('sha256', $keyMaterial, true);
    }

    private function writeActivityLog(
        int $actorUserId,
        int $settingId,
        string $key,
        mixed $oldValue,
        mixed $newValue,
        string $action = 'update'
    ): void {
        if (empty($this->config['activity_log_enabled'])) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO activity_logs (
                user_id, action, entity_type, entity_id, description,
                old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, 'setting', :entity_id, :description,
                :old_values, :new_values, :ip_address, :user_agent,
                CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $actorUserId,
            'action' => $action,
            'entity_id' => $settingId,
            'description' => sprintf(
                '%s System Setting: %s',
                ucfirst($action),
                $key
            ),
            'old_values' => $oldValue === null
                ? null
                : json_encode(
                    [$key => $oldValue],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
            'new_values' => $newValue === null
                ? null
                : json_encode(
                    [$key => $newValue],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
            'ip_address' => $this->resolveClientIp(),
            'user_agent' => mb_substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function readCache(string $key): ?array
    {
        if (empty($this->config['cache_enabled'])) {
            return null;
        }

        $file = $this->cacheFile($key);
        $ttl = max(1, (int) ($this->config['cache_ttl_seconds'] ?? 300));

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
            $decoded = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function writeCache(string $key, array $data): void
    {
        if (empty($this->config['cache_enabled'])) {
            return;
        }

        $directory = $this->cacheDirectory();

        if (
            !is_dir($directory)
            && !mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            return;
        }

        $payload = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
        $temporary = tempnam($directory, 'settings_');

        if ($temporary === false) {
            return;
        }

        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                return;
            }

            chmod($temporary, 0640);

            if (!rename($temporary, $this->cacheFile($key))) {
                return;
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function cacheDirectory(): string
    {
        return rtrim(
            (string) ($this->config['cache_path'] ?? sys_get_temp_dir()),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . 'connectpro-settings';
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDirectory()
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.json';
    }

    private function resolveClientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
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
