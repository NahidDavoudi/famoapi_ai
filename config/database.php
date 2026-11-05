<?php

return [
    'driver'  => $_ENV['DB_DRIVER'] ?? 'mysql',
    'host'    => $_ENV['DB_HOST'] ?? '',
    'port'    => $_ENV['DB_PORT'] ?? '3306',
    'name'    => $_ENV['DB_NAME'] ?? '',
    'user'    => $_ENV['DB_USER'] ?? '',
    'pass'    => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
];