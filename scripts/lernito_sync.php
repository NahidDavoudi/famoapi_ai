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

use App\Modules\Lernito\LernitoConfig;
use App\Modules\Lernito\LernitoSyncService;

/**
 * Parse CLI flags (--key=value or --flag).
 *
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseLernitoSyncArgs(array $argv): array
{
    $options = [];
    for ($i = 1, $len = count($argv); $i < $len; $i++) {
        $token = $argv[$i];
        if ($token === '-h' || $token === '--help') {
            $options['help'] = true;
            continue;
        }
        if (!str_starts_with($token, '--')) {
            continue;
        }
        $eqPos = strpos($token, '=');
        if ($eqPos !== false) {
            $options[substr($token, 2, $eqPos - 2)] = substr($token, $eqPos + 1);
        } else {
            $options[substr($token, 2)] = true;
        }
    }
    return $options;
}

function printLernitoSyncUsage(): void
{
    $usage = <<<'TEXT'
FAMO Lernito Question Bank Sync Runner (scripts/lernito_sync.php)

Usage:
  php scripts/lernito_sync.php --account=motevasete1 [options]
  php scripts/lernito_sync.php --account=motevasete1 --refresh-versions [--days=60]

Options:
  --account=NAME         Account identifier (e.g. motevasete1 or motevasete2).
                         REQUIRED and MUST be a single account when LERNITO_SEND_ACCOUNT_PARAM=false.
                         When LERNITO_SEND_ACCOUNT_PARAM=true, omit or pass --account=all for all accounts.
  --max-questions=N      Max missing/refresh questions to fetch per account (default: 500 sync / 2000 refresh).
  --force-links          Rebuild lernito_shelf_questions even if records_hash has not changed.
  --retry-missing        Include missing questions past cooldown / max attempts (manual override).
  --refresh-versions     Run monthly 60-day version refresh job instead of shelf sync.
  --days=N               Lookback window in days for --refresh-versions (default: 60).
  --job-id=ID            Internal: job ID passed by async API trigger.
  --lock-token=TOKEN     Internal: pre-acquired Redis lock token passed by async API trigger.
  --triggered-by=ACTOR   Audit label (default: cli).
  --json                 Emit JSON output.
  --help, -h             Show this help message.

Recommended Crontab:
  Daily sync (03:30 UTC):
    30 3 * * * php /path/to/famoapi_ai/scripts/lernito_sync.php --account=motevasete1 >> /path/to/storage/logs/lernito_sync.log 2>&1
  Monthly version refresh (1st of month, 04:30 UTC):
    30 4 1 * * php /path/to/famoapi_ai/scripts/lernito_sync.php --account=motevasete1 --refresh-versions >> /path/to/storage/logs/lernito_sync.log 2>&1

TEXT;
    fwrite(STDOUT, $usage);
}

$options = parseLernitoSyncArgs($argv);
if (!empty($options['help'])) {
    printLernitoSyncUsage();
    exit(0);
}

$asJson = !empty($options['json']);
$requestedAccount = isset($options['account']) && is_string($options['account']) ? $options['account'] : null;

$config = LernitoConfig::fromEnv();
$service = new LernitoSyncService($config);

$commonOpts = [
    'job_id'       => isset($options['job-id']) && is_string($options['job-id']) ? $options['job-id'] : null,
    'lock_token'   => isset($options['lock-token']) && is_string($options['lock-token']) ? $options['lock-token'] : null,
    'triggered_by' => isset($options['triggered-by']) && is_string($options['triggered-by']) ? $options['triggered-by'] : 'cli',
];

try {
    if (!empty($options['refresh-versions'])) {
        $refreshOpts = $commonOpts;
        if (isset($options['days']) && is_string($options['days']) && ctype_digit($options['days'])) {
            $refreshOpts['days'] = max(1, (int) $options['days']);
        }
        if (isset($options['max-questions']) && is_string($options['max-questions']) && ctype_digit($options['max-questions'])) {
            $refreshOpts['max_questions'] = max(1, (int) $options['max-questions']);
        }
        $result = $service->runMonthlyVersionRefresh($requestedAccount, $refreshOpts);
    } else {
        $syncOpts = $commonOpts;
        $syncOpts['force_links'] = !empty($options['force-links']);
        $syncOpts['retry_missing'] = !empty($options['retry-missing']);
        if (isset($options['max-questions']) && is_string($options['max-questions']) && ctype_digit($options['max-questions'])) {
            $syncOpts['max_questions'] = max(1, (int) $options['max-questions']);
        }
        $result = $service->runSync($requestedAccount, $syncOpts);
    }

    if ($asJson) {
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    } else {
        $ts = gmdate('Y-m-d H:i:s');
        fwrite(
            STDOUT,
            "[{$ts} UTC] lernito_sync job={$result['job_id']} status={$result['status']} progress="
            . json_encode($result['progress'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n"
        );
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                fwrite(STDERR, "[{$ts} UTC] ERROR: {$err}\n");
            }
        }
    }

    exit(empty($result['errors']) ? 0 : 1);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Invalid arguments: {$e->getMessage()}\n");
    exit(2);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if ($config->internalKey !== '') {
        $msg = str_replace($config->internalKey, '[REDACTED]', $msg);
    }
    fwrite(STDERR, "Error: {$msg}\n");
    exit(1);
}
