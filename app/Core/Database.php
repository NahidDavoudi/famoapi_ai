<?php

namespace App\Core;

class Database
{
    private static ?\PDO $instance = null;

    public static function hasConnection(): bool
    {
        return self::$instance !== null;
    }

    public static function getConnection(): \PDO
    {
        if (self::$instance === null) {
            $host = trim((string) ($_ENV['DB_HOST'] ?? ''));
            $port = trim((string) ($_ENV['DB_PORT'] ?? '3306'));
            $name = trim((string) ($_ENV['DB_NAME'] ?? ''));
            $user = trim((string) ($_ENV['DB_USER'] ?? ''));
            $pass = (string) ($_ENV['DB_PASS'] ?? '');

            if ($host === '' || $name === '' || $user === '') {
                throw new ApiException(
                    'متغیرهای محیطی پایگاه داده به درستی تنظیم نشده‌اند',
                    500,
                    'ENV_CONFIGURATION_ERROR'
                );
            }

            $portInt = filter_var($port, FILTER_VALIDATE_INT);
            if ($portInt === false || $portInt < 1 || $portInt > 65535) {
                throw new ApiException(
                    'متغیر محیطی DB_PORT باید یک عدد صحیح معتبر باشد',
                    500,
                    'ENV_CONFIGURATION_ERROR'
                );
            }

            $dsn = "mysql:host={$host};port={$portInt};dbname={$name};charset=utf8mb4";

            self::$instance = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::$instance->query("SET NAMES utf8mb4 COLLATE utf8mb4_persian_ci");
        }

        return self::$instance;
    }

    public static function setConnection(\PDO $connection): void
    {
        self::$instance = $connection;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function ping(): bool
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{status: string, connected: bool, latency_ms: float}
     */
    public static function checkHealth(): array
    {
        $start = microtime(true);
        $connected = self::ping();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return [
            'status'     => $connected ? 'connected' : 'disconnected',
            'connected'  => $connected,
            'latency_ms' => $latencyMs,
        ];
    }
}