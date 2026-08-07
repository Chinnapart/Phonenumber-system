<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/database.php';

try {
    $result = Database::healthCheck();

    if ($result['connected'] === true) {
        echo '<h1 style="color: green;">Database Connected Successfully</h1>';

        echo '<p><strong>Database:</strong> '
            . htmlspecialchars($result['database'])
            . '</p>';

        echo '<p><strong>MySQL Server Time:</strong> '
            . htmlspecialchars((string) $result['server_time'])
            . '</p>';

        echo '<p><strong>PHP Version:</strong> '
            . htmlspecialchars(PHP_VERSION)
            . '</p>';

        echo '<p><strong>PDO MySQL:</strong> Enabled</p>';
    } else {
        http_response_code(500);

        echo '<h1 style="color: red;">Database Connection Failed</h1>';
        echo '<p>ตรวจสอบ Apache, MySQL และค่าการเชื่อมต่อฐานข้อมูล</p>';
    }
} catch (Throwable $exception) {
    http_response_code(500);

    echo '<h1 style="color: red;">Database Connection Error</h1>';
    echo '<p>ไม่สามารถเชื่อมต่อฐานข้อมูลได้</p>';

    /*
     * ใช้สำหรับ Development เท่านั้น
     * Production ห้ามแสดงรายละเอียด Error ต่อผู้ใช้
     */
    echo '<pre>';
    echo htmlspecialchars($exception->getMessage());
    echo '</pre>';
}