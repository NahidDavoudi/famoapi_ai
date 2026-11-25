<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\PhoneNumber;
use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\SmsService;
use App\Core\TotpService;
use App\Modules\Auth\User;

/**
 * Multi-channel admin 2FA orchestrator with automatic fallback:
 *   SMS -> Email -> Authenticator app (TOTP)
 *
 * Security invariants:
 * - Generates ONE CSPRNG 6-digit OTP per send, storing ONLY a salted HMAC-SHA256 hash.
 * - Constant-time verification (`hash_equals`) bound to user + per-login challenge token.
 * - 5-minute expiry, max 5 verification attempts per login session, single-use atomic consumption.
 * - 60-second resend cooldown, max 3 outbound sends per login session, plus per-account & per-IP send caps.
 * - Fallback to TOTP NEVER weakens security: TOTP requires enrollment and enforces RFC 6238 + replay protection.
 * - Never logs OTP codes, TOTP secrets, recovery codes, SMTP passwords, or SMS API keys.
 */
final class AdminTwoFactorService
{
    public const VALID_CHANNELS = ['sms', 'email', 'totp'];
    public const DEFAULT_CHAIN = ['sms', 'email', 'totp'];

    public const OTP_TTL_SECONDS = 300;        // 5 minutes
    public const MAX_VERIFY_ATTEMPTS = 5;      // 5 attempts per login session
    public const RESEND_COOLDOWN_SECONDS = 60; // 60 seconds cooldown
    public const MAX_SENDS_PER_SESSION = 3;    // max 3 sends per login session

    private const GENERIC_LOGIN_ERROR = 'نام کاربری یا رمز عبور اشتباه است';
    private const GENERIC_2FA_ERROR = 'کد تأیید نامعتبر یا منقضی شده است';

    private SmsChannel $smsChannel;
    private EmailChannel $emailChannel;
    private TotpChannel $totpChannel;
    /** @var (callable(): int)|null */
    private $clock;
    private ?string $logPathOverride;

    /**
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        ?SmsChannel $smsChannel = null,
        ?EmailChannel $emailChannel = null,
        ?TotpChannel $totpChannel = null,
        ?callable $clock = null,
        ?string $logPathOverride = null
    ) {
        $this->smsChannel = $smsChannel ?? new SmsChannel(new SmsService());
        $this->emailChannel = $emailChannel ?? new EmailChannel();
        $this->totpChannel = $totpChannel ?? new TotpChannel(new TotpService());
        $this->clock = $clock;
        $this->logPathOverride = $logPathOverride;
    }

    /**
     * Parse ordered delivery chain from ADMIN_2FA_CHAIN (default: sms,email,totp).
     * Preserves backward compatibility with ADMIN_2FA_METHOD when ADMIN_2FA_CHAIN is unset.
     *
     * @return array<int, string>
     */
    public static function configuredChain(): array
    {
        $rawChain = self::env('ADMIN_2FA_CHAIN');
        if ($rawChain !== '') {
            $parsed = self::parseChainString($rawChain);
            if ($parsed !== []) {
                return $parsed;
            }
        }

        // Legacy fallback when ADMIN_2FA_CHAIN is not configured
        $legacyMethod = strtolower(self::env('ADMIN_2FA_METHOD'));
        if ($legacyMethod === TotpService::METHOD_TOTP) {
            return ['totp'];
        }
        if ($legacyMethod === TotpService::METHOD_SMS) {
            return ['sms'];
        }

        return self::DEFAULT_CHAIN;
    }

