<?php

return [
    'enabled'  => filter_var($_ENV['REDIS_ENABLED'] ?? (!empty($_ENV['REDIS_HOST']) || !empty($_ENV['REDIS_URL'])), FILTER_VALIDATE_BOOLEAN),
    'url'      => $_ENV['REDIS_URL'] ?? '',
    'host'     => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    'port'     => (int) ($_ENV['REDIS_PORT'] ?? 6379),
    'password' => $_ENV['REDIS_PASSWORD'] ?? null,
    'database' => (int) ($_ENV['REDIS_DB'] ?? 0),
    'prefix'   => $_ENV['REDIS_PREFIX'] ?? 'famo:',
    'timeout'  => (float) ($_ENV['REDIS_TIMEOUT'] ?? 1.5),
];
