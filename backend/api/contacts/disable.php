<?php

declare(strict_types=1);

/**
 * ConnectPro Disable Contact Endpoint
 * File: api/contacts/disable.php
 * Method: POST
 * Permission: contacts.update
 *
 * Query:
 * /api/contacts/disable.php?id=123
 *
 * Optional JSON body:
 * {
 *   "reason": "Employee resigned"
 * }
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

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        connectpro_response_method_not_allowed(['POST']);
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
    $input = connectpro_validation_read_input([
        'max_body_bytes' => max(
            1024,
            min(8192, (int) ($contactConfig['max_disable_bytes'] ?? 4096))
        ),
        'allow_empty' => true,
    ]);
    $unknownFields = connectpro_validation_unknown_fields($input, ['reason']);

    if ($unknownFields !== []) {
        connectpro_validation_error([
            '_unknown_fields' => array_map(
                static fn (string|int $field): string =>
                    'ไม่รองรับ Field: ' . $field,
                $unknownFields
            ),
        ]);
    }

    $validation = connectpro_validate($input, [
        'reason' => 'nullable|string|max_length:500',
    ], [
        'reason' => 'เหตุผลที่ปิดใช้งาน',
    ]);

    if (!$validation['valid']) {
        connectpro_validation_error($validation['errors']);
    }

    $reason = isset($validation['data']['reason'])
        ? trim((string) $validation['data']['reason'])
        : null;
    $reason = $reason === '' ? null : $reason;
    $service = new ContactService($pdo, [
        'activity_log_enabled' => (bool) (
            $loggingConfig['activity_enabled'] ?? true
        ),
    ]);

    if (!method_exists($service, 'findById')) {
        throw new LogicException(
            'ContactService::findById() is required by disable.php.'
        );
    }

    $existing = $service->findById($contactId);

    if ($existing === null || $existing === false || !is_array($existing)) {
        connectpro_response_not_found(
            'ไม่พบข้อมูลผู้ติดต่อ',
            'CONTACT_NOT_FOUND'
        );
    }

    if ((string) ($existing['status'] ?? '') === 'inactive') {
        connectpro_response_conflict(
            'ผู้ติดต่อนี้ถูกปิดใช้งานอยู่แล้ว',
            'CONTACT_ALREADY_DISABLED'
        );
    }

    $updateData = ['status' => 'inactive'];

    if ($reason !== null) {
        $existingNotes = trim((string) ($existing['notes'] ?? ''));
        $disableNote = '[Disabled] ' . $reason;
        $updateData['notes'] = $existingNotes === ''
            ? $disableNote
            : $existingNotes . PHP_EOL . $disableNote;
    }

    if (method_exists($service, 'disable')) {
        $contact = $service->disable(
            $contactId,
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
            'ContactService::disable() or update() is required by disable.php.'
        );
    }

    if (!is_array($contact)) {
        throw new UnexpectedValueException(
            'ContactService returned an invalid disabled contact.'
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
        'disabled' => true,
        'reason' => $reason,
    ], 'ปิดใช้งานผู้ติดต่อสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
