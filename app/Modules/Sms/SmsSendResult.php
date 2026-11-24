<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Result of one send attempt as reported by a provider.
 * Error strings MUST already be redacted by the provider implementation
 * (they may end up in sms_log.error).
 */
final class SmsSendResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $messageId = null,
        public readonly ?float $cost = null,
        public readonly ?int $providerStatusCode = null,
        public readonly ?string $providerStatusText = null,
        public readonly ?string $error = null,
        /** true = provider definitively refused (failed); false = outcome unknown (never blindly retried) */
        public readonly bool $definitive = true
    ) {
    }
}
