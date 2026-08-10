<?php

declare(strict_types=1);

/**
 * ConnectPro Request Validation Helper
 *
 * File: api/helpers/validation.php
 *
 * Responsibilities:
 * - Read JSON, form, and query request data safely
 * - Reject malformed JSON and oversized request bodies
 * - Validate fields using reusable declarative rules
 * - Normalize common data types without mutating global input
 * - Reject unknown fields when an allowlist is supplied
 * - Return structured validation errors compatible with response.php
 */

if (!function_exists('connectpro_validation_error')) {
    /**
     * @param array<string|int, mixed> $errors
     */
    function connectpro_validation_error(
        array $errors,
        string $message = 'ข้อมูลที่ส่งมาไม่ถูกต้อง'
    ): never {
        if (function_exists('connectpro_response_validation_error')) {
            connectpro_response_validation_error($errors, $message);
        }

        if (!headers_sent()) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => $message,
                'details' => ['fields' => $errors],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }
}

if (!function_exists('connectpro_validation_bad_request')) {
    function connectpro_validation_bad_request(
        string $code,
        string $message
    ): never {
        if (function_exists('connectpro_response_error')) {
            connectpro_response_error($code, $message, 400);
        }

        if (!headers_sent()) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }
}

if (!function_exists('connectpro_validation_content_type')) {
    function connectpro_validation_content_type(): string
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE']
            ?? $_SERVER['HTTP_CONTENT_TYPE']
            ?? '');

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}

if (!function_exists('connectpro_validation_read_input')) {
    /**
     * Read request data according to HTTP method and Content-Type.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function connectpro_validation_read_input(array $options = []): array
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $contentType = connectpro_validation_content_type();
        $maximumBytes = max(1, (int) ($options['max_body_bytes'] ?? 1048576));
        $allowEmpty = (bool) ($options['allow_empty'] ?? false);

        if (in_array($method, ['GET', 'HEAD'], true)) {
            return is_array($_GET) ? $_GET : [];
        }

        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

            if ($contentLength > $maximumBytes) {
                connectpro_validation_bad_request(
                    'REQUEST_BODY_TOO_LARGE',
                    'ขนาด Request Body เกินกำหนด'
                );
            }

            $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);

            if (!is_string($raw)) {
                connectpro_validation_bad_request(
                    'REQUEST_BODY_UNREADABLE',
                    'ไม่สามารถอ่าน Request Body ได้'
                );
            }

            if (strlen($raw) > $maximumBytes) {
                connectpro_validation_bad_request(
                    'REQUEST_BODY_TOO_LARGE',
                    'ขนาด Request Body เกินกำหนด'
                );
            }

            if (trim($raw) === '') {
                if ($allowEmpty) {
                    return [];
                }

                connectpro_validation_bad_request(
                    'REQUEST_BODY_REQUIRED',
                    'กรุณาส่งข้อมูลใน Request Body'
                );
            }

            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                connectpro_validation_bad_request(
                    'INVALID_JSON',
                    'รูปแบบ JSON ไม่ถูกต้อง'
                );
            }

            if (!is_array($decoded) || array_is_list($decoded)) {
                connectpro_validation_bad_request(
                    'JSON_OBJECT_REQUIRED',
                    'Request Body ต้องเป็น JSON Object'
                );
            }

            return $decoded;
        }

        if (
            $contentType === 'application/x-www-form-urlencoded'
            || $contentType === 'multipart/form-data'
            || $_POST !== []
        ) {
            return is_array($_POST) ? $_POST : [];
        }

        if ($allowEmpty) {
            return [];
        }

        connectpro_validation_bad_request(
            'UNSUPPORTED_CONTENT_TYPE',
            'Content-Type นี้ไม่รองรับ'
        );
    }
}

if (!function_exists('connectpro_validation_parse_rules')) {
    /** @return list<string|array<string, mixed>> */
    function connectpro_validation_parse_rules(string|array $rules): array
    {
        if (is_array($rules)) {
            return array_values($rules);
        }

        if (trim($rules) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            explode('|', $rules)
        ), static fn (string $rule): bool => $rule !== ''));
    }
}

