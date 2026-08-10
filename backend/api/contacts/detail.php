<?php

declare(strict_types=1);

/**
 * ConnectPro Contact Detail Endpoint
 * File: api/contacts/detail.php
 * Method: GET
 * Permission: contacts.view
 *
 * Query:
 * GET /api/contacts/detail.php?id=123
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

    $input = connectpro_validation_request([
        'id' => 'required|integer|min:1|max:2147483647',
    ], [
        'allowed_fields' => ['id'],
        'reject_unknown_fields' => true,
        'labels' => [
            'id' => 'รหัสผู้ติดต่อ',
        ],
        'casts' => [
            'id' => 'integer',
        ],
    ]);

    $contactId = (int) $input['id'];
    $service = new ContactService($pdo, [
        'activity_log_enabled' => (bool) (
            $loggingConfig['activity_enabled'] ?? true
        ),
    ]);

    if (!method_exists($service, 'findById')) {
        throw new LogicException(
            'ContactService::findById() is required by detail.php.'
        );
    }

    $contact = $service->findById($contactId);

    if ($contact === null || $contact === false || !is_array($contact)) {
        connectpro_response_not_found(
            'ไม่พบข้อมูลผู้ติดต่อ',
            'CONTACT_NOT_FOUND'
        );
    }

    // Do not expose internal integration credentials if future service
    // versions attach them to the contact payload.
    unset(
        $contact['password'],
        $contact['password_hash'],
        $contact['access_token'],
        $contact['refresh_token'],
        $contact['integration_secret']
    );

    $canUpdate = in_array(
        'contacts.update',
        $authContext['permissions'] ?? [],
        true
    );
    $canDelete = in_array(
        'contacts.delete',
        $authContext['permissions'] ?? [],
        true
    );
    $canUpdatePresence = in_array(
        'presence.update',
        $authContext['permissions'] ?? [],
        true
    );

    connectpro_response_success([
        'contact' => $contact,
        'capabilities' => [
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'can_update_presence' => $canUpdatePresence,
        ],
    ], 'โหลดรายละเอียดผู้ติดต่อสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
