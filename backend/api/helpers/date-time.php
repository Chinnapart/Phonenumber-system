<?php

declare(strict_types=1);

/**
 * ConnectPro Date-Time Helper
 *
 * File: api/helpers/date-time.php
 *
 * All database timestamps should be stored in UTC. Convert to the configured
 * application or user timezone only when displaying or returning API data.
 */

if (!function_exists('connectpro_datetime_timezone')) {
    function connectpro_datetime_timezone(
        DateTimeZone|string|null $timezone = null,
        string $fallback = 'Asia/Bangkok'
    ): DateTimeZone {
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        $name = trim((string) ($timezone ?? ''));

        if ($name === '') {
            $name = $fallback;
        }

        if (!in_array($name, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Timezone ไม่ถูกต้อง: ' . $name);
        }

        return new DateTimeZone($name);
    }
}

if (!function_exists('connectpro_datetime_utc')) {
    function connectpro_datetime_utc(): DateTimeZone
    {
        static $utc = null;

        if (!$utc instanceof DateTimeZone) {
            $utc = new DateTimeZone('UTC');
        }

        return $utc;
    }
}

if (!function_exists('connectpro_datetime_now')) {
    function connectpro_datetime_now(
        DateTimeZone|string|null $timezone = null
    ): DateTimeImmutable {
        return new DateTimeImmutable(
            'now',
            connectpro_datetime_timezone($timezone)
        );
    }
}

if (!function_exists('connectpro_datetime_parse')) {
    function connectpro_datetime_parse(
        DateTimeInterface|string|int|null $value,
        DateTimeZone|string|null $sourceTimezone = null,
        bool $allowEmpty = false
    ): ?DateTimeImmutable {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($allowEmpty) {
                return null;
            }

            throw new InvalidArgumentException('กรุณาระบุวันและเวลา');
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1)) {
            $timestamp = (int) $value;

            try {
                return (new DateTimeImmutable('@' . $timestamp))
                    ->setTimezone(connectpro_datetime_utc());
            } catch (Exception $exception) {
                throw new InvalidArgumentException(
                    'Unix Timestamp ไม่ถูกต้อง',
                    0,
                    $exception
                );
            }
        }

        $timezone = connectpro_datetime_timezone($sourceTimezone);

        try {
            return new DateTimeImmutable(trim((string) $value), $timezone);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(
                'รูปแบบวันหรือเวลาไม่ถูกต้อง',
                0,
                $exception
            );
        }
    }
}

if (!function_exists('connectpro_datetime_parse_format')) {
    function connectpro_datetime_parse_format(
        string $value,
        string $format = 'Y-m-d H:i:s',
        DateTimeZone|string|null $timezone = null,
        bool $allowEmpty = false
    ): ?DateTimeImmutable {
        $value = trim($value);

        if ($value === '') {
            if ($allowEmpty) {
                return null;
            }

            throw new InvalidArgumentException('กรุณาระบุวันและเวลา');
        }

        $date = DateTimeImmutable::createFromFormat(
            '!' . $format,
            $value,
            connectpro_datetime_timezone($timezone)
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException(
                'รูปแบบวันและเวลาต้องเป็น ' . $format
            );
        }

        return $date;
    }
}

if (!function_exists('connectpro_datetime_to_timezone')) {
    function connectpro_datetime_to_timezone(
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $targetTimezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): DateTimeImmutable {
        $date = connectpro_datetime_parse($value, $sourceTimezone);

        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Unable to parse date-time value.');
        }

        return $date->setTimezone(
            connectpro_datetime_timezone($targetTimezone)
        );
    }
}

if (!function_exists('connectpro_datetime_to_utc')) {
    function connectpro_datetime_to_utc(
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $sourceTimezone = null
    ): DateTimeImmutable {
        $date = connectpro_datetime_parse($value, $sourceTimezone);

        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Unable to parse date-time value.');
        }

        return $date->setTimezone(connectpro_datetime_utc());
    }
}

if (!function_exists('connectpro_datetime_database')) {
    function connectpro_datetime_database(
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $sourceTimezone = null
    ): string {
        return connectpro_datetime_to_utc($value, $sourceTimezone)
            ->format('Y-m-d H:i:s');
    }
}

