<?php

return [
    'driver'  => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'    => $_ENV['DB_HOST'] ?? '',
    'port'    => $_ENV['DB_PORT'] ?? '5432',
    'name'    => $_ENV['DB_NAME'] ?? '',
    'user'    => $_ENV['DB_USER'] ?? '',
    'pass'    => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8',
    'schema'  => $_ENV['DB_SCHEMA'] ?? 'public',
];