<?php

declare(strict_types=1);

/**
 * ConnectPro API Response Helper
 *
 * File: api/helpers/response.php
 *
 * Responsibilities:
 * - Send consistent JSON success and error responses
 * - Support validation errors and paginated results
 * - Attach request IDs and response metadata
 * - Apply secure API response headers
 * - Convert exceptions into safe production responses
 * - Avoid leaking stack traces, SQL, paths, or credentials
 */

if (!function_exists('connectpro_response_request_id')) {
    function connectpro_response_request_id(): string
    {
        $requestLog = $GLOBALS['connectpro_request_log'] ?? null;

        if (
            is_array($requestLog)
            && is_string($requestLog['request_id'] ?? null)
            && $requestLog['request_id'] !== ''
        ) {
            return $requestLog['request_id'];
        }

        $auth = $GLOBALS['connectpro_auth'] ?? null;

        if (
            is_array($auth)
            && is_string($auth['request_id'] ?? null)
            && $auth['request_id'] !== ''
        ) {
            return $auth['request_id'];
        }

        $provided = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;

        if (
            is_string($provided)
            && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $provided) === 1
        ) {
            return $provided;
        }

        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('connectpro_response_headers')) {
    function connectpro_response_headers(
        int $statusCode,
        ?string $requestId = null
    ): void {
        if (headers_sent()) {
            return;
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        if ($requestId !== null && $requestId !== '') {
            header('X-Request-Id: ' . $requestId);
        }
    }
}

if (!function_exists('connectpro_response_sanitize')) {
    /**
     * Recursively normalize response data and remove unsafe value types.
     */
    function connectpro_response_sanitize(
        mixed $value,
        int $depth = 0,
        int $maxDepth = 20
    ): mixed {
        if ($depth >= $maxDepth) {
            return '[MAX_DEPTH]';
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = connectpro_response_sanitize(
                    $item,
                    $depth + 1,
                    $maxDepth
                );
            }

            return $result;
        }

        if ($value instanceof JsonSerializable) {
            return connectpro_response_sanitize(
                $value->jsonSerialize(),
                $depth + 1,
                $maxDepth
            );
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_object($value)) {
            return connectpro_response_sanitize(
                get_object_vars($value),
                $depth + 1,
                $maxDepth
            );
        }

        if (is_resource($value)) {
            return null;
        }

        if (is_float($value) && (!is_finite($value) || is_nan($value))) {
            return null;
        }

        return $value;
    }
}