if (!function_exists('connectpro_validation_rule_parts')) {
    /** @return array{0: string, 1: list<string>} */
    function connectpro_validation_rule_parts(string|array $rule): array
    {
        if (is_array($rule)) {
            $name = strtolower(trim((string) ($rule['rule'] ?? '')));
            $parameters = $rule['parameters'] ?? [];

            if (!is_array($parameters)) {
                $parameters = [$parameters];
            }

            return [$name, array_map('strval', array_values($parameters))];
        }

        [$name, $parameterString] = array_pad(explode(':', $rule, 2), 2, '');
        $parameters = $parameterString === ''
            ? []
            : array_map('trim', explode(',', $parameterString));

        return [strtolower(trim($name)), $parameters];
    }
}

if (!function_exists('connectpro_validation_is_empty')) {
    function connectpro_validation_is_empty(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }
}

if (!function_exists('connectpro_validation_boolean')) {
    function connectpro_validation_boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}

if (!function_exists('connectpro_validation_date')) {
    function connectpro_validation_date(
        mixed $value,
        string $format = 'Y-m-d'
    ): ?DateTimeImmutable {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, trim($value));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false) {
            return null;
        }

        if (
            is_array($errors)
            && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
        ) {
            return null;
        }

        return $date;
    }
}

if (!function_exists('connectpro_validation_message')) {
    /**
     * @param list<string> $parameters
     */
    function connectpro_validation_message(
        string $field,
        string $rule,
        array $parameters,
        array $labels
    ): string {
        $label = (string) ($labels[$field] ?? $field);
        $parameter = $parameters[0] ?? '';

        return match ($rule) {
            'required' => "กรุณาระบุ {$label}",
            'required_if' => "กรุณาระบุ {$label}",
            'string' => "{$label} ต้องเป็นข้อความ",
            'integer' => "{$label} ต้องเป็นจำนวนเต็ม",
            'numeric' => "{$label} ต้องเป็นตัวเลข",
            'boolean' => "{$label} ต้องเป็นค่า true หรือ false",
            'array' => "{$label} ต้องเป็นรายการข้อมูล",
            'email' => "รูปแบบ {$label} ไม่ถูกต้อง",
            'url' => "รูปแบบ {$label} ไม่ถูกต้อง",
            'ip' => "รูปแบบ {$label} ไม่ถูกต้อง",
            'date' => "รูปแบบ {$label} ไม่ถูกต้อง",
            'min' => "{$label} ต้องไม่น้อยกว่า {$parameter}",
            'max' => "{$label} ต้องไม่มากกว่า {$parameter}",
            'min_length' => "{$label} ต้องมีอย่างน้อย {$parameter} ตัวอักษร",
            'max_length' => "{$label} ต้องไม่เกิน {$parameter} ตัวอักษร",
            'length' => "{$label} ต้องมี {$parameter} ตัวอักษร",
            'in' => "ค่า {$label} ไม่อยู่ในรายการที่อนุญาต",
            'not_in' => "ค่า {$label} ไม่ได้รับอนุญาต",
            'regex' => "รูปแบบ {$label} ไม่ถูกต้อง",
            'same' => "{$label} ต้องตรงกับ " . ($parameters[0] ?? 'ข้อมูลที่กำหนด'),
            'different' => "{$label} ต้องไม่เหมือน " . ($parameters[0] ?? 'ข้อมูลที่กำหนด'),
            'confirmed' => "การยืนยัน {$label} ไม่ตรงกัน",
            'nullable' => '',
            default => "{$label} ไม่ถูกต้อง",
        };
    }
}

