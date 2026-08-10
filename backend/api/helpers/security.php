<?php

declare(strict_types=1);

/**
 * ConnectPro Security Helper
 *
 * File: api/helpers/security.php
 *
 * Shared security utilities for API endpoints and middleware.
 * This file does not start sessions or send responses automatically.
 */

if (!function_exists('connectpro_security_headers')) {
    /** @param array<string, mixed> $options */
    function connectpro_security_headers(array $options = []): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (!empty($options['no_cache'])) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        if (
            !empty($options['hsts'])
            && connectpro_security_is_https()
        ) {
            $maxAge = max(300, (int) ($options['hsts_max_age'] ?? 31536000));
            $value = 'max-age=' . $maxAge;

            if (!empty($options['hsts_include_subdomains'])) {
                $value .= '; includeSubDomains';
            }

            header('Strict-Transport-Security: ' . $value);
        }
    }
}

if (!function_exists('connectpro_security_is_https')) {
    function connectpro_security_is_https(bool $trustProxy = false): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        if ($https === 'on' || $https === '1') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        if ($trustProxy) {
            $proto = strtolower(trim((string) (
                $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''
            )));

            return $proto === 'https';
        }

        return false;
    }
}

if (!function_exists('connectpro_security_random_token')) {
    function connectpro_security_random_token(int $bytes = 32): string
    {
        $bytes = max(16, min($bytes, 128));

        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('connectpro_security_hash_token')) {
    function connectpro_security_hash_token(string $token): string
    {
        return hash('sha256', $token);
    }
}

if (!function_exists('connectpro_security_equals')) {
    function connectpro_security_equals(
        string $knownValue,
        string $providedValue
    ): bool {
        return $knownValue !== ''
            && $providedValue !== ''
            && hash_equals($knownValue, $providedValue);
    }
}

if (!function_exists('connectpro_security_header')) {
    function connectpro_security_header(string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', trim($name)));
        $key = 'HTTP_' . $normalized;
        $value = $_SERVER[$key] ?? null;

        if ($normalized === 'CONTENT_TYPE' && $value === null) {
            $value = $_SERVER['CONTENT_TYPE'] ?? null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

if (!function_exists('connectpro_security_csrf_token')) {
    function connectpro_security_csrf_token(
        string $sessionKey = 'connectpro_csrf_token'
    ): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be active for CSRF protection.');
        }

        $token = $_SESSION[$sessionKey] ?? null;

        if (!is_string($token) || strlen($token) < 64) {
            $token = connectpro_security_random_token(32);
            $_SESSION[$sessionKey] = $token;
        }

        return $token;
    }
}

if (!function_exists('connectpro_security_validate_csrf')) {
    function connectpro_security_validate_csrf(
        ?string $providedToken = null,
        string $sessionKey = 'connectpro_csrf_token',
        string $headerName = 'X-CSRF-Token'
    ): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION[$sessionKey] ?? null;
        $providedToken ??= connectpro_security_header($headerName);

        if ($providedToken === null && isset($_POST[$sessionKey])) {
            $posted = $_POST[$sessionKey];
            $providedToken = is_string($posted) ? $posted : null;
        }

        return is_string($expected)
            && is_string($providedToken)
            && connectpro_security_equals($expected, $providedToken);
    }
}

if (!function_exists('connectpro_security_rotate_csrf')) {
    function connectpro_security_rotate_csrf(
        string $sessionKey = 'connectpro_csrf_token'
    ): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be active for CSRF protection.');
        }

        $token = connectpro_security_random_token(32);
        $_SESSION[$sessionKey] = $token;

        return $token;
    }
}

if (!function_exists('connectpro_security_client_ip')) {
    function connectpro_security_client_ip(bool $trustProxy = false): ?string
    {
        $candidates = [];

        if ($trustProxy) {
            $forwarded = connectpro_security_header('X-Forwarded-For');

            if ($forwarded !== null) {
                $candidates = array_merge(
                    $candidates,
                    array_map('trim', explode(',', $forwarded))
                );
            }

            $realIp = connectpro_security_header('X-Real-IP');

            if ($realIp !== null) {
                $candidates[] = $realIp;
            }
        }

        $candidates[] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('connectpro_security_origin_allowed')) {
    /** @param list<string> $allowedOrigins */
    function connectpro_security_origin_allowed(
        array $allowedOrigins,
        bool $allowMissingOrigin = true
    ): bool {
        $origin = connectpro_security_header('Origin');

        if ($origin === null) {
            return $allowMissingOrigin;
        }

        $normalizedAllowed = array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item)
                ? rtrim(strtolower(trim($item)), '/')
                : '',
            $allowedOrigins
        )));

        return in_array(
            rtrim(strtolower($origin), '/'),
            $normalizedAllowed,
            true
        );
    }
}

if (!function_exists('connectpro_security_safe_redirect')) {
    /** @param list<string> $allowedHosts */
    function connectpro_security_safe_redirect(
        string $target,
        string $fallback = '/',
        array $allowedHosts = []
    ): string {
        $target = trim(str_replace(["\r", "\n"], '', $target));

        if ($target === '' || str_starts_with($target, '//')) {
            return $fallback;
        }

        $parts = parse_url($target);

        if ($parts === false) {
            return $fallback;
        }

        if (!isset($parts['scheme'], $parts['host'])) {
            return str_starts_with($target, '/') ? $target : $fallback;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }

        $host = strtolower((string) $parts['host']);
        $allowedHosts = array_map(
            static fn (string $item): string => strtolower(trim($item)),
            $allowedHosts
        );

        return in_array($host, $allowedHosts, true) ? $target : $fallback;
    }
}

