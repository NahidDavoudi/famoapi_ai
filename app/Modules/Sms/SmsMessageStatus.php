<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Provider-reported delivery status of one message (reconciliation input).
 */
final class SmsMessageStatus
{
    public function __construct(
        public readonly string $messageId,
        /** one of: sent|delivered|failed|unknown */
        public readonly string $status,
        public readonly ?int $providerStatusCode = null,
        public readonly ?string $providerStatusText = null,
        public readonly ?float $cost = null,
        public readonly ?int $segments = null
    ) {
    }
}
