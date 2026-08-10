<?php

declare(strict_types=1);

/**
 * ConnectPro Update Contact Endpoint
 * File: api/contacts/update.php
 * Methods: PUT, PATCH
 * Permission: contacts.update
 *
 * Query:
 * /api/contacts/update.php?id=123
 */

$apiRoot = dirname(__DIR__);

require_once $apiRoot . '/helpers/response.php';
require_once $apiRoot . '/helpers/validation.php';
require_once $apiRoot . '/helpers/security.php';
require_once $apiRoot . '/classes/ContactService.php';

try {
    $databaseResult = require $apiRoot . '/config/database.php';

    if ($databaseResult instanceof PDO) {
        $pdo = $databaseResult;
    }

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $appConfig = require $apiRoot . '/config/app.php';

    if (!is_array($appConfig)) {
        $appConfig = [];
    }

    $loggingConfig = is_array($appConfig['logging'] ?? null)
        ? $appConfig['logging']
        : [];
    $securityConfig = is_array($appConfig['security'] ?? null)
        ? $appConfig['security']
        : [];
    $sessionConfig = is_array($appConfig['session'] ?? null)
        ? $appConfig['session']
        : [];
    $contactConfig = is_array($appConfig['contacts'] ?? null)
        ? $appConfig['contacts']
        : [];

    $requestLog = require $apiRoot . '/middleware/request-log.php';
    $requestLog([
        'pdo' => $pdo,
        'database_enabled' => (bool) (
            $loggingConfig['request_database_enabled'] ?? false
        ),
        'file_enabled' => (bool) (
            $loggingConfig['request_file_enabled'] ?? true
        ),
        'log_path' => $apiRoot . '/storage/logs',
        'log_request_body' => (bool) (
            $loggingConfig['contact_write_body_enabled'] ?? false
        ),
        'max_body_log_bytes' => 16384,
        'sample_rate' => 1.0,
        'slow_request_ms' => 1000.0,
    ]);

    connectpro_security_headers([
        'no_cache' => true,
        'hsts' => (bool) ($securityConfig['hsts_enabled'] ?? false),
        'hsts_max_age' => (int) (
            $securityConfig['hsts_max_age'] ?? 31536000
        ),
    ]);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, ['PUT', 'PATCH'], true)) {
        connectpro_response_method_not_allowed(['PUT', 'PATCH']);
    }

    $allowedOrigins = $securityConfig['allowed_origins'] ?? [];

    if (
        is_array($allowedOrigins)
        && $allowedOrigins !== []
        && !connectpro_security_origin_allowed($allowedOrigins, true)
    ) {
        connectpro_response_forbidden(
            'Origin ไม่ได้รับอนุญาต',
            'ORIGIN_NOT_ALLOWED'
        );
    }

    $permission = require $apiRoot . '/middleware/permission.php';
    $authContext = $permission([
        'permissions' => ['contacts.update'],
        'mode' => 'all',
        'auth' => [
            'pdo' => $pdo,
            'refresh_user' => true,
            'session_registry_enabled' => (bool) (
                $sessionConfig['registry_enabled'] ?? false
            ),
            'touch_session_registry' => (bool) (
                $sessionConfig['touch_registry_on_check'] ?? true
            ),
            'csrf' => true,
            'allow_password_change_only' => false,
        ],
    ]);

    $queryValidation = connectpro_validate($_GET, [
        'id' => 'required|integer|min:1|max:2147483647',
    ], [
        'id' => 'รหัสผู้ติดต่อ',
    ]);

    $unknownQueryFields = connectpro_validation_unknown_fields(
        $_GET,
        ['id']
    );

    if ($unknownQueryFields !== []) {
        connectpro_validation_error([
            '_unknown_query_fields' => array_map(
                static fn (string|int $field): string =>
                    'ไม่รองรับ Query Field: ' . $field,
                $unknownQueryFields
            ),
        ]);
    }

    if (!$queryValidation['valid']) {
        connectpro_validation_error($queryValidation['errors']);
    }

    $contactId = (int) $queryValidation['data']['id'];
    $input = connectpro_validation_read_input([
        'max_body_bytes' => max(
            4096,
            min(65536, (int) ($contactConfig['max_update_bytes'] ?? 32768))
        ),
        'allow_empty' => false,
    ]);
    $allowedFields = [
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
    $unknownFields = connectpro_validation_unknown_fields(
        $input,
        $allowedFields
    );

    if ($unknownFields !== []) {
        connectpro_validation_error([
            '_unknown_fields' => array_map(
                static fn (string|int $field): string =>
                    'ไม่รองรับ Field: ' . $field,
                $unknownFields
            ),
        ]);
    }

    if ($input === []) {
        connectpro_validation_error([
            '_request' => ['กรุณาระบุข้อมูลที่ต้องการแก้ไขอย่างน้อย 1 Field'],
        ]);
    }

    $allRules = [
        'employee_code' => 'required|string|min_length:1|max_length:50',
        'display_name' => 'required|string|min_length:2|max_length:150',
        'position' => 'nullable|string|max_length:150',
        'department_id' => 'required|integer|min:1',
        'location_id' => 'nullable|integer|min:1',
        'extension_number' =>
            'nullable|string|max_length:30|regex:/^[0-9*#+(). -]+$/',
        'mobile_number' =>
            'nullable|string|max_length:30|regex:/^[0-9+(). -]+$/',
        'email' => 'nullable|email|max_length:190',
        'ip_address' => 'nullable|ip|max_length:45',
        'status' => 'nullable|in:active,inactive',
        'notes' => 'nullable|string|max_length:2000',
    ];
    $rules = array_intersect_key($allRules, $input);
    $labels = [
        'employee_code' => 'รหัสพนักงาน',
        'display_name' => 'ชื่อผู้ติดต่อ',
        'position' => 'ตำแหน่ง',
        'department_id' => 'แผนก',
        'location_id' => 'สถานที่',
        'extension_number' => 'เบอร์ต่อ',
        'mobile_number' => 'เบอร์โทรศัพท์มือถือ',
        'email' => 'อีเมล',
        'ip_address' => 'IP Address',
        'status' => 'สถานะ',
        'notes' => 'หมายเหตุ',
    ];
    $validation = connectpro_validate($input, $rules, $labels);

    if (!$validation['valid']) {
        connectpro_validation_error($validation['errors']);
    }

    $casts = array_intersect_key([
        'employee_code' => 'uppercase',
        'display_name' => 'string',
        'position' => 'string',
        'department_id' => 'integer',
        'location_id' => 'integer',
        'extension_number' => 'string',
        'mobile_number' => 'string',
        'email' => 'lowercase',
        'ip_address' => 'string',
        'status' => 'lowercase',
        'notes' => 'string',
    ], $validation['data']);
    $data = connectpro_validation_cast($validation['data'], $casts);

    foreach ([
        'position',
        'location_id',
        'extension_number',
        'mobile_number',
        'email',
        'ip_address',
        'notes',
    ] as $nullableField) {
        if (
            array_key_exists($nullableField, $data)
            && ($data[$nullableField] === '' || $data[$nullableField] === null)
        ) {
            $data[$nullableField] = null;
        }
    }

    $service = new ContactService($pdo, [
        'activity_log_enabled' => (bool) (
            $loggingConfig['activity_enabled'] ?? true
        ),
    ]);

    if (!method_exists($service, 'findById') || !method_exists($service, 'update')) {
        throw new LogicException(
            'ContactService::findById() and update() are required by update.php.'
        );
    }

    $existing = $service->findById($contactId);

    if ($existing === null || $existing === false || !is_array($existing)) {
        connectpro_response_not_found(
            'ไม่พบข้อมูลผู้ติดต่อ',
            'CONTACT_NOT_FOUND'
        );
    }

    $contact = $service->update(
        $contactId,
        $data,
        (int) $authContext['user_id']
    );

    if (!is_array($contact)) {
        throw new UnexpectedValueException(
            'ContactService returned an invalid updated contact.'
        );
    }

    unset(
        $contact['password'],
        $contact['password_hash'],
        $contact['access_token'],
        $contact['refresh_token'],
        $contact['integration_secret']
    );

    connectpro_response_success([
        'contact' => $contact,
        'updated_fields' => array_values(array_keys($data)),
    ], 'แก้ไขข้อมูลผู้ติดต่อสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
