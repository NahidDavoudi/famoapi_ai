#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$rootDir = dirname(__DIR__);
$autoload = $rootDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run `composer install` first.\n");
    exit(1);
}

require_once $autoload;

$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

foreach ((array) getenv() as $envKey => $envVal) {
    if (!isset($_ENV[$envKey]) && is_string($envVal)) {
        $_ENV[$envKey] = $envVal;
    }
}

date_default_timezone_set('UTC');

use App\Core\Database;
use App\Core\Migrator;

$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
$statusOnly = in_array('--status', $args, true);

if ($showHelp) {
    $usage = <<<'TEXT'
FAMO Database Migration Runner (scripts/migrate.php)

Usage:
  php scripts/migrate.php           Run all pending SQL migrations in database/migrations/
  php scripts/migrate.php --status  List applied and pending migrations without modifying the DB
  php scripts/migrate.php --help    Show this help message

TEXT;
    fwrite(STDOUT, $usage);
    exit(0);
}

try {
    if ($statusOnly) {
        $pdo = Database::getConnection();
        $executed = [];
        try {
            $executed = $pdo->query('SELECT migration FROM migrations')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            // migrations table may not exist yet
        }

        $files = glob($rootDir . '/database/migrations/*.sql') ?: [];
        sort($files);

        $pendingCount = 0;
        foreach ($files as $file) {
            $name = basename($file);
            $isApplied = in_array($name, $executed, true);
            if (!$isApplied) {
                $pendingCount++;
            }
            $tag = $isApplied ? '[APPLIED]' : '[PENDING]';
            fwrite(STDOUT, "{$tag} {$name}\n");
        }

        fwrite(STDOUT, "\nTotal: " . count($files) . " | Pending: {$pendingCount}\n");
        exit(0);
    }

    $ran = (new Migrator())->run();
    if ($ran === []) {
        fwrite(STDOUT, "No pending migrations.\n");
        exit(0);
    }

    fwrite(STDOUT, "Applied migrations:\n");
    foreach ($ran as $name) {
        fwrite(STDOUT, "  - {$name}\n");
    }
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if ($e->getPrevious() !== null) {
        $msg .= ' (' . $e->getPrevious()->getMessage() . ')';
    }
    fwrite(STDERR, "Migration error: {$msg}\n");
    exit(1);
}
