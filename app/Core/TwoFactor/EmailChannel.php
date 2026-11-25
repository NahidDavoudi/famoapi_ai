<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

/**
 * Email delivery channel for admin 2FA via SMTP (PHPMailer).
 *
 * Reads MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD,
 * MAIL_FROM (with fallback to SMTP_* env vars). Configured for Gmail SMTP
 * (smtp.gmail.com:465 SSL + App Password) with an 8-second hard timeout.
 *
 * Security invariants:
 * - Never logs OTP codes or MAIL_PASSWORD / SMTP_PASS.
 * - Treats missing mail config, invalid email, transport exception, or timeout as failure.
 */
final class EmailChannel implements TwoFactorChannelInterface
{
    public const HARD_TIMEOUT_SECONDS = 8;

    /** @var (callable(array{to: string, from: string, from_name: string, subject: string, body: string, config: array<string, mixed>, timeout: int}): void)|null */
    private $transport;

    /**
     * @param (callable(array{to: string, from: string, from_name: string, subject: string, body: string, config: array<string, mixed>, timeout: int}): void)|null $transport
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function name(): string
    {
        return 'email';
    }

    public function isAvailableFor(array $user): bool
    {
        return $this->resolveEmail($user) !== null;
    }

    public function send(array $user, string $otpCode): ChannelDeliveryResult
    {
        $email = $this->resolveEmail($user);
        if ($email === null) {
            return ChannelDeliveryResult::failed(
                'email',
                'MissingEmailAddress',
                'Admin account has no valid email address',
                true
            );
        }

        $config = self::mailConfig();
        if ($this->transport === null && ($config['host'] === '' || $config['from'] === '')) {
            return ChannelDeliveryResult::failed(
                'email',
                'MailNotConfigured',
                'MAIL_HOST or MAIL_FROM is not configured',
                true
            );
        }

        $subject = 'کد تأیید ورود به پنل مدیریت فامو | FAMO Admin Login Code';
        $body = implode("\n", [
            'کد تأیید ورود به پنل مدیریت فامو:',
            '',
            $otpCode,
            '',
            'این کد تا ۵ دقیقه معتبر است و فقط یک‌بار قابل استفاده می‌باشد.',
            'اگر این درخواست توسط شما انجام نشده است، این ایمیل را نادیده بگیرید و فوراً رمز عبور خود را تغییر دهید.',
            '',
            '---',
            'Your FAMO Admin Panel login verification code: ' . $otpCode,
            'This code expires in 5 minutes and can only be used once.',
            "If this wasn't you, ignore this email and change your password immediately.",
        ]);

        $payload = [
            'to'        => $email,
            'from'      => $config['from'] !== '' ? $config['from'] : 'no-reply@famoacademy.ir',
            'from_name' => $config['from_name'],
            'subject'   => $subject,
            'body'      => $body,
            'config'    => $config,
            'timeout'   => self::HARD_TIMEOUT_SECONDS,
        ];

        try {
            if ($this->transport !== null) {
                ($this->transport)($payload);
            } else {
                $this->sendViaPhpMailer($payload);
            }
        } catch (\Throwable $e) {
            return ChannelDeliveryResult::failed(
                'email',
                get_class($e),
                self::sanitizeMessage($e->getMessage(), $otpCode, $config['password'])
            );
        }

        return ChannelDeliveryResult::ok('email', self::maskEmail($email));
    }

    public static function maskEmail(string $email): string
    {
        $trimmed = trim($email);
        $atPos = strpos($trimmed, '@');
        if ($atPos === false || $atPos === 0) {
            return '***';
        }
        $local = substr($trimmed, 0, $atPos);
        $domain = substr($trimmed, $atPos + 1);
        $visible = strlen($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
        return $visible . '***@' . $domain;
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from: string, from_name: string}
     */
    public static function mailConfig(): array
    {
        $host = self::envFirst(['MAIL_HOST', 'SMTP_HOST']);
        $portRaw = self::envFirst(['MAIL_PORT', 'SMTP_PORT']);
        $port = ($portRaw !== '' && ctype_digit($portRaw)) ? (int) $portRaw : 465;

        $encRaw = strtolower(self::envFirst(['MAIL_ENCRYPTION', 'SMTP_ENCRYPTION']));
        if ($encRaw === '') {
            $encRaw = $port === 465 ? 'ssl' : 'tls';
        }

        $username = self::envFirst(['MAIL_USERNAME', 'SMTP_USER']);
        $password = self::envFirst(['MAIL_PASSWORD', 'SMTP_PASS']);
        $from = self::envFirst(['MAIL_FROM', 'SMTP_FROM']);
        if ($from === '' && $username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL) !== false) {
            $from = $username;
        }
        $fromName = self::envFirst(['MAIL_FROM_NAME', 'SMTP_FROM_NAME']);
        if ($fromName === '') {
            $fromName = 'FAMO Academy';
        }

        return [
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encRaw,
            'username'   => $username,
            'password'   => $password,
            'from'       => $from,
            'from_name'  => $fromName,
        ];
    }

    /**
     * @param array{to: string, from: string, from_name: string, subject: string, body: string, config: array<string, mixed>, timeout: int} $payload
     */
    private function sendViaPhpMailer(array $payload): void
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new \RuntimeException('PHPMailer is not installed; run composer install.');
        }

        $cfg = $payload['config'];
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host = (string) $cfg['host'];
        $mail->Port = (int) $cfg['port'];
        $mail->Timeout = (int) $payload['timeout'];
        $mail->SMTPDebug = 0;

        $username = (string) $cfg['username'];
        $password = (string) $cfg['password'];
        $mail->SMTPAuth = ($username !== '' || $password !== '');
        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        $enc = strtolower((string) $cfg['encryption']);
        if (in_array($enc, ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($enc, ['tls', 'starttls'], true)) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom((string) $payload['from'], (string) $payload['from_name']);
        $mail->addAddress((string) $payload['to']);
        $mail->isHTML(false);
        $mail->Subject = (string) $payload['subject'];
        $mail->Body = (string) $payload['body'];

        $mail->send();
    }

    private function resolveEmail(array $user): ?string
    {
        $raw = isset($user['email']) && is_string($user['email']) ? trim($user['email']) : '';
        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $raw;
    }

    /** @param array<int, string> $keys */
    private static function envFirst(array $keys): string
    {
        foreach ($keys as $key) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_scalar($val) && trim((string) $val) !== '') {
                return trim((string) $val);
            }
        }
        return '';
    }

    private static function sanitizeMessage(string $message, string $otpCode, string $mailPassword): string
    {
        $clean = $message;
        foreach ([$otpCode, $mailPassword] as $secret) {
            $s = trim($secret);
            if ($s !== '' && str_contains($clean, $s)) {
                $clean = str_replace($s, '[REDACTED]', $clean);
            }
        }
        return mb_substr(trim($clean), 0, 240);
    }
}