if (!function_exists('connectpro_validation_numeric_value')) {
    function connectpro_validation_numeric_value(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}

if (!function_exists('connectpro_validation_rule_passes')) {
    /**
     * @param list<string> $parameters
     */
    function connectpro_validation_rule_passes(
        string $field,
        mixed $value,
        string $rule,
        array $parameters,
        array $data
    ): bool {
        return match ($rule) {
            'required' => !connectpro_validation_is_empty($value),
            'required_if' => !(
                isset($parameters[0], $parameters[1])
                && (string) ($data[$parameters[0]] ?? '') === $parameters[1]
                && connectpro_validation_is_empty($value)
            ),
            'nullable' => true,
            'string' => is_string($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric($value),
            'boolean' => connectpro_validation_boolean($value) !== null,
            'array' => is_array($value),
            'email' => is_string($value)
                && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value)
                && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => is_string($value)
                && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'date' => connectpro_validation_date(
                $value,
                $parameters[0] ?? 'Y-m-d'
            ) !== null,
            'min' => ($numeric = connectpro_validation_numeric_value($value)) !== null
                && $numeric >= (float) ($parameters[0] ?? 0),
            'max' => ($numeric = connectpro_validation_numeric_value($value)) !== null
                && $numeric <= (float) ($parameters[0] ?? 0),
            'min_length' => is_string($value)
                && mb_strlen($value) >= (int) ($parameters[0] ?? 0),
            'max_length' => is_string($value)
                && mb_strlen($value) <= (int) ($parameters[0] ?? 0),
            'length' => is_string($value)
                && mb_strlen($value) === (int) ($parameters[0] ?? 0),
            'in' => in_array((string) $value, $parameters, true),
            'not_in' => !in_array((string) $value, $parameters, true),
            'regex' => isset($parameters[0])
                && @preg_match($parameters[0], '') !== false
                && preg_match($parameters[0], (string) $value) === 1,
            'same' => isset($parameters[0])
                && $value === ($data[$parameters[0]] ?? null),
            'different' => isset($parameters[0])
                && $value !== ($data[$parameters[0]] ?? null),
            'confirmed' => $value === ($data[$field . '_confirmation'] ?? null),
            default => throw new InvalidArgumentException(
                'Unknown validation rule: ' . $rule
            ),
        };
    }
}

if (!function_exists('connectpro_validate')) {
    /**
     * Validate and normalize an input array.
     *
     * @param array<string, mixed> $data
     * @param array<string, string|array> $rules
     * @param array<string, string> $labels
     * @param array<string, string> $customMessages
     * @return array{valid: bool, data: array<string, mixed>, errors: array<string, list<string>>}
     */
    function connectpro_validate(
        array $data,
        array $rules,
        array $labels = [],
        array $customMessages = []
    ): array {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            if (!is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException('Invalid validation field.');
            }

            $parsedRules = connectpro_validation_parse_rules($fieldRules);
            $exists = array_key_exists($field, $data);
            $value = $exists ? $data[$field] : null;
            $nullable = false;

            foreach ($parsedRules as $ruleDefinition) {
                [$rule] = connectpro_validation_rule_parts($ruleDefinition);

                if ($rule === 'nullable') {
                    $nullable = true;
                    break;
                }
            }

            if ($nullable && connectpro_validation_is_empty($value)) {
                $validated[$field] = null;
                continue;
            }

            foreach ($parsedRules as $ruleDefinition) {
                [$rule, $parameters] = connectpro_validation_rule_parts(
                    $ruleDefinition
                );

                if ($rule === '') {
                    continue;
                }

                if (
                    !$exists
                    && !in_array($rule, ['required', 'required_if'], true)
                ) {
                    continue;
                }

                if (!connectpro_validation_rule_passes(
                    $field,
                    $value,
                    $rule,
                    $parameters,
                    $data
                )) {
                    $messageKey = $field . '.' . $rule;
                    $message = $customMessages[$messageKey]
                        ?? connectpro_validation_message(
                            $field,
                            $rule,
                            $parameters,
                            $labels
                        );
                    $errors[$field] ??= [];
                    $errors[$field][] = $message;
                }
            }

            if (!isset($errors[$field]) && $exists) {
                $validated[$field] = $value;
            }
        }

        return [
            'valid' => $errors === [],
            'data' => $validated,
            'errors' => $errors,
        ];
    }
}

