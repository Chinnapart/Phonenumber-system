<?php

declare(strict_types=1);

/**
 * ConnectPro Secure Session Configuration
 *
 * File: api/config/session.php
 *
 * Responsibilities:
 * - Load session settings from app.php
 * - Apply secure PHP session options
 * - Start the session safely
 * - Enforce idle and absolute expiration
 * - Rotate the session ID periodically
 * - Initialize a CSRF token
 *
 * This file returns session state and helper closures.
 */

$appConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'app.php';

if (!is_file($appConfigPath)) {
    throw new RuntimeException('Application configuration file not found.');
}

/** @var array<string, mixed> $appConfig */
$appConfig = require $appConfigPath;

$sessionConfig = $appConfig['session'] ?? [];
$securityConfig = $appConfig['security'] ?? [];

if (!is_array($sessionConfig) || !is_array($securityConfig)) {
    throw new RuntimeException('Invalid application session configuration.');
}

if (!function_exists('connectpro_destroy_session')) {
    /**
     * Destroy the active session and remove its cookie.
     */
    function connectpro_destroy_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if ((bool) ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $cookie['path'] ?: '/',
                    'domain' => $cookie['domain'] ?? '',
                    'secure' => (bool) ($cookie['secure'] ?? false),
                    'httponly' => (bool) ($cookie['httponly'] ?? true),
                    'samesite' => $cookie['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }
}

if (!function_exists('connectpro_session_expired_response')) {
    /**
     * Send a consistent JSON response when the session has expired.
     */
    function connectpro_session_expired_response(string $reason): never
    {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }

        echo json_encode(
            [
                'success' => false,
                'error' => [
                    'code' => 'SESSION_EXPIRED',
                    'message' => 'Session หมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง',
                    'reason' => $reason,
                ],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

if (!function_exists('connectpro_is_https')) {
    /**
     * Determine whether the current request uses HTTPS.
     */
    function connectpro_is_https(bool $trustProxyHeaders = false): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        if ($trustProxyHeaders) {
            $forwardedProto = strtolower((string) (
                $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''
            ));

            return $forwardedProto === 'https';
        }

        return false;
    }
}

$sessionName = (string) ($sessionConfig['name'] ?? 'connectpro_session');
$lifetimeMinutes = max(1, (int) ($sessionConfig['lifetime_minutes'] ?? 30));
$idleMinutes = max(1, (int) (
    $sessionConfig['idle_timeout_minutes'] ?? 20
));
$regenerateMinutes = max(1, (int) (
    $sessionConfig['regenerate_interval_minutes'] ?? 10
));
$cookiePath = (string) ($sessionConfig['cookie_path'] ?? '/connectpro');
$cookieDomain = (string) ($sessionConfig['cookie_domain'] ?? '');
$cookieSameSite = ucfirst(strtolower((string) (
    $sessionConfig['cookie_same_site'] ?? 'Lax'
)));
$trustProxyHeaders = (bool) (
    $securityConfig['trust_proxy_headers'] ?? false
);
$isHttps = connectpro_is_https($trustProxyHeaders);
$cookieSecure = (bool) ($sessionConfig['cookie_secure'] ?? $isHttps);

if (!in_array($cookieSameSite, ['Lax', 'Strict', 'None'], true)) {
    $cookieSameSite = 'Lax';
}

if ($cookieSameSite === 'None') {
    $cookieSecure = true;
}

if (session_status() === PHP_SESSION_NONE) {
    if (headers_sent($sentFile, $sentLine)) {
        throw new RuntimeException(sprintf(
            'Cannot start session because headers were sent in %s:%d.',
            $sentFile,
            $sentLine
        ));
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
    ini_set('session.cookie_samesite', $cookieSameSite);
    ini_set('session.gc_maxlifetime', (string) ($lifetimeMinutes * 60));
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'domain' => $cookieDomain,
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => $cookieSameSite,
    ]);

    if (!session_start()) {
        throw new RuntimeException('Unable to start the application session.');
    }
}

$now = time();
$absoluteLifetimeSeconds = $lifetimeMinutes * 60;
$idleTimeoutSeconds = $idleMinutes * 60;
$regenerateIntervalSeconds = $regenerateMinutes * 60;

$createdAt = (int) ($_SESSION['_session_created_at'] ?? $now);
$lastActivityAt = (int) ($_SESSION['_session_last_activity_at'] ?? $now);
$lastRegeneratedAt = (int) (
    $_SESSION['_session_regenerated_at'] ?? $now
);

$isIdleExpired = ($now - $lastActivityAt) > $idleTimeoutSeconds;
$isAbsoluteExpired = ($now - $createdAt) > $absoluteLifetimeSeconds;

if ($isIdleExpired || $isAbsoluteExpired) {
    $reason = $isIdleExpired ? 'idle_timeout' : 'absolute_timeout';

    connectpro_destroy_session();
    connectpro_session_expired_response($reason);
}

$_SESSION['_session_created_at'] = $createdAt;
$_SESSION['_session_last_activity_at'] = $now;
$_SESSION['_session_regenerated_at'] = $lastRegeneratedAt;

if (($now - $lastRegeneratedAt) >= $regenerateIntervalSeconds) {
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to rotate the session identifier.');
    }

    $_SESSION['_session_regenerated_at'] = $now;
}

$csrfEnabled = (bool) ($securityConfig['csrf_enabled'] ?? true);
$csrfTokenName = (string) (
    $securityConfig['csrf_token_name'] ?? 'connectpro_csrf_token'
);

if ($csrfEnabled) {
    $currentToken = $_SESSION[$csrfTokenName] ?? null;

    if (!is_string($currentToken) || strlen($currentToken) < 64) {
        $_SESSION[$csrfTokenName] = bin2hex(random_bytes(32));
    }
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

return [
    'name' => session_name(),
    'id' => session_id(),
    'started' => session_status() === PHP_SESSION_ACTIVE,
    'created_at' => (int) $_SESSION['_session_created_at'],
    'last_activity_at' => (int) $_SESSION['_session_last_activity_at'],
    'expires_at' => min(
        (int) $_SESSION['_session_created_at'] + $absoluteLifetimeSeconds,
        (int) $_SESSION['_session_last_activity_at'] + $idleTimeoutSeconds
    ),
    'idle_timeout_seconds' => $idleTimeoutSeconds,
    'absolute_lifetime_seconds' => $absoluteLifetimeSeconds,
    'csrf_enabled' => $csrfEnabled,
    'csrf_token_name' => $csrfTokenName,
    'csrf_token' => $csrfEnabled
        ? (string) $_SESSION[$csrfTokenName]
        : null,
    'destroy' => static function (): void {
        connectpro_destroy_session();
    },
    'regenerate' => static function (): bool {
        $regenerated = session_regenerate_id(true);

        if ($regenerated) {
            $_SESSION['_session_regenerated_at'] = time();
        }

        return $regenerated;
    },
];
