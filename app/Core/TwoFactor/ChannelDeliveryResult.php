<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

/**
 * Outcome of attempting one second-factor channel delivery step.
 */
final class ChannelDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $channel,
        public readonly ?string $destinationMask = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $skipped = false
    ) {
    }

    public static function ok(string $channel, ?string $destinationMask = null): self
    {
        return new self(true, $channel, $destinationMask, null, null, false);
    }

    public static function failed(
        string $channel,
        string $errorClass,
        string $errorMessage,
        bool $skipped = false
    ): self {
        return new self(false, $channel, null, $errorClass, $errorMessage, $skipped);
    }
}
