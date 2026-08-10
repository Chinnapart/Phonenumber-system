<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = '127.0.0.1';
        $port = '3306';
        $database = 'connectpro';
        $username = 'root';
        $password = '';
        $charset = 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            self::$connection = new PDO(
                $dsn,
                $username,
                $password,
                $options
            );

            self::$connection->exec(
                "SET time_zone = '+07:00'"
            );

            return self::$connection;
        } catch (PDOException $exception) {
            error_log(
                '[ConnectPro Database Error] '
                . $exception->getMessage()
            );

            throw new RuntimeException(
                'Unable to connect to the database.',
                0,
                $exception
            );
        }
    }

    public static function healthCheck(): array
    {
        try {
            $statement = self::connection()->query(
                'SELECT
                    DATABASE() AS database_name,
                    NOW() AS server_time'
            );

            $result = $statement->fetch();

            return [
                'connected' => true,
                'database' => $result['database_name'] ?? '',
                'server_time' => $result['server_time'] ?? null,
            ];
        } catch (Throwable $exception) {
            error_log(
                '[ConnectPro Health Check Error] '
                . $exception->getMessage()
            );

            return [
                'connected' => false,
                'database' => 'connectpro',
                'server_time' => null,
            ];
        }
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }
}