if (!function_exists('connectpro_response_encode')) {
    function connectpro_response_encode(array $payload): string
    {
        try {
            return json_encode(
                connectpro_response_sanitize($payload),
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return '{"success":false,"error":{"code":"RESPONSE_ENCODING_FAILED","message":"ไม่สามารถสร้างข้อมูลตอบกลับได้"}}';
        }
    }
}

if (!function_exists('connectpro_response_send')) {
    /**
     * Send JSON and terminate the current API request.
     *
     * @param array<string, mixed> $payload
     */
    function connectpro_response_send(
        array $payload,
        int $statusCode = 200
    ): never {
        if ($statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        $requestId = connectpro_response_request_id();
        $payload['meta'] = is_array($payload['meta'] ?? null)
            ? $payload['meta']
            : [];
        $payload['meta']['request_id'] = $requestId;
        $payload['meta']['timestamp'] = (new DateTimeImmutable())
            ->format(DateTimeInterface::ATOM);

        connectpro_response_headers($statusCode, $requestId);
        echo connectpro_response_encode($payload);
        exit;
    }
}

if (!function_exists('connectpro_response_success')) {
    /**
     * Send a successful JSON response.
     *
     * @param array<string, mixed> $meta
     */
    function connectpro_response_success(
        mixed $data = null,
        string $message = 'ดำเนินการสำเร็จ',
        int $statusCode = 200,
        array $meta = []
    ): never {
        connectpro_response_send(
            [
                'success' => true,
                'message' => $message,
                'data' => $data,
                'meta' => $meta,
            ],
            $statusCode
        );
    }
}

if (!function_exists('connectpro_response_created')) {
    function connectpro_response_created(
        mixed $data = null,
        string $message = 'สร้างข้อมูลสำเร็จ',
        array $meta = []
    ): never {
        connectpro_response_success($data, $message, 201, $meta);
    }
}

if (!function_exists('connectpro_response_no_content')) {
    function connectpro_response_no_content(): never
    {
        if (!headers_sent()) {
            http_response_code(204);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            header('X-Request-Id: ' . connectpro_response_request_id());
        }

        exit;
    }
}

if (!function_exists('connectpro_response_error')) {
    /**
     * Send an error response.
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $meta
     */
    function connectpro_response_error(
        string $code,
        string $message,
        int $statusCode = 400,
        array $details = [],
        array $meta = []
    ): never {
        $error = [
            'code' => strtoupper(trim($code)) ?: 'REQUEST_FAILED',
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        connectpro_response_send(
            [
                'success' => false,
                'error' => $error,
                'meta' => $meta,
            ],
            $statusCode
        );
    }
}

if (!function_exists('connectpro_response_validation_error')) {
    /**
     * @param array<string|int, mixed> $errors
     */
    function connectpro_response_validation_error(
        array $errors,
        string $message = 'ข้อมูลที่ส่งมาไม่ถูกต้อง'
    ): never {
        connectpro_response_error(
            'VALIDATION_FAILED',
            $message,
            422,
            ['fields' => $errors]
        );
    }
}

if (!function_exists('connectpro_response_unauthorized')) {
    function connectpro_response_unauthorized(
        string $message = 'กรุณาเข้าสู่ระบบก่อนใช้งาน',
        string $code = 'AUTHENTICATION_REQUIRED'
    ): never {
        connectpro_response_error($code, $message, 401);
    }
}

if (!function_exists('connectpro_response_forbidden')) {
    function connectpro_response_forbidden(
        string $message = 'บัญชีปัจจุบันไม่มีสิทธิ์ดำเนินการนี้',
        string $code = 'PERMISSION_DENIED'
    ): never {
        connectpro_response_error($code, $message, 403);
    }
}

if (!function_exists('connectpro_response_not_found')) {
    function connectpro_response_not_found(
        string $message = 'ไม่พบข้อมูลที่ต้องการ',
        string $code = 'RESOURCE_NOT_FOUND'
    ): never {
        connectpro_response_error($code, $message, 404);
    }
}

if (!function_exists('connectpro_response_conflict')) {
    /** @param array<string, mixed> $details */
    function connectpro_response_conflict(
        string $message = 'ข้อมูลขัดแย้งกับข้อมูลที่มีอยู่',
        string $code = 'RESOURCE_CONFLICT',
        array $details = []
    ): never {
        connectpro_response_error($code, $message, 409, $details);
    }
}

if (!function_exists('connectpro_response_method_not_allowed')) {
    /** @param list<string> $allowedMethods */
    function connectpro_response_method_not_allowed(
        array $allowedMethods
    ): never {
        $allowedMethods = array_values(array_unique(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            array_filter(
                $allowedMethods,
                static fn (mixed $method): bool => is_string($method)
                    && trim($method) !== ''
            )
        )));

        if (!headers_sent() && $allowedMethods !== []) {
            header('Allow: ' . implode(', ', $allowedMethods));
        }

        connectpro_response_error(
            'METHOD_NOT_ALLOWED',
            'HTTP Method นี้ไม่รองรับ',
            405,
            ['allowed_methods' => $allowedMethods]
        );
    }
}

if (!function_exists('connectpro_response_too_many_requests')) {
    function connectpro_response_too_many_requests(
        int $retryAfterSeconds = 60,
        string $message = 'มีคำขอมากเกินไป กรุณาลองใหม่ภายหลัง'
    ): never {
        $retryAfterSeconds = max(1, $retryAfterSeconds);

        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfterSeconds);
        }

        connectpro_response_error(
            'RATE_LIMIT_EXCEEDED',
            $message,
            429,
            ['retry_after_seconds' => $retryAfterSeconds]
        );
    }
}

