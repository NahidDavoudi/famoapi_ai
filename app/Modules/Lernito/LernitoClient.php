<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use RuntimeException;

/**
 * HTTP client for the same-server Node Lernito gateway (/internal/*).
 */
final class LernitoClient implements LernitoClientInterface
{
    public const QUESTION_ID_PATTERN = '/^[a-zA-Z0-9_-]{8,64}$/';

    private LernitoConfig $config;

    public function __construct(?LernitoConfig $config = null)
    {
        $this->config = $config ?? LernitoConfig::fromEnv();
    }

    public function fetchShelves(string $account, bool $force = true, ?int $timeoutSeconds = null): array
    {
        $validatedAccount = $this->config->assertValidAccount($account);

        $query = [];
        if ($force) {
            $query['force'] = '1';
        }
        if ($this->config->sendAccountParam) {
            $query['account'] = $validatedAccount;
        }

        $path = '/internal/shelves' . ($query !== [] ? '?' . http_build_query($query) : '');
        $data = $this->request('GET', $path, null, $timeoutSeconds ?? $this->config->syncTimeoutSeconds);

        $shelves = $data['shelves'] ?? null;
        if (!is_array($shelves)) {
            throw new RuntimeException('پاسخ نامعتبر از سرویس لرنیتو: کلید shelves یافت نشد.');
        }

        return [
            'shelves'   => array_values($shelves),
            'count'     => isset($data['count']) ? (int) $data['count'] : count($shelves),
            'cachedAt'  => isset($data['cachedAt']) && is_string($data['cachedAt']) ? $data['cachedAt'] : null,
            'fromCache' => (bool) ($data['fromCache'] ?? false),
        ];
    }

    public function fetchQuestions(array $ids, string $account, ?int $timeoutSeconds = null): array
    {
        $validatedAccount = $this->config->assertValidAccount($account);

        $cleanIds = [];
        foreach ($ids as $id) {
            $trimmed = trim((string) $id);
            if ($trimmed !== '' && preg_match(self::QUESTION_ID_PATTERN, $trimmed) === 1 && !in_array($trimmed, $cleanIds, true)) {
                $cleanIds[] = $trimmed;
            }
        }

        if ($cleanIds === []) {
            return [
                'questions'      => [],
                'requestedCount' => 0,
                'fetchedCount'   => 0,
                'missingIds'     => [],
            ];
        }

        if (count($cleanIds) > LernitoConfig::MAX_BATCH_SIZE) {
            throw new RuntimeException(
                'تعداد شناسه‌های ارسالی به fetch-questions نمی‌تواند بیش از ' . LernitoConfig::MAX_BATCH_SIZE . ' باشد.'
            );
        }

        $payload = ['ids' => $cleanIds];
        if ($this->config->sendAccountParam) {
            $payload['account'] = $validatedAccount;
        }

        $data = $this->request(
            'POST',
            '/internal/fetch-questions',
            $payload,
            $timeoutSeconds ?? $this->config->syncTimeoutSeconds
        );

        $questions = $data['questions'] ?? null;
        if (!is_array($questions)) {
            throw new RuntimeException('پاسخ نامعتبر از سرویس لرنیتو: کلید questions یافت نشد.');
        }

        $missingIds = [];
        if (isset($data['missingIds']) && is_array($data['missingIds'])) {
            foreach ($data['missingIds'] as $missingId) {
                $m = trim((string) $missingId);
                if ($m !== '') {
                    $missingIds[] = $m;
                }
            }
        }

        return [
            'questions'      => array_values($questions),
            'requestedCount' => isset($data['requestedCount']) ? (int) $data['requestedCount'] : count($cleanIds),
            'fetchedCount'   => isset($data['fetchedCount']) ? (int) $data['fetchedCount'] : count($questions),
            'missingIds'     => array_values(array_unique($missingIds)),
        ];
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $jsonBody, int $timeoutSeconds): array
    {
        if ($this->config->internalKey === '') {
            throw new RuntimeException('متغیر LERNITO_INTERNAL_KEY تنظیم نشده است.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('افزونه PHP cURL برای ارتباط با سرویس لرنیتو نصب نیست.');
        }

        $url = $this->config->serviceUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('خطا در مقداردهی cURL برای سرویس لرنیتو.');
        }

        $headers = [
            'Accept: application/json',
            'X-Internal-Key: ' . $this->config->internalKey,
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, max(2, $timeoutSeconds)),
            CURLOPT_TIMEOUT        => max(2, $timeoutSeconds),
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];

        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                curl_close($ch);
                throw new RuntimeException('خطا در کدگذاری JSON بدنه درخواست لرنیتو.');
            }
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $encoded;
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $curlErrno !== 0) {
            $safeErr = $this->redactSecret($curlErr !== '' ? $curlErr : "cURL errno {$curlErrno}");
            throw new RuntimeException("خطای ارتباط با سرویس لرنیتو: {$safeErr}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("پاسخ غیر-JSON از سرویس لرنیتو (HTTP {$httpStatus}).");
        }

        $ok = (bool) ($decoded['ok'] ?? false);
        if ($httpStatus < 200 || $httpStatus >= 300 || !$ok) {
            $errCode = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['code'] ?? 'UPSTREAM_ERROR') : 'UPSTREAM_ERROR';
            $errMsg = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['message'] ?? "HTTP {$httpStatus}") : "HTTP {$httpStatus}";
            $safeMsg = $this->redactSecret($errMsg);
            throw new RuntimeException("خطای سرویس لرنیتو [{$errCode}] (HTTP {$httpStatus}): {$safeMsg}");
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('پاسخ نامعتبر از سرویس لرنیتو: بخش data خالی است.');
        }

        return $data;
    }

    private function redactSecret(string $message): string
    {
        if ($this->config->internalKey === '') {
            return $message;
        }
        return str_replace($this->config->internalKey, '[REDACTED]', $message);
    }
}
