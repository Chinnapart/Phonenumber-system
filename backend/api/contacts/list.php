<?php

declare(strict_types=1);

/**
 * ConnectPro Contact List Endpoint
 * File: api/contacts/list.php
 * Method: GET
 * Permission: contacts.view
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
        'log_request_body' => false,
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

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        connectpro_response_method_not_allowed(['GET']);
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
        'permissions' => ['contacts.view'],
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
            'csrf' => false,
            'allow_password_change_only' => false,
        ],
    ]);

    $filters = connectpro_validation_request([
        'search' => 'nullable|string|max_length:150',
        'department_id' => 'nullable|integer|min:1',
        'location_id' => 'nullable|integer|min:1',
        'status' => 'nullable|in:active,inactive',
        'presence_status' => 'nullable|in:online,busy,away,offline,unknown',
        'sort' => 'nullable|in:name_asc,name_desc,department,updated_desc',
        'page' => 'nullable|integer|min:1|max:1000000',
        'per_page' => 'nullable|integer|min:1|max:100',
    ], [
        'allowed_fields' => [
            'search',
            'department_id',
            'location_id',
            'status',
            'presence_status',
            'sort',
            'page',
            'per_page',
        ],
        'reject_unknown_fields' => true,
        'labels' => [
            'search' => 'คำค้นหา',
            'department_id' => 'แผนก',
            'location_id' => 'สถานที่',
            'status' => 'สถานะข้อมูล',
            'presence_status' => 'สถานะ Presence',
            'sort' => 'การเรียงลำดับ',
            'page' => 'หน้าปัจจุบัน',
            'per_page' => 'จำนวนรายการต่อหน้า',
        ],
        'casts' => [
            'search' => 'string',
            'department_id' => 'integer',
            'location_id' => 'integer',
            'status' => 'lowercase',
            'presence_status' => 'lowercase',
            'sort' => 'lowercase',
            'page' => 'integer',
            'per_page' => 'integer',
        ],
    ]);

    $filters['page'] = (int) ($filters['page'] ?? 1);
    $filters['per_page'] = min(
        max(1, (int) ($filters['per_page']
            ?? $contactConfig['default_per_page']
            ?? 20)),
        max(1, (int) ($contactConfig['max_per_page'] ?? 100))
    );
    $filters['sort'] = (string) ($filters['sort'] ?? 'name_asc');

    $service = new ContactService($pdo, [
        'default_per_page' => max(
            1,
            (int) ($contactConfig['default_per_page'] ?? 20)
        ),
        'max_per_page' => max(
            1,
            (int) ($contactConfig['max_per_page'] ?? 100)
        ),
        'activity_log_enabled' => (bool) (
            $loggingConfig['activity_enabled'] ?? true
        ),
    ]);

    $result = $service->search(
        $filters,
        (int) $authContext['user_id']
    );

    if (!is_array($result)) {
        throw new UnexpectedValueException(
            'ContactService returned an invalid list result.'
        );
    }

    connectpro_response_paginated(
        $result,
        'โหลดรายชื่อผู้ติดต่อสำเร็จ',
        [
            'filters' => [
                'search' => $filters['search'] ?? null,
                'department_id' => $filters['department_id'] ?? null,
                'location_id' => $filters['location_id'] ?? null,
                'status' => $filters['status'] ?? null,
                'presence_status' => $filters['presence_status'] ?? null,
                'sort' => $filters['sort'],
            ],
        ]
    );
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