if (!function_exists('connectpro_response_paginated')) {
    /**
     * Send a paginated response from a service result.
     *
     * Expected result:
     * [
     *   'items' => [...],
     *   'pagination' => [page, per_page, total, last_page, from, to]
     * ]
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $extraMeta
     */
    function connectpro_response_paginated(
        array $result,
        string $message = 'โหลดข้อมูลสำเร็จ',
        array $extraMeta = []
    ): never {
        $items = $result['items'] ?? [];
        $pagination = $result['pagination'] ?? [];

        if (!is_array($items) || !is_array($pagination)) {
            throw new InvalidArgumentException(
                'Invalid paginated service result.'
            );
        }

        $normalizedPagination = [
            'page' => max(1, (int) ($pagination['page'] ?? 1)),
            'per_page' => max(1, (int) ($pagination['per_page'] ?? 20)),
            'total' => max(0, (int) ($pagination['total'] ?? 0)),
            'last_page' => max(1, (int) ($pagination['last_page'] ?? 1)),
            'from' => max(0, (int) ($pagination['from'] ?? 0)),
            'to' => max(0, (int) ($pagination['to'] ?? 0)),
        ];
        $normalizedPagination['has_previous'] =
            $normalizedPagination['page'] > 1;
        $normalizedPagination['has_next'] =
            $normalizedPagination['page']
            < $normalizedPagination['last_page'];

        $meta = array_merge(
            $extraMeta,
            ['pagination' => $normalizedPagination]
        );

        connectpro_response_success($items, $message, 200, $meta);
    }
}

if (!function_exists('connectpro_response_parse_validation_exception')) {
    /** @return array<string|int, mixed>|null */
    function connectpro_response_parse_validation_exception(
        InvalidArgumentException $exception
    ): ?array {
        try {
            $decoded = json_decode(
                $exception->getMessage(),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $errors = $decoded['validation_errors'] ?? null;

        return is_array($errors) ? $errors : null;
    }
}

if (!function_exists('connectpro_response_exception')) {
    /**
     * Convert known service exceptions into safe API errors.
     *
     * @param array<string, mixed> $options
     */
    function connectpro_response_exception(
        Throwable $exception,
        array $options = []
    ): never {
        $debug = (bool) ($options['debug'] ?? false);

        if ($exception instanceof InvalidArgumentException) {
            $validationErrors =
                connectpro_response_parse_validation_exception($exception);

            if ($validationErrors !== null) {
                connectpro_response_validation_error($validationErrors);
            }

            connectpro_response_error(
                'INVALID_REQUEST',
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'ข้อมูลที่ส่งมาไม่ถูกต้อง',
                400
            );
        }

        if ($exception instanceof OutOfBoundsException) {
            connectpro_response_not_found();
        }

        if ($exception instanceof DomainException) {
            connectpro_response_conflict(
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'ไม่สามารถดำเนินการกับข้อมูลนี้ได้',
                'BUSINESS_RULE_VIOLATION'
            );
        }

        $details = [];

        if ($debug) {
            $details = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
            ];
        }

        error_log(sprintf(
            '[ConnectPro][%s] %s in %s:%d',
            connectpro_response_request_id(),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));

        connectpro_response_error(
            'INTERNAL_SERVER_ERROR',
            'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่ภายหลัง',
            500,
            $details
        );
    }
}

return [
    'send' => static fn (array $payload, int $status = 200): never =>
        connectpro_response_send($payload, $status),
    'success' => static fn (
        mixed $data = null,
        string $message = 'ดำเนินการสำเร็จ',
        int $status = 200,
        array $meta = []
    ): never => connectpro_response_success($data, $message, $status, $meta),
    'created' => static fn (
        mixed $data = null,
        string $message = 'สร้างข้อมูลสำเร็จ',
        array $meta = []
    ): never => connectpro_response_created($data, $message, $meta),
    'error' => static fn (
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
        array $meta = []
    ): never => connectpro_response_error(
        $code,
        $message,
        $status,
        $details,
        $meta
    ),
    'validation_error' => static fn (
        array $errors,
        string $message = 'ข้อมูลที่ส่งมาไม่ถูกต้อง'
    ): never => connectpro_response_validation_error($errors, $message),
    'paginated' => static fn (
        array $result,
        string $message = 'โหลดข้อมูลสำเร็จ',
        array $meta = []
    ): never => connectpro_response_paginated($result, $message, $meta),
    'exception' => static fn (
        Throwable $exception,
        array $options = []
    ): never => connectpro_response_exception($exception, $options),
];
