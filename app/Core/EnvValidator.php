<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Validates mandatory and optional environment variables for incoming API requests.
 */
final class EnvValidator
{
    public const CORE_REQUIRED = [
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'JWT_SECRET',
    ];

    private const POSITIVE_INT_KEYS = [
        'JWT_TTL',
        'UPLOAD_MAX_SIZE',
        'AUTH_2FA_CHALLENGE_TTL',
        'AUTH_2FA_MAX_ATTEMPTS',
        'AUTH_LOGIN_IP_MAX_ATTEMPTS',
        'AUTH_LOGIN_LOCKOUT_SECONDS',
        'AUTH_LOGIN_MAX_ATTEMPTS',
        'AUTH_LOGIN_USER_SOFT_MAX',
        'AUTH_LOGIN_WINDOW_SECONDS',
        'AUTH_SMS_IP_HOURLY_MAX',
        'AUTH_SMS_PHONE_HOURLY_MAX',
        'AUTH_TELEGRAM_REGISTER_PER_IP',
        'AUTH_TELEGRAM_REGISTER_PER_TG',
        'AUTH_TELEGRAM_VERIFY_PER_IP',
        'AUTH_TELEGRAM_VERIFY_PER_TG',
        'BOT_BROADCAST_DAILY_LIMIT',
        'BOT_OUTBOX_LOCK_TTL',
        'BOT_OUTBOX_MAX_ATTEMPTS',
        'BOT_REPORT_NOTIFY_WINDOW',
        'TELEGRAM_AUTH_TICKET_TTL',
        'TELEGRAM_LOGIN_MAX_AGE',
        'SMTP_PORT',
        'REDIS_PORT',
        'PUBLIC_RATE_LIMIT_MAX',
        'PUBLIC_RATE_LIMIT_WINDOW',
        'RATE_LIMIT_API_AUTH_WINDOW',
        'RATE_LIMIT_API_STAFF_MAX',
        'RATE_LIMIT_API_STUDENT_MAX',
        'RATE_LIMIT_AUTH_IP_MAX',
        'RATE_LIMIT_AUTH_IP_WINDOW',
        'RATE_LIMIT_AUTH_USER_IP_MAX',
        'RATE_LIMIT_AUTH_USER_IP_WINDOW',
        'RATE_LIMIT_PUBLIC_EXAM_IP_MAX',
        'RATE_LIMIT_PUBLIC_EXAM_IP_WINDOW',
        'RATE_LIMIT_PUBLIC_IP_MAX',
        'RATE_LIMIT_PUBLIC_IP_WINDOW',
        'RATE_LIMIT_SMS_IP_MAX',
        'RATE_LIMIT_SMS_IP_WINDOW',
        'RATE_LIMIT_SMS_PHONE_MAX',
        'RATE_LIMIT_SMS_PHONE_WINDOW',
    ];

    private const NON_NEGATIVE_INT_KEYS = [
        'BOT_OUTBOX_RETRY_BACKOFF',
        'REDIS_DB',
    ];

    private const BOOLEAN_KEYS = [
        'APP_DEBUG',
        'CACHE_ENABLED',
        'JWT_COOKIE_SECURE',
        'REDIS_ENABLED',
    ];

    private const URL_KEYS = [
        'API_URL',
        'ASSET_URL',
        'TELEGRAM_BOT_URL',
    ];

    private const ALLOWED_APP_ENVS = [
        'production',
        'development',
        'local',
        'testing',
        'staging',
    ];

    private const ALLOWED_SAMESITE = [
        'lax',
        'strict',
        'none',
    ];

