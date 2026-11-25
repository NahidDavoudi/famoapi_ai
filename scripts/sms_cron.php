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

use App\Modules\Sms\BalanceMonitor;
use App\Modules\Sms\Reconciler;
use App\Modules\Sms\Redactor;

/**
 * Parse CLI arguments into command and --key[=value] options.
 *
 * @param array<int, string> $argv
 * @return array{command: string, options: array<string, string|bool>}
 */
function parseSmsCronArgs(array $argv): array
{
    $command = strtolower(trim((string) ($argv[1] ?? 'help')));
    $options = [];

    for ($i = 2, $len = count($argv); $i < $len; $i++) {
        $token = $argv[$i];
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

    return ['command' => $command, 'options' => $options];
}

function printSmsCronUsage(): void
{
    $usage = <<<'TEXT'
FAMO SMS Scheduled Task Runner (scripts/sms_cron.php)

Usage:
  php scripts/sms_cron.php reconcile [--limit=100] [--json]
  php scripts/sms_cron.php balance   [--json]
  php scripts/sms_cron.php unknown   [--limit=100] [--json]
  php scripts/sms_cron.php all       [--limit=100] [--json]

Commands:
  reconcile  Poll the SMS provider for delivery status of eligible `sent` and
             `unknown` rows (with a provider_msg_id) and update sms_log.
  balance    Check provider account balance against SMS_LOW_BALANCE_THRESHOLD
             and emit a rate-limited alert when below threshold.
  unknown    List `unknown` rows WITHOUT a provider_msg_id for manual operator
             review (metadata only; never prints message bodies or secrets).
  all        Run `reconcile`, `balance`, and report the `unknown` review count.

Options:
  --limit=N  Maximum rows to scan/list (default: 100, min: 1, max: 1000).
  --json     Emit machine-readable JSON output.

TEXT;
    fwrite(STDOUT, $usage);
}

/**
 * @param array<string, mixed> $payload
 */
function writeSmsCronOutput(array $payload, bool $asJson): void
{
    if ($asJson) {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        return;
    }

    foreach ($payload as $section => $value) {
        if (is_array($value)) {
            fwrite(STDOUT, "[{$section}] " . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        } else {
            fwrite(STDOUT, "{$section}=" . (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? 'null')) . "\n");
        }
    }
}

$parsed = parseSmsCronArgs($argv);
$command = $parsed['command'];
$options = $parsed['options'];

if ($command === '' || $command === 'help' || $command === '--help' || $command === '-h') {
    printSmsCronUsage();
    exit(0);
}

$asJson = !empty($options['json']);
$limit = 100;
if (isset($options['limit']) && is_string($options['limit']) && ctype_digit($options['limit'])) {
    $limit = max(1, min(1000, (int) $options['limit']));
}

try {
    if ($command === 'reconcile') {
        $result = Reconciler::reconcile($limit);
        writeSmsCronOutput(['reconcile' => $result], $asJson);
        exit(0);
    }

    if ($command === 'balance') {
        $result = BalanceMonitor::checkBalance();
        writeSmsCronOutput(['balance' => $result], $asJson);
        exit(0);
    }

    if ($command === 'unknown') {
        $rows = Reconciler::listUnknownWithoutMessageId($limit);
        $safeRows = array_map(
            static fn (array $row): array => [
                'id'         => (int) ($row['id'] ?? 0),
                'client'     => (string) ($row['client'] ?? ''),
                'purpose'    => (string) ($row['purpose'] ?? ''),
                'receptor'   => (string) ($row['receptor'] ?? ''),
                'ref'        => (string) ($row['ref'] ?? ''),
                'provider'   => (string) ($row['provider'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ],
            $rows
        );
        writeSmsCronOutput([
            'unknown_without_msg_id_count' => count($safeRows),
            'rows'                         => $safeRows,
        ], $asJson);
        exit(0);
    }

    if ($command === 'all') {
        $reconcile = Reconciler::reconcile($limit);
        $balance = BalanceMonitor::checkBalance();
        $unknownRows = Reconciler::listUnknownWithoutMessageId($limit);

        writeSmsCronOutput([
            'reconcile'                    => $reconcile,
            'balance'                      => $balance,
            'unknown_without_msg_id_count' => count($unknownRows),
        ], $asJson);
        exit(0);
    }

    fwrite(STDERR, "Unknown command: {$command}\n\n");
    printSmsCronUsage();
    exit(2);
} catch (\Throwable $e) {
    $secrets = [
        $_ENV['KAVENEGAR_API_KEY'] ?? getenv('KAVENEGAR_API_KEY') ?: null,
    ];
    $safeMessage = Redactor::redactSecrets($e->getMessage(), $secrets);
    fwrite(STDERR, "Error: {$safeMessage}\n");
    exit(1);
}
