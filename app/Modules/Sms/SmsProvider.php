<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Transport-agnostic SMS provider contract. Implementations:
 * - KavenegarProvider: production (Kavenegar REST API)
 * - FakeProvider:      tests / local development (never touches the network)
 */
interface SmsProvider
{
    /** Send a plain text message to one receptor. */
    public function send(string $receptor, string $text, string $sender): SmsSendResult;

    /**
     * Send a template (OTP/lookup) message. At most 3 tokens.
     *
     * @param array<int,string> $tokens
     */
    public function sendTemplate(string $receptor, string $template, array $tokens, string $sender): SmsSendResult;

    /**
     * Query the provider delivery status for the given message ids.
     *
     * @param array<int,string> $messageIds
     * @return array<string,SmsMessageStatus> keyed by message id; missing ids map to status "unknown"
     */
    public function fetchStatuses(array $messageIds): array;

    /** Account balance in Rial, or null when the provider cannot report it. */
    public function getBalance(): ?float;
}
