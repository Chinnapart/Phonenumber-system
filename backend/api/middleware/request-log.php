<?php

declare(strict_types=1);

/**
 * ConnectPro Request Logging Middleware
 *
 * File: api/middleware/request-log.php
 *
 * Responsibilities:
 * - Generate or validate a request ID
 * - Capture request method, route, client IP, user, timing, and memory usage
 * - Redact credentials, tokens, cookies, and sensitive request fields
 * - Record the final HTTP status through a shutdown handler
 * - Support database logging with file fallback
 * - Support route exclusions, sampling, and slow-request detection
 *
 * Usage:
 *
 * $requestLog = require __DIR__ . '/../middleware/request-log.php';
 * $request = $requestLog([
 *     'pdo' => $pdo,
 *     'database_enabled' => true,
 *     'file_enabled' => true,
 *     'log_path' => __DIR__ . '/../storage/logs',
 * ]);
 *
 * The returned context is available immediately. The final log record is
 * written automatically when the request finishes.
 */

if (!function_exists('connectpro_request_log_header')) {
    function connectpro_request_log_header(string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', trim($name)));
        $serverKey = 'HTTP_' . $normalized;
        $value = $_SERVER[$serverKey] ?? null;

        if ($value === null && $normalized === 'CONTENT_TYPE') {
            $value = $_SERVER['CONTENT_TYPE'] ?? null;
        }

        if ($value === null && $normalized === 'CONTENT_LENGTH') {
            $value = $_SERVER['CONTENT_LENGTH'] ?? null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

if (!function_exists('connectpro_request_log_request_id')) {
    function connectpro_request_log_request_id(): string
    {
        $provided = connectpro_request_log_header('X-Request-Id');

        if (
            is_string($provided)
            && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $provided) === 1
        ) {
            return $provided;
        }

        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('connectpro_request_log_client_ip')) {
    /**
     * Resolve the direct client IP. Proxy headers are trusted only when the
     * option is explicitly enabled by deployment configuration.
     */
    function connectpro_request_log_client_ip(bool $trustProxy = false): ?string
    {
        $candidates = [];

        if ($trustProxy) {
            $forwarded = connectpro_request_log_header('X-Forwarded-For');

            if ($forwarded !== null) {
                $candidates = array_merge(
                    $candidates,
                    array_map('trim', explode(',', $forwarded))
                );
            }

            $realIp = connectpro_request_log_header('X-Real-IP');

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

if (!function_exists('connectpro_request_log_route')) {
    function connectpro_request_log_route(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '/';
        }

        $decoded = rawurldecode($path);

        return mb_substr($decoded, 0, 1000);
    }
}

if (!function_exists('connectpro_request_log_sensitive_key')) {
    function connectpro_request_log_sensitive_key(string $key): bool
    {
        $key = strtolower(trim($key));
        $sensitive = [
            'password',
            'password_hash',
            'current_password',
            'new_password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'csrf_token',
            'remember_token',
            'authorization',
            'cookie',
            'set-cookie',
            'secret',
            'client_secret',
            'api_key',
            'private_key',
            'connection_string',
        ];

        if (in_array($key, $sensitive, true)) {
            return true;
        }

        foreach ($sensitive as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('connectpro_request_log_sanitize')) {
    /**
     * Recursively redact sensitive values and limit log payload size.
     */
    function connectpro_request_log_sanitize(
        mixed $value,
        int $depth = 0,
        int $maxDepth = 5,
        int $maxStringLength = 2000,
        int $maxItems = 100
    ): mixed {
        if ($depth >= $maxDepth) {
            return '[MAX_DEPTH]';
        }

        if (is_array($value)) {
            $sanitized = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if ($count >= $maxItems) {
                    $sanitized['_truncated'] = true;
                    break;
                }

                if (connectpro_request_log_sensitive_key((string) $key)) {
                    $sanitized[$key] = '[REDACTED]';
                } else {
                    $sanitized[$key] = connectpro_request_log_sanitize(
                        $item,
                        $depth + 1,
                        $maxDepth,
                        $maxStringLength,
                        $maxItems
                    );
                }

                $count++;
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return '[OBJECT:' . get_debug_type($value) . ']';
        }

        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        if (is_string($value)) {
            $value = str_replace("\0", '', $value);

            if (mb_strlen($value) > $maxStringLength) {
                return mb_substr($value, 0, $maxStringLength)
                    . '[TRUNCATED]';
            }
        }

        return $value;
    }
}

if (!function_exists('connectpro_request_log_headers')) {
    /** @return array<string, mixed> */
    function connectpro_request_log_headers(): array
    {
        $allowed = [
            'Accept',
            'Accept-Language',
            'Content-Type',
            'Content-Length',
            'Origin',
            'Referer',
            'User-Agent',
            'X-Requested-With',
            'X-Request-Id',
        ];
        $headers = [];

        foreach ($allowed as $name) {
            $value = connectpro_request_log_header($name);

            if ($value !== null) {
                $headers[$name] = mb_substr($value, 0, 1000);
            }
        }

        return $headers;
    }
}

if (!function_exists('connectpro_request_log_body')) {
    /**
     * Return a safe request body preview without consuming php://input.
     *
     * @param array<string, mixed> $options
     */
    function connectpro_request_log_body(array $options): mixed
    {
        if (empty($options['log_request_body'])) {
            return null;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $contentType = strtolower((string) (
            connectpro_request_log_header('Content-Type') ?? ''
        ));

        if (str_contains($contentType, 'multipart/form-data')) {
            return [
                'fields' => connectpro_request_log_sanitize($_POST),
                'files' => array_map(
                    static fn (mixed $file): mixed => is_array($file)
                        ? [
                            'name' => basename((string) ($file['name'] ?? '')),
                            'type' => (string) ($file['type'] ?? ''),
                            'size' => (int) ($file['size'] ?? 0),
                            'error' => (int) ($file['error'] ?? 0),
                        ]
                        : null,
                    $_FILES
                ),
            ];
        }

        if ($_POST !== []) {
            return connectpro_request_log_sanitize($_POST);
        }

        $contentLength = (int) (
            connectpro_request_log_header('Content-Length') ?? 0
        );
        $maximumBytes = max(
            0,
            (int) ($options['max_body_log_bytes'] ?? 16384)
        );

        if ($maximumBytes === 0 || $contentLength > $maximumBytes) {
            return $contentLength > 0
                ? ['_omitted' => true, '_content_length' => $contentLength]
                : null;
        }

        $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (strlen($raw) > $maximumBytes) {
            return [
                '_omitted' => true,
                '_reason' => 'body_log_limit',
                '_content_length' => $contentLength,
            ];
        }

        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode(
                    $raw,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );

                return connectpro_request_log_sanitize($decoded);
            } catch (JsonException) {
                return ['_invalid_json' => true];
            }
        }

        return connectpro_request_log_sanitize($raw);
    }
}

if (!function_exists('connectpro_request_log_user_context')) {
    /** @return array{user_id: int|null, username: string|null} */
    function connectpro_request_log_user_context(): array
    {
        $context = $GLOBALS['connectpro_auth'] ?? null;
        $user = is_array($context) && is_array($context['user'] ?? null)
            ? $context['user']
            : ($_SESSION['auth_user'] ?? $_SESSION['user'] ?? null);

        if (!is_array($user)) {
            return ['user_id' => null, 'username' => null];
        }

        $userId = (int) ($user['id'] ?? $user['user_id'] ?? 0);

        return [
            'user_id' => $userId > 0 ? $userId : null,
            'username' => isset($user['username'])
                ? mb_substr((string) $user['username'], 0, 100)
                : null,
        ];
    }
}

if (!function_exists('connectpro_request_log_should_skip')) {
    /** @param array<string, mixed> $options */
    function connectpro_request_log_should_skip(
        string $route,
        array $options
    ): bool {
        $excludedRoutes = $options['excluded_routes'] ?? [
            '/favicon.ico',
            '/health',
            '/healthz',
        ];

        if (is_array($excludedRoutes)) {
            foreach ($excludedRoutes as $excluded) {
                if (!is_string($excluded) || $excluded === '') {
                    continue;
                }

                if ($route === $excluded || str_starts_with($route, $excluded)) {
                    return true;
                }
            }
        }

        $sampleRate = (float) ($options['sample_rate'] ?? 1.0);
        $sampleRate = min(max($sampleRate, 0.0), 1.0);

        if ($sampleRate <= 0.0) {
            return true;
        }

        if ($sampleRate < 1.0) {
            $draw = random_int(0, 1_000_000) / 1_000_000;

            if ($draw > $sampleRate) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('connectpro_request_log_level')) {
    function connectpro_request_log_level(
        int $statusCode,
        float $durationMs,
        float $slowThresholdMs
    ): string {
        if ($statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400 || $durationMs >= $slowThresholdMs) {
            return 'warning';
        }

        return 'info';
    }
}

if (!function_exists('connectpro_request_log_encode')) {
    function connectpro_request_log_encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return json_encode(['_encoding_error' => true]);
        }
    }
}

if (!function_exists('connectpro_request_log_database')) {
    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $options
     */
    function connectpro_request_log_database(
        array $record,
        array $options
    ): bool {
        if (empty($options['database_enabled'])) {
            return false;
        }

        $pdo = $options['pdo'] ?? null;

        if (!$pdo instanceof PDO) {
            return false;
        }

        $sql = <<<SQL
            INSERT INTO request_logs (
                request_id, user_id, username, method, route,
                query_params, request_headers, request_body,
                ip_address, user_agent, status_code, duration_ms,
                memory_peak_bytes, log_level, error_type, error_message,
                created_at
            ) VALUES (
                :request_id, :user_id, :username, :method, :route,
                :query_params, :request_headers, :request_body,
                :ip_address, :user_agent, :status_code, :duration_ms,
                :memory_peak_bytes, :log_level, :error_type, :error_message,
                :created_at
            )
            SQL;

        try {
            $statement = $pdo->prepare($sql);
            $statement->bindValue(':request_id', $record['request_id']);
            $statement->bindValue(
                ':user_id',
                $record['user_id'],
                $record['user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
            );
            $statement->bindValue(
                ':username',
                $record['username'],
                $record['username'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $statement->bindValue(':method', $record['method']);
            $statement->bindValue(':route', $record['route']);
            $statement->bindValue(
                ':query_params',
                connectpro_request_log_encode($record['query_params'])
            );
            $statement->bindValue(
                ':request_headers',
                connectpro_request_log_encode($record['request_headers'])
            );
            $statement->bindValue(
                ':request_body',
                connectpro_request_log_encode($record['request_body']),
                $record['request_body'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_STR
            );
            $statement->bindValue(
                ':ip_address',
                $record['ip_address'],
                $record['ip_address'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_STR
            );
            $statement->bindValue(':user_agent', $record['user_agent']);
            $statement->bindValue(
                ':status_code',
                $record['status_code'],
                PDO::PARAM_INT
            );
            $statement->bindValue(':duration_ms', (string) $record['duration_ms']);
            $statement->bindValue(
                ':memory_peak_bytes',
                $record['memory_peak_bytes'],
                PDO::PARAM_INT
            );
            $statement->bindValue(':log_level', $record['log_level']);
            $statement->bindValue(
                ':error_type',
                $record['error_type'],
                $record['error_type'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_STR
            );
            $statement->bindValue(
                ':error_message',
                $record['error_message'],
                $record['error_message'] === null
                    ? PDO::PARAM_NULL
                    : PDO::PARAM_STR
            );
            $statement->bindValue(':created_at', $record['created_at']);
            $statement->execute();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('connectpro_request_log_file')) {
    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $options
     */
    function connectpro_request_log_file(
        array $record,
        array $options
    ): bool {
        if (empty($options['file_enabled'])) {
            return false;
        }

        $directory = rtrim(
            (string) ($options['log_path']
                ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'
                    . DIRECTORY_SEPARATOR . 'logs'),
            DIRECTORY_SEPARATOR
        );

        if (
            !is_dir($directory)
            && !mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            return false;
        }

        $filename = $directory
            . DIRECTORY_SEPARATOR
            . 'requests-'
            . date('Y-m-d')
            . '.log';
        $line = connectpro_request_log_encode($record);

        if ($line === null) {
            return false;
        }

        return file_put_contents(
            $filename,
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) !== false;
    }
}

if (!function_exists('connectpro_request_log_write')) {
    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $options
     */
    function connectpro_request_log_write(
        array $record,
        array $options
    ): void {
        $databaseWritten = connectpro_request_log_database($record, $options);
        $fileAlways = (bool) ($options['file_always'] ?? false);

        if (!$databaseWritten || $fileAlways) {
            connectpro_request_log_file($record, $options);
        }
    }
}

/**
 * Request logging middleware callable.
 *
 * Options:
 * - pdo: PDO
 * - database_enabled: bool
 * - file_enabled: bool
 * - file_always: bool
 * - log_path: string
 * - trust_proxy_headers: bool
 * - log_request_body: bool
 * - max_body_log_bytes: int
 * - sample_rate: float, 0.0 to 1.0
 * - excluded_routes: list<string>
 * - slow_request_ms: float
 *
 * @return Closure(array<string, mixed>): array<string, mixed>
 */
return static function (array $options = []): array {
    $defaults = [
        'database_enabled' => isset($options['pdo'])
            && $options['pdo'] instanceof PDO,
        'file_enabled' => true,
        'file_always' => false,
        'trust_proxy_headers' => false,
        'log_request_body' => false,
        'max_body_log_bytes' => 16384,
        'sample_rate' => 1.0,
        'slow_request_ms' => 1000.0,
    ];
    $options = array_merge($defaults, $options);

    if (isset($options['pdo']) && !$options['pdo'] instanceof PDO) {
        throw new InvalidArgumentException('pdo must be an instance of PDO.');
    }

    $requestId = connectpro_request_log_request_id();
    $route = connectpro_request_log_route();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $startedAt = microtime(true);
    $startedAtIso = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    $skip = connectpro_request_log_should_skip($route, $options);

    if (!headers_sent()) {
        header('X-Request-Id: ' . $requestId);
    }

    $context = [
        'request_id' => $requestId,
        'method' => $method,
        'route' => $route,
        'started_at' => $startedAtIso,
        'started_at_float' => $startedAt,
        'skipped' => $skip,
    ];

    $GLOBALS['connectpro_request_log'] = $context;

    if ($skip) {
        return $context;
    }

    $queryParams = connectpro_request_log_sanitize($_GET);
    $requestHeaders = connectpro_request_log_headers();
    $requestBody = connectpro_request_log_body($options);
    $ipAddress = connectpro_request_log_client_ip(
        (bool) $options['trust_proxy_headers']
    );
    $userAgent = mb_substr(
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        0,
        500
    );

    register_shutdown_function(static function () use (
        $options,
        $requestId,
        $method,
        $route,
        $startedAt,
        $startedAtIso,
        $queryParams,
        $requestHeaders,
        $requestBody,
        $ipAddress,
        $userAgent
    ): void {
        $durationMs = round((microtime(true) - $startedAt) * 1000, 3);
        $statusCode = http_response_code();

        if (!is_int($statusCode) || $statusCode < 100) {
            $statusCode = 200;
        }

        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        $errorType = null;
        $errorMessage = null;

        if (
            is_array($lastError)
            && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)
        ) {
            $statusCode = max($statusCode, 500);
            $errorType = 'fatal_error';
            $errorMessage = mb_substr(
                (string) ($lastError['message'] ?? 'Fatal error'),
                0,
                1000
            );
        }

        $userContext = connectpro_request_log_user_context();
        $slowThreshold = max(
            1.0,
            (float) ($options['slow_request_ms'] ?? 1000.0)
        );
        $record = [
            'request_id' => $requestId,
            'user_id' => $userContext['user_id'],
            'username' => $userContext['username'],
            'method' => $method,
            'route' => $route,
            'query_params' => $queryParams,
            'request_headers' => $requestHeaders,
            'request_body' => $requestBody,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'log_level' => connectpro_request_log_level(
                $statusCode,
                $durationMs,
                $slowThreshold
            ),
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'created_at' => $startedAtIso,
        ];

        connectpro_request_log_write($record, $options);
    });

    return $context;
};
