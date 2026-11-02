<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

date_default_timezone_set('UTC');

$driver = $_ENV['DB_DRIVER'] ?? 'pgsql';
$port = $_ENV['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306');
$dsn = $driver === 'pgsql'
    ? "pgsql:host={$_ENV['DB_HOST']};port={$port};dbname={$_ENV['DB_NAME']}"
    : "mysql:host={$_ENV['DB_HOST']};port={$port};dbname={$_ENV['DB_NAME']};charset=utf8mb4";

$db = new \PDO(
    $dsn,
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
if ($driver === 'pgsql') {
    $db->exec("SET NAMES 'UTF8'");
    $db->exec("SET TIME ZONE 'UTC'");
}

if (!class_exists('App\\Core\\Database')) {
    require_once __DIR__ . '/../app/Core/Database.php';
}
\App\Core\Database::setConnection($db);

$GLOBALS['TEST_APP'] = require __DIR__ . '/../bootstrap/app.php';