<?php

declare(strict_types=1);

/**
 * ConnectPro Contact Status Endpoint
 * File: api/contacts/status.php
 * Method: PATCH
 * Permission: contacts.update
 *
 * Query:
 * /api/contacts/status.php?id=123
 *
 * JSON body:
 * {
 *   "status": "active|inactive",
 *   "reason": "optional reason"
 * }
 *
 * This endpoint changes the contact record status only. Presence must be
 * changed through the dedicated Presence API.
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

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'PATCH') {
        connectpro_response_method_not_allowed(['PATCH']);
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
    $unknownQueryFields = connectpro_validation_unknown_fields($_GET, ['id']);

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
    $data = connectpro_validation_request([
        'status' => 'required|in:active,inactive',
        'reason' => 'nullable|string|max_length:500',
    ], [
        'max_body_bytes' => max(
            1024,
            min(8192, (int) ($contactConfig['max_status_bytes'] ?? 4096))
        ),
        'allowed_fields' => ['status', 'reason'],
        'reject_unknown_fields' => true,
        'labels' => [
            'status' => 'สถานะผู้ติดต่อ',
            'reason' => 'เหตุผลที่เปลี่ยนสถานะ',
        ],
        'casts' => [
            'status' => 'lowercase',
            'reason' => 'string',
        ],
    ]);

    $newStatus = (string) $data['status'];
    $reason = isset($data['reason'])
        ? trim((string) $data['reason'])
        : null;
    $reason = $reason === '' ? null : $reason;
    $service = new ContactService($pdo, [
        'activity_log_enabled' => (bool) (
            $loggingConfig['activity_enabled'] ?? true
        ),
    ]);

    if (!method_exists($service, 'findById')) {
        throw new LogicException(
            'ContactService::findById() is required by status.php.'
        );
    }

    $existing = $service->findById($contactId);

    if ($existing === null || $existing === false || !is_array($existing)) {
        connectpro_response_not_found(
            'ไม่พบข้อมูลผู้ติดต่อ',
            'CONTACT_NOT_FOUND'
        );
    }

    $oldStatus = strtolower((string) ($existing['status'] ?? ''));

    if ($oldStatus === $newStatus) {
        connectpro_response_conflict(
            'ผู้ติดต่อนี้มีสถานะดังกล่าวอยู่แล้ว',
            'CONTACT_STATUS_UNCHANGED',
            ['current_status' => $oldStatus]
        );
    }

    $updateData = ['status' => $newStatus];

    if ($reason !== null) {
        $existingNotes = trim((string) ($existing['notes'] ?? ''));
        $statusNote = sprintf(
            '[Status: %s -> %s] %s',
            $oldStatus !== '' ? $oldStatus : 'unknown',
            $newStatus,
            $reason
        );
        $updateData['notes'] = $existingNotes === ''
            ? $statusNote
            : $existingNotes . PHP_EOL . $statusNote;
    }

    if (method_exists($service, 'changeStatus')) {
        $contact = $service->changeStatus(
            $contactId,
            $newStatus,
            (int) $authContext['user_id'],
            $reason
        );
    } elseif (method_exists($service, 'updateStatus')) {
        $contact = $service->updateStatus(
            $contactId,
            $newStatus,
            (int) $authContext['user_id'],
            $reason
        );
    } elseif (method_exists($service, 'update')) {
        $contact = $service->update(
            $contactId,
            $updateData,
            (int) $authContext['user_id']
        );
    } else {
        throw new LogicException(
            'ContactService status update method is unavailable.'
        );
    }

    if (!is_array($contact)) {
        throw new UnexpectedValueException(
            'ContactService returned an invalid status result.'
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
        'status_change' => [
            'from' => $oldStatus,
            'to' => $newStatus,
            'reason' => $reason,
        ],
    ], 'เปลี่ยนสถานะผู้ติดต่อสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
