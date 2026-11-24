<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Deterministic in-memory provider for tests and local development.
 * Never touches the network and never sends a real SMS.
 */
final class FakeProvider implements SmsProvider
{
    /** @var SmsSendResult[] consumed queue for send()/sendTemplate() */
    private array $sendResults;

    /** @var array<string, array<int, SmsMessageStatus>> consumed queue per message id */
    private array $statusResults;

    private ?float $balance;

    /** Recorded provider calls, for assertions. @var array<int, array<string, mixed>> */
    public array $calls = [];

    /**
     * @param SmsSendResult[] $sendResults
     * @param array<string, array<int, SmsMessageStatus>> $statusResults
     */
    public function __construct(array $sendResults = [], array $statusResults = [], ?float $balance = null)
    {
        $this->sendResults = array_values($sendResults);
        $this->statusResults = $statusResults;
        $this->balance = $balance;
    }

    public static function okResult(?float $cost = 1000.0): SmsSendResult
    {
        return new SmsSendResult(
            true,
            'fake-' . substr(bin2hex(random_bytes(8)), 0, 12),
            $cost,
            1,
            'Queued',
            null,
            true
        );
    }

    public static function failedResult(
        int $returnStatus = 402,
        string $text = 'Operation failed',
        bool $definitive = true
    ): SmsSendResult {
        return new SmsSendResult(
            false,
            null,
            null,
            $returnStatus,
            $text,
            'kavenegar_return_status_' . $returnStatus . ': ' . $text,
            $definitive
        );
    }

    public function send(string $receptor, string $text, string $sender): SmsSendResult
    {
        $this->calls[] = ['op' => 'send', 'receptor' => $receptor, 'text' => $text, 'sender' => $sender];
        $result = array_shift($this->sendResults);

        return $result instanceof SmsSendResult ? $result : self::okResult();
    }

    public function sendTemplate(string $receptor, string $template, array $tokens, string $sender): SmsSendResult
    {
        $this->calls[] = [
            'op'       => 'sendTemplate',
            'receptor' => $receptor,
            'template' => $template,
            'tokens'   => $tokens,
            'sender'   => $sender,
        ];
        $result = array_shift($this->sendResults);

        return $result instanceof SmsSendResult ? $result : self::okResult();
    }

    public function fetchStatuses(array $messageIds): array
    {
        $this->calls[] = ['op' => 'fetchStatuses', 'messageIds' => $messageIds];

        $out = [];
        foreach ($messageIds as $messageId) {
            $key = (string) $messageId;
            $queue = $this->statusResults[$key] ?? null;
            $status = null;
            if (is_array($queue) && $queue !== []) {
                $status = array_shift($queue);
                $this->statusResults[$key] = $queue;
            }
            $out[$key] = $status instanceof SmsMessageStatus
                ? $status
                : new SmsMessageStatus($key, 'unknown');
        }

        return $out;
    }

    public function getBalance(): ?float
    {
        $this->calls[] = ['op' => 'getBalance'];

        return $this->balance;
    }
}
