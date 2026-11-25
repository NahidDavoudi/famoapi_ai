<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

/**
 * Common interface for admin 2FA channels (SMS, Email, TOTP).
 */
interface TwoFactorChannelInterface
{
    /** Canonical channel identifier: 'sms' | 'email' | 'totp'. */
    public function name(): string;

    /** Whether the user has the required contact/enrollment configured for this channel. */
    public function isAvailableFor(array $user): bool;

    /**
     * Execute delivery (or readiness check for TOTP) for the given admin user.
     * Implementations MUST never throw uncaught exceptions or leak secrets/OTPs in error strings.
     */
    public function send(array $user, string $otpCode): ChannelDeliveryResult;
}