    /**
     * Validate environment variables for a specific HTTP request.
     *
     * @throws ApiException when mandatory variables are missing or malformed
     */
    public static function validateForRequest(ServerRequestInterface $request): void
    {
        $path = $request->getUri()->getPath();

        // Health endpoints only validate optional variable formats if present.
        $isHealth = ($path === '/health' || $path === '/api/v1/health');
        $isBotRoute = str_starts_with($path, '/api/v1/bot');
        $isTelegramAuthRoute = str_starts_with($path, '/api/v1/auth/telegram');

        $errors = [];

        if (!$isHealth) {
            foreach (self::CORE_REQUIRED as $key) {
                if ($key === 'DB_PASS') {
                    // DB_PASS key must be defined (may be empty string in local/test environments)
                    if (!self::hasKey($key) && !Database::hasConnection()) {
                        $errors[] = "متغیر محیطی الزامی {$key} تعریف نشده است";
                    }
                    continue;
                }

                if (in_array($key, ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'], true) && Database::hasConnection()) {
                    // If an active PDO connection is already injected (e.g. test harness), only validate format if set
                    $val = self::get($key);
                    if ($val !== null && trim($val) === '') {
                        $errors[] = "متغیر محیطی {$key} نمی‌تواند خالی باشد";
                    }
                    continue;
                }

                $val = self::get($key);
                if ($val === null || trim($val) === '') {
                    $errors[] = "متغیر محیطی الزامی {$key} تنظیم نشده است";
                }
            }
        }

        // Validate DB_PORT format when present
        $dbPort = self::get('DB_PORT');
        if ($dbPort !== null && $dbPort !== '') {
            $portInt = filter_var($dbPort, FILTER_VALIDATE_INT);
            if ($portInt === false || $portInt < 1 || $portInt > 65535) {
                $errors[] = 'متغیر محیطی DB_PORT باید یک عدد صحیح بین 1 تا 65535 باشد';
            }
        }

        // Route-specific mandatory environment variables
        if ($isBotRoute) {
            $botKey = self::get('BOT_SERVICE_KEY');
            if ($botKey === null || trim($botKey) === '') {
                throw new ApiException('کلید سرویس ربات تنظیم نشده است', 500, 'BOT_NOT_CONFIGURED');
            }

            $allowList = trim((string) (self::get('BOT_IP_ALLOWLIST') ?? ''));
            if ($allowList !== '') {
                foreach (explode(',', $allowList) as $ip) {
                    $ip = trim($ip);
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                        $errors[] = "مقدار آی‌پی نامعتبر در BOT_IP_ALLOWLIST: {$ip}";
                    }
                }
            }
        }

        if ($isTelegramAuthRoute) {
            $secretHex = self::get('TELEGRAM_LOGIN_SECRET_KEY');
            if ($secretHex === null || trim($secretHex) === '') {
                $errors[] = 'متغیر محیطی الزامی TELEGRAM_LOGIN_SECRET_KEY تنظیم نشده است';
            } elseif (!preg_match('/^[0-9a-fA-F]{64}$/', $secretHex)) {
                $errors[] = 'متغیر محیطی TELEGRAM_LOGIN_SECRET_KEY باید یک رشته هگزادسیمال ۶۴ کاراکتری معتبر باشد';
            }
        }

        // Format checks for optional environment variables when set and non-empty
        $formatErrors = self::validateOptionalFormats();
        if ($formatErrors !== []) {
            $errors = array_merge($errors, $formatErrors);
        }

        if ($errors !== []) {
            throw new ApiException($errors[0], 500, 'ENV_CONFIGURATION_ERROR');
        }
    }

    /**
     * Validate an explicit list of required environment variable keys plus all format rules.
     *
     * @param array<int,string> $requiredKeys
     * @return array<int,string> list of validation error messages
     */
    public static function validate(array $requiredKeys = self::CORE_REQUIRED): array
    {
        $errors = [];

        foreach ($requiredKeys as $key) {
            if ($key === 'DB_PASS') {
                if (!self::hasKey($key)) {
                    $errors[] = "متغیر محیطی الزامی {$key} تعریف نشده است";
                }
                continue;
            }

            $value = self::get($key);
            if ($value === null || trim($value) === '') {
                $errors[] = "متغیر محیطی الزامی {$key} تنظیم نشده است";
            }
        }

        $dbPort = self::get('DB_PORT');
        if ($dbPort !== null && $dbPort !== '') {
            $portInt = filter_var($dbPort, FILTER_VALIDATE_INT);
            if ($portInt === false || $portInt < 1 || $portInt > 65535) {
                $errors[] = 'متغیر محیطی DB_PORT باید یک عدد صحیح بین 1 تا 65535 باشد';
            }
        }

        return array_merge($errors, self::validateOptionalFormats());
    }