if (!function_exists('connectpro_validation_cast')) {
    /**
     * Cast validated fields according to an explicit schema.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $casts
     * @return array<string, mixed>
     */
    function connectpro_validation_cast(array $data, array $casts): array
    {
        foreach ($casts as $field => $type) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $data[$field] = match (strtolower(trim($type))) {
                'string' => trim((string) $data[$field]),
                'integer', 'int' => (int) $data[$field],
                'float', 'double' => (float) $data[$field],
                'boolean', 'bool' => connectpro_validation_boolean(
                    $data[$field]
                ),
                'array' => is_array($data[$field])
                    ? $data[$field]
                    : [$data[$field]],
                'lowercase' => mb_strtolower(trim((string) $data[$field])),
                'uppercase' => mb_strtoupper(trim((string) $data[$field])),
                'date' => connectpro_validation_date($data[$field])
                    ?->format('Y-m-d'),
                default => throw new InvalidArgumentException(
                    'Unknown validation cast: ' . $type
                ),
            };
        }

        return $data;
    }
}

if (!function_exists('connectpro_validation_only')) {
    /**
     * Keep only explicitly allowed request fields.
     *
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    function connectpro_validation_only(
        array $data,
        array $allowedFields
    ): array {
        $allowed = array_fill_keys(array_values(array_filter(
            $allowedFields,
            static fn (mixed $field): bool => is_string($field)
                && trim($field) !== ''
        )), true);

        return array_intersect_key($data, $allowed);
    }
}

if (!function_exists('connectpro_validation_unknown_fields')) {
    /**
     * @param list<string> $allowedFields
     * @return list<string>
     */
    function connectpro_validation_unknown_fields(
        array $data,
        array $allowedFields
    ): array {
        $allowed = array_fill_keys($allowedFields, true);

        return array_values(array_filter(
            array_keys($data),
            static fn (string|int $field): bool => !isset($allowed[$field])
        ));
    }
}

if (!function_exists('connectpro_validation_request')) {
    /**
     * Read, allowlist, validate, cast, and return request data.
     * Terminates with HTTP 400 or 422 when validation fails.
     *
     * @param array<string, string|array> $rules
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function connectpro_validation_request(
        array $rules,
        array $options = []
    ): array {
        $input = connectpro_validation_read_input($options);
        $allowedFields = $options['allowed_fields'] ?? array_keys($rules);

        if (!is_array($allowedFields)) {
            throw new InvalidArgumentException(
                'allowed_fields must be an array.'
            );
        }

        if (!empty($options['reject_unknown_fields'])) {
            $unknown = connectpro_validation_unknown_fields(
                $input,
                $allowedFields
            );

            if ($unknown !== []) {
                connectpro_validation_error([
                    '_unknown_fields' => array_map(
                        static fn (string|int $field): string =>
                            'ไม่รองรับ Field: ' . $field,
                        $unknown
                    ),
                ]);
            }
        }

        $input = connectpro_validation_only($input, $allowedFields);
        $labels = is_array($options['labels'] ?? null)
            ? $options['labels']
            : [];
        $messages = is_array($options['messages'] ?? null)
            ? $options['messages']
            : [];
        $result = connectpro_validate($input, $rules, $labels, $messages);

        if (!$result['valid']) {
            connectpro_validation_error($result['errors']);
        }

        $casts = is_array($options['casts'] ?? null)
            ? $options['casts']
            : [];

        return connectpro_validation_cast($result['data'], $casts);
    }
}

return [
    'read_input' => static fn (array $options = []): array =>
        connectpro_validation_read_input($options),
    'validate' => static fn (
        array $data,
        array $rules,
        array $labels = [],
        array $messages = []
    ): array => connectpro_validate($data, $rules, $labels, $messages),
    'request' => static fn (
        array $rules,
        array $options = []
    ): array => connectpro_validation_request($rules, $options),
    'cast' => static fn (array $data, array $casts): array =>
        connectpro_validation_cast($data, $casts),
    'only' => static fn (array $data, array $fields): array =>
        connectpro_validation_only($data, $fields),
];
