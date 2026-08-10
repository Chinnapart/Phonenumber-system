<?php

declare(strict_types=1);

/**
 * ConnectPro Contact Quick Search Endpoint
 * File: api/contacts/search.php
 * Method: GET
 * Permission: contacts.view
 *
 * Query example:
 * GET /api/contacts/search.php?q=somchai&limit=10
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
        'slow_request_ms' => 500.0,
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

    $input = connectpro_validation_request([
        'q' => 'required|string|min_length:2|max_length:150',
        'department_id' => 'nullable|integer|min:1',
        'location_id' => 'nullable|integer|min:1',
        'presence_status' =>
            'nullable|in:online,busy,away,offline,unknown',
        'limit' => 'nullable|integer|min:1|max:50',
    ], [
        'allowed_fields' => [
            'q',
            'department_id',
            'location_id',
            'presence_status',
            'limit',
        ],
        'reject_unknown_fields' => true,
        'labels' => [
            'q' => 'คำค้นหา',
            'department_id' => 'แผนก',
            'location_id' => 'สถานที่',
            'presence_status' => 'สถานะ Presence',
            'limit' => 'จำนวนผลลัพธ์',
        ],
        'casts' => [
            'q' => 'string',
            'department_id' => 'integer',
            'location_id' => 'integer',
            'presence_status' => 'lowercase',
            'limit' => 'integer',
        ],
    ]);

    $maximumLimit = max(
        1,
        min(50, (int) ($contactConfig['search_max_results'] ?? 20))
    );
    $limit = min(
        max(1, (int) ($input['limit'] ?? 10)),
        $maximumLimit
    );
    $filters = [
        'search' => trim((string) $input['q']),
        'department_id' => $input['department_id'] ?? null,
        'location_id' => $input['location_id'] ?? null,
        'presence_status' => $input['presence_status'] ?? null,
        'status' => 'active',
        'sort' => 'name_asc',
        'page' => 1,
        'per_page' => $limit,
    ];

    $service = new ContactService($pdo, [
        'default_per_page' => $limit,
        'max_per_page' => $maximumLimit,
        'activity_log_enabled' => false,
    ]);
    $result = $service->search(
        $filters,
        (int) $authContext['user_id']
    );

    if (!is_array($result) || !is_array($result['items'] ?? null)) {
        throw new UnexpectedValueException(
            'ContactService returned an invalid search result.'
        );
    }

    $items = array_map(
        static function (array $contact): array {
            return [
                'id' => (int) ($contact['id'] ?? 0),
                'employee_code' => (string) (
                    $contact['employee_code'] ?? ''
                ),
                'display_name' => (string) (
                    $contact['display_name'] ?? ''
                ),
                'position' => $contact['position'] ?? null,
                'department_id' => isset($contact['department_id'])
                    ? (int) $contact['department_id']
                    : null,
                'department_name' => $contact['department_name'] ?? null,
                'location_id' => isset($contact['location_id'])
                    ? (int) $contact['location_id']
                    : null,
                'location_name' => $contact['location_name'] ?? null,
                'extension_number' => $contact['extension_number'] ?? null,
                'mobile_number' => $contact['mobile_number'] ?? null,
                'email' => $contact['email'] ?? null,
                'presence_status' => (string) (
                    $contact['presence_status'] ?? 'unknown'
                ),
            ];
        },
        $result['items']
    );
    $total = max(
        count($items),
        (int) ($result['pagination']['total'] ?? count($items))
    );

    connectpro_response_success([
        'query' => $filters['search'],
        'items' => $items,
        'count' => count($items),
        'total' => $total,
        'has_more' => $total > count($items),
    ], 'ค้นหาผู้ติดต่อสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
