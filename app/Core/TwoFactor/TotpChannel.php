<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

use App\Core\TotpService;

/**
 * Authenticator-app TOTP channel for admin 2FA, wrapping the existing RFC 6238 TotpService.
 *
 * No outbound network call is required; falling back to TOTP prompts the user directly
 * with "Enter the code from your authenticator app" and verifies via TotpService (full
 * second factor with ±1 step drift, replay protection, and single-use recovery codes).
 */
final class TotpChannel implements TwoFactorChannelInterface
{
    private TotpService $totp;

    public function __construct(?TotpService $totp = null)
    {
        $this->totp = $totp ?? new TotpService();
    }

    public function name(): string
    {
        return 'totp';
    }

    public function isAvailableFor(array $user): bool
    {
        return TotpService::isEnrolled($user);
    }

    public function send(array $user, string $otpCode): ChannelDeliveryResult
    {
        if (!$this->isAvailableFor($user)) {
            return ChannelDeliveryResult::failed(
                'totp',
                'TotpNotEnrolled',
                'Admin account is not enrolled in TOTP authenticator app',
                true
            );
        }

        return ChannelDeliveryResult::ok('totp', null);
    }

    /**
     * Verify a 6-digit TOTP code or one-time recovery code.
     *
     * @return array{method: string, matched_step?: int, remaining_recovery_codes?: int}
     */
    public function verify(array $user, string $code, ?int $timestamp = null): array
    {
        return $this->totp->verifyAdminCode($user, $code, $timestamp);
    }
}