    /**
     * @return array<int, string>
     */
    public static function parseChainString(string $raw): array
    {
        $seen = [];
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $ch = strtolower(trim($part));
            if ($ch !== '' && in_array($ch, self::VALID_CHANNELS, true) && !isset($seen[$ch])) {
                $seen[$ch] = true;
                $out[] = $ch;
            }
        }
        return $out;
    }

    public static function channelMessage(string $channel): string
    {
        return match ($channel) {
            'sms'   => 'Code sent via SMS',
            'email' => 'Code sent via email',
            'totp'  => 'Enter the code from your authenticator app',
            default => 'Enter your verification code',
        };
    }

    public static function channelMessageFa(string $channel): string
    {
        return match ($channel) {
            'sms'   => 'کد تأیید از طریق پیامک ارسال شد',
            'email' => 'کد تأیید از طریق ایمیل ارسال شد',
            'totp'  => 'کد اپلیکیشن احراز هویت (Authenticator) را وارد کنید',
            default => 'کد تأیید را وارد کنید',
        };
    }

    /**
     * Step 1 (after username + password): generate ONE CSPRNG 6-digit OTP, run the
     * configured delivery fallback chain (SMS -> Email -> TOTP), persist the hashed
     * challenge bound to this login session, and return UI metadata for the code step.
     *
     * @return array<string, mixed>
     */
    public function initiateChallenge(array $user, ?string $ip = null): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new ApiException(self::GENERIC_LOGIN_ERROR, 401, 'AUTH_ERROR');
        }

        $chain = self::configuredChain();
        $channels = $this->channelMap();

        // Generate ONE CSPRNG 6-digit OTP shared across SMS -> Email fallback within this login attempt.
        $otpCode = self::generateOtpCode();
        $challengeToken = bin2hex(random_bytes(32));
        $challengeHash = self::hashChallengeToken($userId, $challengeToken);

        $activeChannel = null;
        $destinationMask = null;
        $deliveredChannels = [];
        $sendCount = 0;
        $sendRateChecked = false;
        $nowTs = $this->now();
        $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);

        foreach ($chain as $channelName) {
            $channel = $channels[$channelName] ?? null;
            if (!$channel instanceof TwoFactorChannelInterface) {
                continue;
            }

            if ($channelName === 'sms' || $channelName === 'email') {
                // Skip disabled/circuit-open/missing-contact channels without burning send rate-limit quota.
                if ($channelName === 'sms' && (!SmsChannel::isEnabled() || SmsCircuitBreaker::isOpen())) {
                    $skipResult = $channel->send($user, $otpCode);
                    $this->logChannelFailure($userId, $skipResult);
                    continue;
                }
                if (!$channel->isAvailableFor($user)) {
                    $skipResult = $channel->send($user, $otpCode);
                    $this->logChannelFailure($userId, $skipResult);
                    continue;
                }

                if (!$sendRateChecked) {
                    $this->enforceSendRateLimits($userId, $ip);
                    $sendRateChecked = true;
                }

                $result = $channel->send($user, $otpCode);
                if ($result->success) {
                    $activeChannel = $channelName;
                    $destinationMask = $result->destinationMask;
                    $deliveredChannels[] = $channelName;
                    $sendCount = 1;
                    break;
                }

                $this->logChannelFailure($userId, $result);
                continue;
            }

            // TOTP fallback (no outbound message needed)
            $result = $channel->send($user, $otpCode);
            if ($result->success) {
                $activeChannel = 'totp';
                $destinationMask = null;
                break;
            }

            $this->logChannelFailure($userId, $result);
        }

        if ($activeChannel === null) {
            $this->writeLog(
                'AdminTwoFactorService: All configured 2FA channels failed for admin user #' . $userId
                . ' (chain=' . implode(',', $chain) . '). Enroll TOTP with `php nad admin:totp-setup ' . $userId . '` or check SMTP/SMS config.'
            );
            throw new ApiException(self::GENERIC_LOGIN_ERROR, 401, 'AUTH_ERROR');
        }

        // Only store salted OTP hash when an outbound OTP channel succeeded; never store plaintext OTP.
        $otpSalt = null;
        $otpHash = null;
        if ($activeChannel === 'sms' || $activeChannel === 'email') {
            $otpSalt = bin2hex(random_bytes(16));
            $otpHash = self::hashOtp($userId, $challengeHash, $otpSalt, $otpCode);
        }

        $ttl = self::challengeTtlSeconds();
        $expiresAt = gmdate('Y-m-d H:i:s', $nowTs + $ttl);
        $lastSentAt = $sendCount > 0 ? $nowUtc : null;

        $db = Database::getConnection();
        // Invalidate previous unconsumed challenges for this admin so only the latest login session is active.
        $db->prepare('UPDATE admin_2fa_challenges SET consumed_at = :now WHERE user_id = :uid AND consumed_at IS NULL')
           ->execute(['now' => $nowUtc, 'uid' => $userId]);

        $stmt = $db->prepare(
            'INSERT INTO admin_2fa_challenges
                (user_id, challenge_hash, otp_salt, otp_hash, active_channel, delivered_channels,
                 send_count, last_sent_at, verify_attempts, max_attempts, expires_at, created_at, updated_at)
             VALUES
                (:user_id, :challenge_hash, :otp_salt, :otp_hash, :active_channel, :delivered_channels,
                 :send_count, :last_sent_at, 0, :max_attempts, :expires_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'user_id'            => $userId,
            'challenge_hash'     => $challengeHash,
            'otp_salt'           => $otpSalt,
            'otp_hash'           => $otpHash,
            'active_channel'     => $activeChannel,
            'delivered_channels' => implode(',', $deliveredChannels),
            'send_count'         => $sendCount,
            'last_sent_at'       => $lastSentAt,
            'max_attempts'       => self::MAX_VERIFY_ATTEMPTS,
            'expires_at'         => $expiresAt,
            'created_at'         => $nowUtc,
            'updated_at'         => $nowUtc,
        ]);

        return $this->buildChallengeResponse(
            $user,
            $challengeToken,
            $activeChannel,
            $destinationMask,
            $sendCount,
            $ttl,
            $sendCount > 0 ? self::RESEND_COOLDOWN_SECONDS : 0,
            $chain
        );
    }

    /**
     * Secondary actions on the 2FA code step:
     * - Switch to 'totp' ("Use authenticator app instead") -> immediate, no send quota used.
     * - Switch from 'sms' to 'email' ("Didn't receive it? Send via email") -> sends new OTP via email.
     * - Resend on current outbound channel ('sms' or 'email') -> enforces 60s cooldown & max 3 sends/session.
     *
     * @return array<string, mixed>
     */
    public function switchOrResend(
        int $userId,
        string $challengeToken,
        ?string $targetChannel = null,
        ?string $ip = null
    ): array {
        $user = User::findById($userId);
        if (!$user || strtolower((string) ($user['role'] ?? '')) !== 'admin' || (int) ($user['is_active'] ?? 1) === 0) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $challenge = $this->findActiveChallenge($userId, $challengeToken, true);
        if ($challenge === null) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $chain = self::configuredChain();
        $currentChannel = (string) $challenge['active_channel'];
        $desired = $targetChannel !== null && trim($targetChannel) !== ''
            ? strtolower(trim($targetChannel))
            : $currentChannel;

        if (!in_array($desired, self::VALID_CHANNELS, true)) {
            throw new ApiException('کانال احراز هویت نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $nowTs = $this->now();
        $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
        $sendCount = (int) ($challenge['send_count'] ?? 0);
        $db = Database::getConnection();

        // Branch 1: Manual switch to Authenticator App (TOTP) - always available when enrolled
        if ($desired === 'totp') {
            if (!$this->totpChannel->isAvailableFor($user)) {
                throw new ApiException(
                    'اپلیکیشن احراز هویت (TOTP) برای این حساب فعال نشده است',
                    422,
                    'TOTP_NOT_ENROLLED'
                );
            }

            $db->prepare(
                'UPDATE admin_2fa_challenges SET active_channel = :ch, updated_at = :now WHERE id = :id AND consumed_at IS NULL'
            )->execute([
                'ch'  => 'totp',
                'now' => $nowUtc,
                'id'  => (int) $challenge['id'],
            ]);

            $remainingTtl = max(1, strtotime((string) $challenge['expires_at'] . ' UTC') - $nowTs);
            return $this->buildChallengeResponse(
                $user,
                $challengeToken,
                'totp',
                null,
                $sendCount,
                $remainingTtl,
                0,
                $chain
            );
        }

        // Branch 2: Outbound resend or fallback switch ('sms' or 'email')
        if (!in_array($desired, $chain, true)) {
            throw new ApiException('این کانال ارسال در تنظیمات فعال نیست', 422, 'CHANNEL_UNAVAILABLE');
        }

        if ($sendCount >= self::MAX_SENDS_PER_SESSION) {
            throw new RateLimitedException(self::challengeTtlSeconds(), 'حداکثر تعداد ارسال کد در این نشست به پایان رسیده است. لطفاً دوباره وارد شوید یا از اپلیکیشن احراز هویت استفاده کنید.');
        }

        $deliveredList = array_values(array_filter(explode(',', (string) ($challenge['delivered_channels'] ?? ''))));
        $isSameChannelOrAlreadyDelivered = ($desired === $currentChannel) || in_array($desired, $deliveredList, true);

        if ($isSameChannelOrAlreadyDelivered && !empty($challenge['last_sent_at'])) {
            $lastSentTs = strtotime((string) $challenge['last_sent_at'] . ' UTC');
            if ($lastSentTs !== false) {
                $elapsed = $nowTs - $lastSentTs;
                if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                    $retryAfter = self::RESEND_COOLDOWN_SECONDS - $elapsed;
                    throw new RateLimitedException($retryAfter, "لطفاً {$retryAfter} ثانیه صبر کنید و سپس دوباره درخواست ارسال کد دهید.");
                }
            }
        }

        $this->enforceSendRateLimits($userId, $ip);

        $channels = $this->channelMap();
        $channel = $channels[$desired];
        $otpCode = self::generateOtpCode();
        $result = $channel->send($user, $otpCode);

        if (!$result->success) {
            $this->logChannelFailure($userId, $result);
            throw new ApiException(
                'ارسال کد از طریق این کانال ناموفق بود. لطفاً از کانال جایگزین یا اپلیکیشن احراز هویت استفاده کنید.',
                503,
                '2FA_DELIVERY_FAILED'
            );
        }

        $challengeHash = (string) $challenge['challenge_hash'];
        $otpSalt = bin2hex(random_bytes(16));
        $otpHash = self::hashOtp($userId, $challengeHash, $otpSalt, $otpCode);
        $nextSendCount = $sendCount + 1;
        if (!in_array($desired, $deliveredList, true)) {
            $deliveredList[] = $desired;
        }
        $ttl = self::challengeTtlSeconds();
        $expiresAt = gmdate('Y-m-d H:i:s', $nowTs + $ttl);

        $db->prepare(
            'UPDATE admin_2fa_challenges
             SET otp_salt = :salt,
                 otp_hash = :hash,
                 active_channel = :ch,
                 delivered_channels = :delivered,
                 send_count = :send_count,
                 last_sent_at = :last_sent,
                 expires_at = :expires_at,
                 updated_at = :now
             WHERE id = :id AND consumed_at IS NULL'
        )->execute([
            'salt'       => $otpSalt,
            'hash'       => $otpHash,
            'ch'         => $desired,
            'delivered'  => implode(',', $deliveredList),
            'send_count' => $nextSendCount,
            'last_sent'  => $nowUtc,
            'expires_at' => $expiresAt,
            'now'        => $nowUtc,
            'id'         => (int) $challenge['id'],
        ]);

        return $this->buildChallengeResponse(
            $user,
            $challengeToken,
            $desired,
            $result->destinationMask,
            $nextSendCount,
            $ttl,
            self::RESEND_COOLDOWN_SECONDS,
            $chain
        );
    }

    /**
     * Step 2: verify either the sent OTP (SMS/Email), a valid RFC 6238 TOTP code,
     * or a single-use recovery code, depending on the active/selected method.
     *
     * @return array{method: string, remaining_recovery_codes?: int}
     */
    public function verifyChallenge(
        array $user,
        string $rawCode,
        ?string $challengeToken = null,
        ?string $requestedMethod = null,
        ?int $totpTimestamp = null
    ): array {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || (int) ($user['is_active'] ?? 1) === 0) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $requireToken = ($challengeToken !== null && trim($challengeToken) !== '');
        $challenge = $this->findActiveChallenge($userId, $challengeToken, $requireToken);
        if ($challenge === null) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $challengeId = (int) $challenge['id'];
        $attempts = (int) ($challenge['verify_attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($challenge['max_attempts'] ?? self::MAX_VERIFY_ATTEMPTS));
        $nowUtc = gmdate('Y-m-d H:i:s', $this->now());

        if ($attempts >= $maxAttempts) {
            $this->consumeChallengeRow($challengeId, $nowUtc);
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $normalized = PhoneNumber::toEnglishDigits(trim($rawCode));
        $compact = preg_replace('/[\s\-]+/', '', $normalized) ?? '';
        if ($compact === '') {
            $this->recordFailedAttempt($challengeId, $attempts, $maxAttempts, $nowUtc);
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $activeChannel = (string) $challenge['active_channel'];
        $methodHint = $requestedMethod !== null ? strtolower(trim($requestedMethod)) : '';
        $isSixDigits = preg_match('/^\d{6}$/', $compact) === 1;

        // Determine verification branch:
        // 1. Non-6-digit code (e.g. XXXXX-XXXXX recovery code) OR active_channel === 'totp' OR explicit totp/recovery hint
        $useTotpOrRecovery = !$isSixDigits
            || $activeChannel === 'totp'
            || in_array($methodHint, ['totp', 'recovery', 'authenticator'], true);

        if ($useTotpOrRecovery) {
            if (!$this->totpChannel->isAvailableFor($user)) {
                $this->recordFailedAttempt($challengeId, $attempts, $maxAttempts, $nowUtc);
                throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
            }

            try {
                $verified = $this->totpChannel->verify($user, $rawCode, $totpTimestamp);
            } catch (ApiException $e) {
                $this->recordFailedAttempt($challengeId, $attempts, $maxAttempts, $nowUtc);
                throw $e;
            }

            if (!$this->consumeChallengeRow($challengeId, $nowUtc)) {
                throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
            }

            $out = ['method' => (string) $verified['method']];
            if (isset($verified['remaining_recovery_codes'])) {
                $out['remaining_recovery_codes'] = (int) $verified['remaining_recovery_codes'];
            }
            return $out;
        }

        // 2. Sent 6-digit OTP verification (SMS or Email) with constant-time HMAC hash comparison
        $storedSalt = (string) ($challenge['otp_salt'] ?? '');
        $storedHash = (string) ($challenge['otp_hash'] ?? '');
        $challengeHash = (string) ($challenge['challenge_hash'] ?? '');

        if ($storedSalt === '' || $storedHash === '') {
            $this->recordFailedAttempt($challengeId, $attempts, $maxAttempts, $nowUtc);
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $candidateHash = self::hashOtp($userId, $challengeHash, $storedSalt, $compact);
        if (!hash_equals($storedHash, $candidateHash)) {
            $this->recordFailedAttempt($challengeId, $attempts, $maxAttempts, $nowUtc);
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        if (!$this->consumeChallengeRow($challengeId, $nowUtc)) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        return ['method' => $activeChannel];
    }

    /**
     * @return array<string, TwoFactorChannelInterface>
     */
    private function channelMap(): array
    {
        return [
            'sms'   => $this->smsChannel,
            'email' => $this->emailChannel,
            'totp'  => $this->totpChannel,
        ];
    }

    private function findActiveChallenge(int $userId, ?string $challengeToken, bool $requireToken): ?array
    {
        $nowUtc = gmdate('Y-m-d H:i:s', $this->now());
        $db = Database::getConnection();

        if ($requireToken) {
            $trimmed = trim((string) $challengeToken);
            if ($trimmed === '') {
                return null;
            }
            $expectedHash = self::hashChallengeToken($userId, $trimmed);
            $stmt = $db->prepare(
                'SELECT * FROM admin_2fa_challenges
                 WHERE user_id = :uid AND challenge_hash = :hash AND consumed_at IS NULL AND expires_at > :now
                 LIMIT 1'
            );
            $stmt->execute([
                'uid'  => $userId,
                'hash' => $expectedHash,
                'now'  => $nowUtc,
            ]);
            $row = $stmt->fetch();
            if (!$row || !hash_equals((string) $row['challenge_hash'], $expectedHash)) {
                return null;
            }
            return $row;
        }

        $stmt = $db->prepare(
            'SELECT * FROM admin_2fa_challenges
             WHERE user_id = :uid AND consumed_at IS NULL AND expires_at > :now
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'uid' => $userId,
            'now' => $nowUtc,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function recordFailedAttempt(int $challengeId, int $currentAttempts, int $maxAttempts, string $nowUtc): void
    {
        $db = Database::getConnection();
        $nextAttempts = $currentAttempts + 1;
        if ($nextAttempts >= $maxAttempts) {
            $db->prepare(
                'UPDATE admin_2fa_challenges
                 SET verify_attempts = :attempts, consumed_at = :now, otp_hash = NULL, otp_salt = NULL, updated_at = :upd
                 WHERE id = :id'
            )->execute([
                'attempts' => $nextAttempts,
                'now'      => $nowUtc,
                'upd'      => $nowUtc,
                'id'       => $challengeId,
            ]);
            return;
        }

        $db->prepare(
            'UPDATE admin_2fa_challenges SET verify_attempts = :attempts, updated_at = :upd WHERE id = :id'
        )->execute([
            'attempts' => $nextAttempts,
            'upd'      => $nowUtc,
            'id'       => $challengeId,
        ]);
    }

    private function consumeChallengeRow(int $challengeId, string $nowUtc): bool
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE admin_2fa_challenges
             SET consumed_at = :now, otp_hash = NULL, otp_salt = NULL, updated_at = :upd
             WHERE id = :id AND consumed_at IS NULL AND expires_at > :now_check'
        );
        $stmt->execute([
            'now'       => $nowUtc,
            'upd'       => $nowUtc,
            'id'        => $challengeId,
            'now_check' => $nowUtc,
        ]);
        return $stmt->rowCount() === 1;
    }

    private function enforceSendRateLimits(int $userId, ?string $ip): void
    {
        $userHourlyMax = max(1, (int) (self::env('AUTH_2FA_SEND_USER_HOURLY_MAX') ?: 10));
        $userKey = RateLimiter::key('2fa_send:user', (string) $userId);
        $userCheck = RateLimiter::attemptFixedWindow($userKey, $userHourlyMax, 3600);
        if (!$userCheck['allowed']) {
            throw new RateLimitedException($userCheck['retry_after'] > 0 ? $userCheck['retry_after'] : 3600);
        }

        if ($ip !== null && $ip !== '') {
            $ipHourlyMax = max(1, (int) (self::env('AUTH_2FA_SEND_IP_HOURLY_MAX') ?: 20));
            $ipKey = RateLimiter::key('2fa_send:ip', $ip);
            $ipCheck = RateLimiter::attemptFixedWindow($ipKey, $ipHourlyMax, 3600);
            if (!$ipCheck['allowed']) {
                throw new RateLimitedException($ipCheck['retry_after'] > 0 ? $ipCheck['retry_after'] : 3600);
            }
        }
    }

    /**
     * @param array<int, string> $chain
     * @return array<string, mixed>
     */
    private function buildChallengeResponse(
        array $user,
        string $challengeToken,
        string $activeChannel,
        ?string $destinationMask,
        int $sendCount,
        int $expiresIn,
        int $resendCooldownSeconds,
        array $chain
    ): array {
        $availableChannels = [];
        foreach ($chain as $ch) {
            if ($ch === 'sms' && SmsChannel::isEnabled() && $this->smsChannel->isAvailableFor($user)) {
                $availableChannels[] = 'sms';
            } elseif ($ch === 'email' && $this->emailChannel->isAvailableFor($user)) {
                $availableChannels[] = 'email';
            } elseif ($ch === 'totp' && $this->totpChannel->isAvailableFor($user)) {
                $availableChannels[] = 'totp';
            }
        }

        $sendsRemaining = max(0, self::MAX_SENDS_PER_SESSION - $sendCount);
        $canSendEmailFallback = $activeChannel === 'sms'
            && in_array('email', $chain, true)
            && $this->emailChannel->isAvailableFor($user)
            && $sendsRemaining > 0;
        $canUseAuthenticator = in_array('totp', $chain, true)
            && $this->totpChannel->isAvailableFor($user);

        return [
            'requires_2fa'            => true,
            'two_fa_method'           => $activeChannel,
            'channel'                 => $activeChannel,
            'channel_message'         => self::channelMessage($activeChannel),
            'channel_message_fa'      => self::channelMessageFa($activeChannel),
            'destination_mask'        => $destinationMask,
            'phone_mask'              => $activeChannel === 'sms' ? $destinationMask : null,
            'email_mask'              => $activeChannel === 'email' ? $destinationMask : null,
            'user_id'                 => (int) $user['id'],
            'username'                => (string) ($user['username'] ?? ''),
            'role'                    => 'admin',
            'challenge_token'         => $challengeToken,
            'expires_in'              => $expiresIn,
            'resend_cooldown_seconds' => $resendCooldownSeconds,
            'sends_used'              => $sendCount,
            'max_sends'               => self::MAX_SENDS_PER_SESSION,
            'sends_remaining'         => $sendsRemaining,
            'available_channels'      => $availableChannels,
            'secondary_actions'       => [
                'resend_current'    => in_array($activeChannel, ['sms', 'email'], true) && $sendsRemaining > 0,
                'send_via_email'    => $canSendEmailFallback,
                'use_authenticator' => $canUseAuthenticator,
            ],
        ];
    }

    private function logChannelFailure(int $userId, ChannelDeliveryResult $result): void
    {
        $errorClass = $result->errorClass ?? 'DeliveryFailed';
        $errorMessage = $result->errorMessage ?? 'unknown error';
        $prefix = $result->skipped ? 'skipped' : 'failed';
        $this->writeLog(
            sprintf(
                'AdminTwoFactorService: channel=%s %s for admin user #%d (%s: %s)',
                $result->channel,
                $prefix,
                $userId,
                $errorClass,
                $errorMessage
            )
        );
    }

    private function writeLog(string $message): void
    {
        $path = $this->logPathOverride ?: (dirname(__DIR__, 3) . '/storage/logs/app.log');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @error_log('[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $path);
    }

    private function now(): int
    {
        return is_callable($this->clock) ? (int) ($this->clock)() : time();
    }

    private static function challengeTtlSeconds(): int
    {
        $raw = self::env('AUTH_2FA_CHALLENGE_TTL');
        if ($raw !== '' && ctype_digit($raw)) {
            return max(60, (int) $raw);
        }
        return self::OTP_TTL_SECONDS;
    }

    private static function generateOtpCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function hashChallengeToken(int $userId, string $token): string
    {
        return hash_hmac('sha256', "admin_2fa_challenge|{$userId}|{$token}", self::hmacSecret());
    }

    private static function hashOtp(int $userId, string $challengeHash, string $salt, string $code): string
    {
        return hash_hmac('sha256', "admin_2fa_otp|{$userId}|{$challengeHash}|{$salt}|{$code}", self::hmacSecret());
    }

    private static function hmacSecret(): string
    {
        $raw = self::env('OTP_SECRET');
        if ($raw === '') {
            $raw = self::env('JWT_SECRET');
        }
        if ($raw === '') {
            return hash('sha256', 'famo-admin-2fa-default-hmac-key');
        }
        return hash_hmac('sha256', 'famo:admin_2fa:hmac:v1', $raw);
    }

    private static function env(string $key): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_scalar($val)) {
            return '';
        }
        return trim((string) $val);
    }
}
