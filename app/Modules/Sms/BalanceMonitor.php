<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Rate-limited low-balance and insufficient-credit monitor for the Sms module.
 *
 * Designed with zero Redis dependency: cooldown state is stored in a small
 * local JSON file so repeated send failures or cron ticks do not flood logs
 * or alert channels. Secrets (KAVENEGAR_API_KEY) are always redacted before
 * any log line or notifier callback is emitted.
 */
final class BalanceMonitor
{
    private static ?string $statePathOverride = null;
    private static ?string $logPathOverride = null;
    /** @var (callable(string, array<string, mixed>): void)|null */
    private static $notifierOverride = null;

    public static function setStatePath(?string $path): void
    {
        self::$statePathOverride = $path;
    }

    public static function setLogPath(?string $path): void
    {
        self::$logPathOverride = $path;
    }

    /**
     * Optional callback invoked whenever a non-rate-limited alert fires:
     * fn(string $message, array $context): void
     *
     * @param (callable(string, array<string, mixed>): void)|null $notifier
     */
    public static function setNotifier(?callable $notifier): void
    {
        self::$notifierOverride = $notifier;
    }

    public static function reset(): void
    {
        self::$statePathOverride = null;
        self::$logPathOverride = null;
        self::$notifierOverride = null;
    }

    /**
     * Raise a rate-limited alert when the provider signals insufficient credit
     * at send time (e.g. Kavenegar status 411).
     */
    public static function alertInsufficientBalance(string $reason, ?SmsConfig $config = null): bool
    {
        $cfg = $config ?? self::safeConfig();
        $notifyMinutes = $cfg !== null ? $cfg->lowBalanceNotifyMinutes : 360;
        $secrets = $cfg !== null ? [$cfg->apiKey] : [];

        $safeReason = Redactor::redactSecrets(trim($reason), $secrets);
        $message = 'SMS provider reported insufficient balance' . ($safeReason !== '' ? ': ' . $safeReason : '');

        return self::emitRateLimited('insufficient_balance', $notifyMinutes, $message, [
            'event'    => 'insufficient_balance',
            'provider' => $cfg?->provider ?? 'unknown',
            'reason'   => $safeReason,
        ]);
    }

    /**
     * Poll the provider's account balance and emit a rate-limited alert when
     * it falls below SMS_LOW_BALANCE_THRESHOLD.
     *
     * @return array{balance: ?float, threshold: float, below_threshold: bool, alerted: bool}
     */
    public static function checkBalance(?SmsProvider $provider = null, ?SmsConfig $config = null): array
    {
        $cfg = $config ?? SmsConfig::fromEnv();
        $prov = $provider ?? Sms::provider();
        $balance = $prov->getBalance();

        if ($balance === null) {
            return [
                'balance'         => null,
                'threshold'       => $cfg->lowBalanceThreshold,
                'below_threshold' => false,
                'alerted'         => false,
            ];
        }

        $below = $cfg->lowBalanceThreshold > 0.0 && $balance < $cfg->lowBalanceThreshold;
        $alerted = false;

        if ($below) {
            $message = sprintf(
                'SMS provider balance is low: %.2f Rial (threshold: %.2f Rial, provider: %s)',
                $balance,
                $cfg->lowBalanceThreshold,
                $cfg->provider
            );
            $alerted = self::emitRateLimited('low_balance', $cfg->lowBalanceNotifyMinutes, $message, [
                'event'     => 'low_balance',
                'provider'  => $cfg->provider,
                'balance'   => $balance,
                'threshold' => $cfg->lowBalanceThreshold,
            ]);
        }

        return [
            'balance'         => $balance,
            'threshold'       => $cfg->lowBalanceThreshold,
            'below_threshold' => $below,
            'alerted'         => $alerted,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function emitRateLimited(string $eventKey, int $cooldownMinutes, string $message, array $context): bool
    {
        $cooldownSeconds = max(60, $cooldownMinutes * 60);
        $now = time();
        $statePath = self::statePath();
        $state = self::readState($statePath);

        $lastAlertTs = isset($state[$eventKey]) && is_numeric($state[$eventKey])
            ? (int) $state[$eventKey]
            : 0;

        if ($lastAlertTs > 0 && ($now - $lastAlertTs) < $cooldownSeconds) {
            return false;
        }

        $state[$eventKey] = $now;
        self::writeState($statePath, $state);

        $logLine = sprintf(
            '[%s] [CRITICAL] [Sms\BalanceMonitor] %s' . PHP_EOL,
            gmdate('Y-m-d H:i:s'),
            $message
        );

        $logPath = self::logPath();
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @error_log($logLine, 3, $logPath);

        if (is_callable(self::$notifierOverride)) {
            (self::$notifierOverride)($message, $context);
        }

        return true;
    }

    private static function safeConfig(): ?SmsConfig
    {
        try {
            return SmsConfig::fromEnv();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function statePath(): string
    {
        if (self::$statePathOverride !== null && self::$statePathOverride !== '') {
            return self::$statePathOverride;
        }

        return dirname(__DIR__, 3) . '/storage/logs/sms_balance_alerts.json';
    }

    private static function logPath(): string
    {
        if (self::$logPathOverride !== null && self::$logPathOverride !== '') {
            return self::$logPathOverride;
        }

        return dirname(__DIR__, 3) . '/storage/logs/app.log';
    }

    /**
     * @return array<string, int>
     */
    private static function readState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $out[$k] = (int) $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $state
     */
    private static function writeState(string $path, array $state): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($path, (string) json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
