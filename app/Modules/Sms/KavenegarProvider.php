<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Kavenegar REST client (https://kavenegar.com/rest.html).
 *
 * SECURITY: the API key is part of every request URL
 * (https://api.kavenegar.com/v1/{API-KEY}/sms/send.json). It is therefore
 * NEVER written to logs, exception messages, or database error columns.
 * This class builds no error strings that contain the URL, and transport
 * failures are reduced to null results without logging the URL.
 *
 * Status mapping (single source of truth, mirrored in the module README):
 *
 * Kavenegar message (delivery) status codes -> internal status
 *   1  queued for sending                    -> sent
 *   2  scheduled                             -> sent
 *   4, 5  handed over to the telecom         -> sent
 *   6  sending failed                        -> failed
 *   10 delivered                             -> delivered
 *   11 not delivered (handset off/unreachable) -> failed
 *   13 cancelled / failed, cost refunded     -> failed
 *   14 blocked by recipient, cost refunded   -> failed
 *   100 invalid message id                   -> unknown
 *   anything else                            -> unknown
 *
 * Kavenegar `return.status` codes at send time (table 1 of the docs):
 *   200                            -> accepted
 *   400/401/402/403/404/405/418    -> definitive rejection
 *   409 (server busy, retry later) -> outcome unknown
 *   anything else                  -> outcome unknown (never blindly retried)
 */
final class KavenegarProvider implements SmsProvider
{
    private const BASE_URL = 'https://api.kavenegar.com/v1/';

    /** Delivery status code -> internal status (see class docblock). */
    public const MESSAGE_STATUS_MAP = [
        1   => 'sent',
        2   => 'sent',
        4   => 'sent',
        5   => 'sent',
        6   => 'failed',
        10  => 'delivered',
        11  => 'failed',
        13  => 'failed',
        14  => 'failed',
        100 => 'unknown',
    ];

    /** return.status values that mean the provider definitively refused the message. */
    private const DEFINITIVE_REJECTION_CODES = [400, 401, 402, 403, 404, 405, 418];

    /**
     * return.status values reported for insufficient account credit.
     * NOTE: 411 appears in Kavenegar SDK error tables ("insufficient credit")
     * but is NOT listed in the official rest.html table; treated as a
     * best-effort signal only (see module README, "Not verified").
     */
    private const INSUFFICIENT_BALANCE_CODES = [411];

    private string $apiKey;
    private int $timeoutMs;

    public function __construct(string $apiKey, int $timeoutMs = 10000)
    {
        $this->apiKey = $apiKey;
        $this->timeoutMs = max(1000, $timeoutMs);
    }

    public function send(string $receptor, string $text, string $sender): SmsSendResult
    {
        return $this->call('sms/send.json', [
            'receptor' => $receptor,
            'sender'   => $sender,
            'message'  => $text,
        ]);
    }

    public function sendTemplate(string $receptor, string $template, array $tokens, string $sender): SmsSendResult
    {
        $params = [
            'receptor' => $receptor,
            'template' => $template,
        ];

        // Kavenegar VerifyLookup accepts up to three tokens (token, token2, token3).
        $tokenKeys = ['token', 'token2', 'token3'];
        $index = 0;
        foreach ($tokens as $token) {
            if ($index >= 3) {
                break;
            }
            if (is_scalar($token) && (string) $token !== '') {
                $params[$tokenKeys[$index]] = (string) $token;
            }
            $index++;
        }

        return $this->call('verify/lookup.json', $params);
    }

    public function fetchStatuses(array $messageIds): array
    {
        $out = [];
        foreach ($messageIds as $messageId) {
            $key = (string) $messageId;
            if ($key === '') {
                continue;
            }

            $json = $this->request('sms/status.json', ['messageid' => $key], 'GET');
            if ($json === null) {
                $out[$key] = new SmsMessageStatus($key, 'unknown');
                continue;
            }

            $entry = is_array($json['entries'][0] ?? null) ? $json['entries'][0] : null;
            if ($entry === null) {
                $out[$key] = new SmsMessageStatus($key, 'unknown');
                continue;
            }

            $code = isset($entry['status']) && is_numeric($entry['status']) ? (int) $entry['status'] : null;
            $out[$key] = new SmsMessageStatus(
                $key,
                self::mapMessageStatus($code),
                $code,
                isset($entry['statustext']) && is_string($entry['statustext']) ? $entry['statustext'] : null
            );
        }

        return $out;
    }

    public function getBalance(): ?float
    {
        $json = $this->request('account/info.json', [], 'GET');
        if ($json === null) {
            return null;
        }

        $entries = $json['entries'] ?? null;
        if (!is_array($entries)) {
            return null;
        }

        // Response shape parsed defensively (see module README, "Not verified").
        $account = is_array($entries['account'] ?? null) ? $entries['account'] : $entries;
        $balance = $account['balance'] ?? null;

        return is_numeric($balance) ? (float) $balance : null;
    }

    public static function mapMessageStatus(?int $code): string
    {
        if ($code === null) {
            return 'unknown';
        }

        return self::MESSAGE_STATUS_MAP[$code] ?? 'unknown';
    }

    public static function isInsufficientBalanceCode(?int $code): bool
    {
        return $code !== null && in_array($code, self::INSUFFICIENT_BALANCE_CODES, true);
    }

    /**
     * Execute one Kavenegar method (send or verify/lookup) and map the reply.
     */
    private function call(string $method, array $params): SmsSendResult
    {
        $json = $this->request($method, $params, 'POST');
        if ($json === null) {
            // Network problem / timeout / unparseable body: outcome unknown.
            return new SmsSendResult(
                false,
                null,
                null,
                null,
                null,
                'provider_unreachable_or_unparseable_response',
                false
            );
        }

n        $returnStatus = isset($json['return']['status']) && is_numeric($json['return']['status'])
            ? (int) $json['return']['status']
            : null;
        $returnMessage = isset($json['return']['message']) && is_string($json['return']['message'])
            ? $json['return']['message']
            : null;

        if ($returnStatus !== 200) {
            $error = 'kavenegar_return_status_' . ($returnStatus ?? 'null')
                . ($returnMessage !== null ? ': ' . $returnMessage : '');

            return new SmsSendResult(
                false,
                null,
                null,
                $returnStatus,
                $returnMessage,
                $error,
                $returnStatus !== null && in_array($returnStatus, self::DEFINITIVE_REJECTION_CODES, true)
            );
        }

        $entry = is_array($json['entries'][0] ?? null) ? $json['entries'][0] : null;
        if ($entry === null) {
            // HTTP/return 200 but no entry we can read: do NOT claim success.
            return new SmsSendResult(
                false,
                null,
                null,
                $returnStatus,
                $returnMessage,
                'kavenegar_missing_entry',
                false
            );
        }

        $messageId = isset($entry['messageid']) && is_numeric($entry['messageid'])
            ? (string) $entry['messageid']
            : null;
        if ($messageId === null) {
            return new SmsSendResult(
                false,
                null,
                null,
                $returnStatus,
                $returnMessage,
                'kavenegar_missing_messageid',
                false
            );
        }

        $cost = isset($entry['cost']) && is_numeric($entry['cost']) ? (float) $entry['cost'] : null;
        $code = isset($entry['status']) && is_numeric($entry['status']) ? (int) $entry['status'] : null;

        return new SmsSendResult(
            true,
            $messageId,
            $cost,
            $code,
            isset($entry['statustext']) && is_string($entry['statustext']) ? $entry['statustext'] : null,
            null,
            true
        );
    }

    /**
     * Perform a Kavenegar REST call and return the decoded JSON body.
     * Returns null on any transport-level failure or unparseable response.
     * The URL (which contains the API key) is never returned or logged.
     *
     * @return array<string,mixed>|null
     */
    private function request(string $method, array $params, string $httpMethod): ?array
    {
        $url = self::BASE_URL . rawurlencode($this->apiKey) . '/' . $method;

        $ch = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_HTTPHEADER        => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER    => true,
        ];

        if ($httpMethod === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } else {
            $query = count($params) > 0 ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
            $options[CURLOPT_URL] = $url . $query;
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || !is_string($body) || $body === '' || $curlError !== '') {
            // Deliberately no logging here: any context could leak the URL/key.
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['return'])) {
            return null;
        }

        return $decoded;
    }
}