if (!function_exists('connectpro_datetime_format')) {
    function connectpro_datetime_format(
        DateTimeInterface|string|int|null $value,
        string $format = 'Y-m-d H:i:s',
        DateTimeZone|string|null $targetTimezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC',
        ?string $fallback = null
    ): ?string {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $fallback;
        }

        try {
            return connectpro_datetime_to_timezone(
                $value,
                $targetTimezone,
                $sourceTimezone
            )->format($format);
        } catch (InvalidArgumentException) {
            return $fallback;
        }
    }
}

if (!function_exists('connectpro_datetime_iso8601')) {
    function connectpro_datetime_iso8601(
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $targetTimezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): string {
        return connectpro_datetime_to_timezone(
            $value,
            $targetTimezone,
            $sourceTimezone
        )->format(DateTimeInterface::ATOM);
    }
}

if (!function_exists('connectpro_datetime_date_range')) {
    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    function connectpro_datetime_date_range(
        string $dateFrom,
        string $dateTo,
        DateTimeZone|string|null $timezone = null,
        int $maximumDays = 366
    ): array {
        $zone = connectpro_datetime_timezone($timezone);
        $start = connectpro_datetime_parse_format(
            $dateFrom,
            'Y-m-d',
            $zone
        );
        $end = connectpro_datetime_parse_format(
            $dateTo,
            'Y-m-d',
            $zone
        );

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            throw new RuntimeException('Unable to create date range.');
        }

        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(23, 59, 59, 999999);

        if ($start > $end) {
            throw new InvalidArgumentException(
                'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด'
            );
        }

        $maximumDays = max(1, min($maximumDays, 3660));
        $days = (int) $start->diff($end)->format('%a') + 1;

        if ($days > $maximumDays) {
            throw new InvalidArgumentException(sprintf(
                'ช่วงวันที่ต้องไม่เกิน %d วัน',
                $maximumDays
            ));
        }

        return ['start' => $start, 'end' => $end];
    }
}

if (!function_exists('connectpro_datetime_utc_range')) {
    /**
     * @return array{start: string, end: string}
     */
    function connectpro_datetime_utc_range(
        string $dateFrom,
        string $dateTo,
        DateTimeZone|string|null $timezone = null,
        int $maximumDays = 366
    ): array {
        $range = connectpro_datetime_date_range(
            $dateFrom,
            $dateTo,
            $timezone,
            $maximumDays
        );

        return [
            'start' => $range['start']
                ->setTimezone(connectpro_datetime_utc())
                ->format('Y-m-d H:i:s'),
            'end' => $range['end']
                ->setTimezone(connectpro_datetime_utc())
                ->format('Y-m-d H:i:s.u'),
        ];
    }
}

if (!function_exists('connectpro_datetime_relative')) {
    function connectpro_datetime_relative(
        DateTimeInterface|string|int $value,
        DateTimeInterface|string|int|null $reference = null,
        DateTimeZone|string|null $timezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): string {
        $zone = connectpro_datetime_timezone($timezone);
        $date = connectpro_datetime_to_timezone(
            $value,
            $zone,
            $sourceTimezone
        );
        $now = $reference === null
            ? new DateTimeImmutable('now', $zone)
            : connectpro_datetime_to_timezone(
                $reference,
                $zone,
                $sourceTimezone
            );
        $seconds = $date->getTimestamp() - $now->getTimestamp();
        $future = $seconds > 0;
        $absolute = abs($seconds);

        if ($absolute < 5) {
            return 'เมื่อสักครู่';
        }

        $units = [
            31536000 => 'ปี',
            2592000 => 'เดือน',
            604800 => 'สัปดาห์',
            86400 => 'วัน',
            3600 => 'ชั่วโมง',
            60 => 'นาที',
            1 => 'วินาที',
        ];

        foreach ($units as $unitSeconds => $label) {
            if ($absolute >= $unitSeconds) {
                $amount = (int) floor($absolute / $unitSeconds);

                return $future
                    ? 'อีก ' . $amount . ' ' . $label
                    : $amount . ' ' . $label . 'ที่แล้ว';
            }
        }

        return 'เมื่อสักครู่';
    }
}

if (!function_exists('connectpro_datetime_is_expired')) {
    function connectpro_datetime_is_expired(
        DateTimeInterface|string|int|null $value,
        DateTimeInterface|string|int|null $reference = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): bool {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        $date = connectpro_datetime_to_utc($value, $sourceTimezone);
        $now = $reference === null
            ? new DateTimeImmutable('now', connectpro_datetime_utc())
            : connectpro_datetime_to_utc($reference, $sourceTimezone);

        return $date <= $now;
    }
}

