<?php

declare(strict_types=1);

/**
 * ConnectPro Logout Endpoint
 * File: api/auth/logout.php
 * Method: POST
 *
 * Requires an authenticated session and a valid CSRF token.
 */

$apiRoot = dirname(__DIR__);

require_once $apiRoot . '/helpers/response.php';
require_once $apiRoot . '/helpers/security.php';

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

    $auth = require $apiRoot . '/middleware/auth.php';
    $authContext = $auth([
        'pdo' => $pdo,
        'refresh_user' => true,
        'session_registry_enabled' => (bool) (
            $sessionConfig['registry_enabled'] ?? false
        ),
        'touch_session_registry' => false,
        'csrf' => true,
        'allow_password_change_only' => true,
    ]);

    $userId = (int) $authContext['user_id'];
    $username = (string) (
        $authContext['user']['username'] ?? 'unknown'
    );
    $rawSessionId = session_id();
    $sessionIdHash = $rawSessionId === ''
        ? null
        : hash('sha256', $rawSessionId);

    $pdo->beginTransaction();

    try {
        if (
            (bool) ($sessionConfig['registry_enabled'] ?? false)
            && $sessionIdHash !== null
        ) {
            $statement = $pdo->prepare(
                'DELETE FROM user_sessions '
                . 'WHERE id = :session_id AND user_id = :user_id'
            );
            $statement->bindValue(
                ':session_id',
                $sessionIdHash,
                PDO::PARAM_STR
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->execute();
        }

        if ((bool) ($loggingConfig['activity_enabled'] ?? true)) {
            $statement = $pdo->prepare(
                'INSERT INTO activity_logs ('
                . 'user_id, action, entity_type, entity_id, description, '
                . 'ip_address, user_agent, created_at'
                . ') VALUES ('
                . ":user_id, 'logout', 'session', :entity_id, :description, "
                . ':ip_address, :user_agent, CURRENT_TIMESTAMP'
                . ')'
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->bindValue(':entity_id', $userId, PDO::PARAM_INT);
            $statement->bindValue(
                ':description',
                'ออกจากระบบ: ' . $username,
                PDO::PARAM_STR
            );
            $statement->bindValue(
                ':ip_address',
                connectpro_security_client_ip(
                    (bool) ($securityConfig['trust_proxy_headers'] ?? false)
                ),
                PDO::PARAM_STR
            );
            $statement->bindValue(
                ':user_agent',
                mb_substr(
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    0,
                    500
                ),
                PDO::PARAM_STR
            );
            $statement->execute();
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    // Remove all server-side session data and expire the session cookie.
    connectpro_destroy_session();

    // Clear optional remember-me cookie if deployment uses one.
    $rememberCookie = (string) (
        $sessionConfig['remember_cookie_name'] ?? 'connectpro_remember'
    );

    if ($rememberCookie !== '' && isset($_COOKIE[$rememberCookie])) {
        $cookiePath = (string) ($sessionConfig['cookie_path'] ?? '/');
        $cookieDomain = (string) ($sessionConfig['cookie_domain'] ?? '');
        $cookieSecure = (bool) (
            $sessionConfig['cookie_secure']
            ?? connectpro_security_is_https(
                (bool) ($securityConfig['trust_proxy_headers'] ?? false)
            )
        );

        setcookie($rememberCookie, '', [
            'expires' => time() - 3600,
            'path' => $cookiePath,
            'domain' => $cookieDomain,
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$rememberCookie]);
    }

    connectpro_response_success([
        'logged_out' => true,
        'redirect_to' => (string) (
            $appConfig['redirects']['after_logout']
            ?? '/connectpro/login.html'
        ),
    ], 'ออกจากระบบสำเร็จ');
} catch (Throwable $exception) {
    $debug = isset($appConfig)
        && is_array($appConfig)
        && (bool) ($appConfig['debug'] ?? false);

    connectpro_response_exception($exception, ['debug' => $debug]);
}