if (!function_exists('connectpro_security_escape_html')) {
    function connectpro_security_escape_html(mixed $value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

if (!function_exists('connectpro_security_safe_filename')) {
    function connectpro_security_safe_filename(
        string $filename,
        string $fallback = 'file'
    ): string {
        $filename = basename(str_replace("\0", '', $filename));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: '';
        $name = trim($name, '.-_');

        if ($name === '') {
            $name = $fallback;
        }

        $name = substr($name, 0, 120);
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1
            ? $extension
            : '';

        return $extension === '' ? $name : $name . '.' . $extension;
    }
}

if (!function_exists('connectpro_security_redact')) {
    function connectpro_security_redact(
        mixed $value,
        int $depth = 0,
        int $maxDepth = 8
    ): mixed {
        if ($depth >= $maxDepth) {
            return '[MAX_DEPTH]';
        }

        if (!is_array($value)) {
            return is_string($value) && mb_strlen($value) > 5000
                ? mb_substr($value, 0, 5000) . '[TRUNCATED]'
                : $value;
        }

        $sensitive = [
            'password', 'password_hash', 'current_password', 'new_password',
            'token', 'access_token', 'refresh_token', 'csrf_token',
            'authorization', 'cookie', 'secret', 'api_key', 'private_key',
            'connection_string',
        ];
        $result = [];

        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitive as $word) {
                if ($normalized === $word || str_contains($normalized, $word)) {
                    $isSensitive = true;
                    break;
                }
            }

            $result[$key] = $isSensitive
                ? '[REDACTED]'
                : connectpro_security_redact($item, $depth + 1, $maxDepth);
        }

        return $result;
    }
}

if (!function_exists('connectpro_security_rate_limit')) {
    /**
     * File-based fixed-window rate limiter for a single application node.
     * For multiple servers, replace this with a shared Redis-backed limiter.
     *
     * @return array{allowed: bool, limit: int, remaining: int, reset_at: int, retry_after: int}
     */
    function connectpro_security_rate_limit(
        string $key,
        int $limit = 60,
        int $windowSeconds = 60,
        ?string $storagePath = null
    ): array {
        $limit = max(1, min($limit, 100000));
        $windowSeconds = max(1, min($windowSeconds, 86400));
        $storagePath ??= sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'connectpro-rate-limit';

        if (
            !is_dir($storagePath)
            && !mkdir($storagePath, 0750, true)
            && !is_dir($storagePath)
        ) {
            throw new RuntimeException('Unable to create rate-limit storage.');
        }

        $file = rtrim($storagePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $handle = fopen($file, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to open rate-limit storage.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock rate-limit storage.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $record = [];

            if (is_string($raw) && $raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                    $record = is_array($decoded) ? $decoded : [];
                } catch (JsonException) {
                    $record = [];
                }
            }

            $now = time();
            $windowStart = (int) ($record['window_start'] ?? $now);
            $count = (int) ($record['count'] ?? 0);

            if (($now - $windowStart) >= $windowSeconds) {
                $windowStart = $now;
                $count = 0;
            }

            $allowed = $count < $limit;

            if ($allowed) {
                $count++;
            }

            $resetAt = $windowStart + $windowSeconds;
            $payload = json_encode([
                'window_start' => $windowStart,
                'count' => $count,
            ], JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $payload);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'reset_at' => $resetAt,
            'retry_after' => $allowed ? 0 : max(1, $resetAt - time()),
        ];
    }
}

if (!function_exists('connectpro_security_apply_rate_headers')) {
    /** @param array{limit: int, remaining: int, reset_at: int, retry_after: int} $result */
    function connectpro_security_apply_rate_headers(array $result): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-RateLimit-Limit: ' . (int) $result['limit']);
        header('X-RateLimit-Remaining: ' . (int) $result['remaining']);
        header('X-RateLimit-Reset: ' . (int) $result['reset_at']);

        if ((int) $result['retry_after'] > 0) {
            header('Retry-After: ' . (int) $result['retry_after']);
        }
    }
}

return [
    'headers' => static fn (array $options = []): void =>
        connectpro_security_headers($options),
    'random_token' => static fn (int $bytes = 32): string =>
        connectpro_security_random_token($bytes),
    'hash_token' => static fn (string $token): string =>
        connectpro_security_hash_token($token),
    'equals' => static fn (string $known, string $provided): bool =>
        connectpro_security_equals($known, $provided),
    'csrf_token' => static fn (string $key = 'connectpro_csrf_token'): string =>
        connectpro_security_csrf_token($key),
    'validate_csrf' => static fn (
        ?string $token = null,
        string $key = 'connectpro_csrf_token',
        string $header = 'X-CSRF-Token'
    ): bool => connectpro_security_validate_csrf($token, $key, $header),
    'client_ip' => static fn (bool $trustProxy = false): ?string =>
        connectpro_security_client_ip($trustProxy),
    'origin_allowed' => static fn (
        array $origins,
        bool $allowMissing = true
    ): bool => connectpro_security_origin_allowed($origins, $allowMissing),
    'safe_redirect' => static fn (
        string $target,
        string $fallback = '/',
        array $hosts = []
    ): string => connectpro_security_safe_redirect($target, $fallback, $hosts),
    'escape_html' => static fn (mixed $value): string =>
        connectpro_security_escape_html($value),
    'safe_filename' => static fn (
        string $filename,
        string $fallback = 'file'
    ): string => connectpro_security_safe_filename($filename, $fallback),
    'redact' => static fn (mixed $value): mixed =>
        connectpro_security_redact($value),
    'rate_limit' => static fn (
        string $key,
        int $limit = 60,
        int $window = 60,
        ?string $path = null
    ): array => connectpro_security_rate_limit($key, $limit, $window, $path),
];