if (!function_exists('connectpro_datetime_clamp_future')) {
    function connectpro_datetime_clamp_future(
        DateTimeInterface|string|int $value,
        int $maximumFutureSeconds = 60,
        DateTimeZone|string|null $sourceTimezone = null
    ): DateTimeImmutable {
        $date = connectpro_datetime_to_utc($value, $sourceTimezone);
        $now = new DateTimeImmutable('now', connectpro_datetime_utc());
        $maximumFutureSeconds = max(0, min($maximumFutureSeconds, 86400));

        if ($date->getTimestamp() > $now->getTimestamp() + $maximumFutureSeconds) {
            throw new InvalidArgumentException(
                'วันและเวลาไม่สามารถอยู่ในอนาคตเกินกำหนดได้'
            );
        }

        return $date;
    }
}

if (!function_exists('connectpro_datetime_timezone_options')) {
    /**
     * @return list<array{value: string, label: string, offset_seconds: int}>
     */
    function connectpro_datetime_timezone_options(
        ?DateTimeInterface $reference = null
    ): array {
        $reference ??= new DateTimeImmutable('now', connectpro_datetime_utc());
        $options = [];

        foreach (timezone_identifiers_list() as $identifier) {
            $zone = new DateTimeZone($identifier);
            $offset = $zone->getOffset($reference);
            $sign = $offset >= 0 ? '+' : '-';
            $absolute = abs($offset);
            $hours = intdiv($absolute, 3600);
            $minutes = intdiv($absolute % 3600, 60);

            $options[] = [
                'value' => $identifier,
                'label' => sprintf(
                    '(UTC%s%02d:%02d) %s',
                    $sign,
                    $hours,
                    $minutes,
                    $identifier
                ),
                'offset_seconds' => $offset,
            ];
        }

        usort(
            $options,
            static fn (array $left, array $right): int =>
                [$left['offset_seconds'], $left['value']]
                <=> [$right['offset_seconds'], $right['value']]
        );

        return $options;
    }
}

return [
    'timezone' => static fn (
        DateTimeZone|string|null $timezone = null
    ): DateTimeZone => connectpro_datetime_timezone($timezone),
    'now' => static fn (
        DateTimeZone|string|null $timezone = null
    ): DateTimeImmutable => connectpro_datetime_now($timezone),
    'parse' => static fn (
        DateTimeInterface|string|int|null $value,
        DateTimeZone|string|null $timezone = null,
        bool $allowEmpty = false
    ): ?DateTimeImmutable => connectpro_datetime_parse(
        $value,
        $timezone,
        $allowEmpty
    ),
    'to_utc' => static fn (
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $sourceTimezone = null
    ): DateTimeImmutable => connectpro_datetime_to_utc(
        $value,
        $sourceTimezone
    ),
    'format' => static fn (
        DateTimeInterface|string|int|null $value,
        string $format = 'Y-m-d H:i:s',
        DateTimeZone|string|null $targetTimezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC',
        ?string $fallback = null
    ): ?string => connectpro_datetime_format(
        $value,
        $format,
        $targetTimezone,
        $sourceTimezone,
        $fallback
    ),
    'database' => static fn (
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $sourceTimezone = null
    ): string => connectpro_datetime_database($value, $sourceTimezone),
    'iso8601' => static fn (
        DateTimeInterface|string|int $value,
        DateTimeZone|string|null $targetTimezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): string => connectpro_datetime_iso8601(
        $value,
        $targetTimezone,
        $sourceTimezone
    ),
    'utc_range' => static fn (
        string $from,
        string $to,
        DateTimeZone|string|null $timezone = null,
        int $maximumDays = 366
    ): array => connectpro_datetime_utc_range(
        $from,
        $to,
        $timezone,
        $maximumDays
    ),
    'relative' => static fn (
        DateTimeInterface|string|int $value,
        DateTimeInterface|string|int|null $reference = null,
        DateTimeZone|string|null $timezone = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): string => connectpro_datetime_relative(
        $value,
        $reference,
        $timezone,
        $sourceTimezone
    ),
    'is_expired' => static fn (
        DateTimeInterface|string|int|null $value,
        DateTimeInterface|string|int|null $reference = null,
        DateTimeZone|string|null $sourceTimezone = 'UTC'
    ): bool => connectpro_datetime_is_expired(
        $value,
        $reference,
        $sourceTimezone
    ),
    'timezone_options' => static fn (
        ?DateTimeInterface $reference = null
    ): array => connectpro_datetime_timezone_options($reference),
];
