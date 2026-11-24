<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Module configuration - environment variables only (no config files, no DB),
 * validated eagerly so misconfiguration fails fast with SmsConfigException.
 *
 * Required (when provider = kavenegar):
 * - KAVENEGAR_API_KEY          secret; never logged or committed
 * - KAVENEGAR_SENDER           sender line number (e.g. 10004346)
 * Required (always, when provider = kavenegar):
 * - SMS_LOW_BALANCE_THRESHOLD  Rial; when the provider balance drops below
 *                              this value, an alert is raised (rate limited)
 * Optional:
 * - SMS_PROVIDER               kavenegar|fake; default: kavenegar in
 *                              production, fake everywhere else
 * - SMS_REQUEST_TIMEOUT_MS     default 10000 (min 1000)
 * - SMS_RECONCILE_MAX_AGE_DAYS default 3
 * - SMS_RECONCILE_MIN_AGE_SECONDS default 60
 * - SMS_LOW_BALANCE_NOTIFY_MINUTES default 360
 */
final class SmsConfig
{
    public const CLIENT = 'famo';

    public const PROVIDER_KAVENEGAR = 'kavenegar';
    public const PROVIDER_FAKE = 'fake';

    public function __construct(
        public readonly string $provider,
        public readonly string $apiKey,
        public readonly string $sender,
        public readonly int $timeoutMs,
        public readonly float $lowBalanceThreshold,
        public readonly int $reconcileMaxAgeDays,
        public readonly int $reconcileMinAgeSeconds,
        public readonly int $lowBalanceNotifyMinutes
    ) {
    }

    public static function fromEnv(): self
    {
        $provider = self::env('SMS_PROVIDER');
        if ($provider === '') {
            // Production defaults to the real provider; every other
            // environment (development/local/testing/staging) defaults to
            // the fake provider so no real SMS can ever go out by accident.
            $provider = self::isProduction() ? self::PROVIDER_KAVENEGAR : self::PROVIDER_FAKE;
        }
        $provider = strtolower($provider);
        if ($provider !== self::PROVIDER_KAVENEGAR && $provider !== self::PROVIDER_FAKE) {
            throw new SmsConfigException('SMS_PROVIDER must be either "kavenegar" or "fake".');
        }

        $apiKey = self::env('KAVENEGAR_API_KEY');
        $sender = self::env('KAVENEGAR_SENDER');
        $lowBalanceRaw = self::env('SMS_LOW_BALANCE_THRESHOLD');

        if ($provider === self::PROVIDER_KAVENEGAR) {
            if ($apiKey === '') {
                throw new SmsConfigException('KAVENEGAR_API_KEY is required when SMS_PROVIDER=kavenegar.');
            }
            if ($sender === '') {
                throw new SmsConfigException('KAVENEGAR_SENDER is required when SMS_PROVIDER=kavenegar.');
            }
        }

        if ($lowBalanceRaw === '') {
            // Only mandatory for the real provider; the fake provider
            // (tests/dev) does not check balances.
            if ($provider === self::PROVIDER_KAVENEGAR) {
                throw new SmsConfigException('SMS_LOW_BALANCE_THRESHOLD is required (unit: Iranian Rial).');
            }
            $lowBalanceThreshold = 0.0;
        } else {
            if (!is_numeric($lowBalanceRaw)) {
                throw new SmsConfigException('SMS_LOW_BALANCE_THRESHOLD must be numeric (unit: Iranian Rial).');
            }
            $lowBalanceThreshold = (float) $lowBalanceRaw;
        }

        return new self(
            $provider,
            $apiKey,
            $sender,
            max(1000, self::envInt('SMS_REQUEST_TIMEOUT_MS', 10000)),
            $lowBalanceThreshold,
            max(1, self::envInt('SMS_RECONCILE_MAX_AGE_DAYS', 3)),
            max(1, self::envInt('SMS_RECONCILE_MIN_AGE_SECONDS', 60)),
            max(1, self::envInt('SMS_LOW_BALANCE_NOTIFY_MINUTES', 360))
        );
    }

    private static function isProduction(): bool
    {
        $appEnv = strtolower(self::env('APP_ENV'));
        // Same convention as App\Core\EnvValidator: unset/empty APP_ENV means production.
        return $appEnv === '' || $appEnv === 'production';
    }

    private static function envInt(string $key, int $default): int
    {
        $value = self::env($key);
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string) $value);
    }
}