    /**
     * @return array<int,string>
     */
    public static function validateOptionalFormats(): array
    {
        $errors = [];

        $appEnv = self::get('APP_ENV');
        if ($appEnv !== null && $appEnv !== '' && !in_array(strtolower(trim($appEnv)), self::ALLOWED_APP_ENVS, true)) {
            $errors[] = 'متغیر محیطی APP_ENV نامعتبر است';
        }

        $sameSite = self::get('JWT_COOKIE_SAMESITE');
        if ($sameSite !== null && $sameSite !== '' && !in_array(strtolower(trim($sameSite)), self::ALLOWED_SAMESITE, true)) {
            $errors[] = 'متغیر محیطی JWT_COOKIE_SAMESITE باید یکی از مقادیر Lax، Strict یا None باشد';
        }

        foreach (['AUTH_REVOCATION_FAIL_MODE', 'AUTH_REVOCATION_ADMIN_FAIL_MODE'] as $failModeKey) {
            $failModeVal = self::get($failModeKey);
            if ($failModeVal !== null && $failModeVal !== '' && !in_array(strtolower(trim($failModeVal)), ['open', 'closed'], true)) {
                $errors[] = "متغیر محیطی {$failModeKey} باید یکی از مقادیر open یا closed باشد";
            }
        }

        foreach (self::BOOLEAN_KEYS as $key) {
            $val = self::get($key);
            if ($val !== null && $val !== '') {
                $normalized = strtolower(trim($val));
                if (!in_array($normalized, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
                    $errors[] = "متغیر محیطی {$key} باید مقدار بولی (true/false) باشد";
                }
            }
        }

        foreach (self::POSITIVE_INT_KEYS as $key) {
            $val = self::get($key);
            if ($val !== null && $val !== '') {
                $intVal = filter_var($val, FILTER_VALIDATE_INT);
                if ($intVal === false || $intVal < 1) {
                    $errors[] = "متغیر محیطی {$key} باید یک عدد صحیح مثبت باشد";
                }
            }
        }

        foreach (self::NON_NEGATIVE_INT_KEYS as $key) {
            $val = self::get($key);
            if ($val !== null && $val !== '') {
                $intVal = filter_var($val, FILTER_VALIDATE_INT);
                if ($intVal === false || $intVal < 0) {
                    $errors[] = "متغیر محیطی {$key} باید یک عدد صحیح نامنفی باشد";
                }
            }
        }

        foreach (self::URL_KEYS as $key) {
            $val = self::get($key);
            if ($val !== null && trim($val) !== '') {
                $trimmed = trim($val);
                $parts = parse_url($trimmed);
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                if (filter_var($trimmed, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                    $errors[] = "متغیر محیطی {$key} باید یک آدرس اینترنتی (URL) معتبر باشد";
                }
            }
        }

        $secretHex = self::get('TELEGRAM_LOGIN_SECRET_KEY');
        if ($secretHex !== null && $secretHex !== '' && !preg_match('/^[0-9a-fA-F]{64}$/', $secretHex)) {
            $errors[] = 'متغیر محیطی TELEGRAM_LOGIN_SECRET_KEY باید یک رشته هگزادسیمال ۶۴ کاراکتری معتبر باشد';
        }

        return $errors;
    }

    private static function hasKey(string $key): bool
    {
        return array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false;
    }

    private static function get(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
            return is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : null;
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        $env = getenv($key);
        return $env !== false ? (string) $env : null;
    }
}
