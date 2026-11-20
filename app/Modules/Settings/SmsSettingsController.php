<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\ApiException;
use App\Core\SmsPatternSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SmsSettingsController
{
    private const ALIAS_TO_KEY = [
        'auth_body_id'               => SmsPatternSettings::KEY_AUTH_2FA,
        'login_body_id'              => SmsPatternSettings::KEY_AUTH_2FA,
        'pattern_body_id'            => SmsPatternSettings::KEY_AUTH_2FA,
        'MELIPAYAMAK_PATTERN_BODY_ID' => SmsPatternSettings::KEY_AUTH_2FA,
        'appointment_body_id'        => SmsPatternSettings::KEY_APPOINTMENT_REMINDER,
        'MELIPAYAMAK_APPOINTMENT_BODY_ID' => SmsPatternSettings::KEY_APPOINTMENT_REMINDER,
        'exam_body_id'               => SmsPatternSettings::KEY_EXAM_RESULT,
        'MELIPAYAMAK_EXAM_BODY_ID'   => SmsPatternSettings::KEY_EXAM_RESULT,
    ];

    public function getPatterns(Request $request, Response $response): Response
    {
        $data = SmsPatternSettings::getAll();

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $data,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function updatePatterns(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $actorUserId = isset($decoded->sub) ? (int) $decoded->sub : null;
        $body = (array) ($request->getParsedBody() ?? []);

        $updates = [];

        // Shape 1: { "pattern_key": "appointment_reminder", "body_id": 123456, "pattern_text": "..." }
        if (isset($body['pattern_key']) && is_string($body['pattern_key'])) {
            $key = trim($body['pattern_key']);
            if (!isset(SmsPatternSettings::DEFINITIONS[$key])) {
                return $this->validationError($response, "کلید پترن «{$key}» معتبر نیست");
            }
            $entry = [];
            if (array_key_exists('body_id', $body)) {
                $entry['body_id'] = $this->parseValidatedBodyId($body['body_id'], $key, $response);
                if ($entry['body_id'] === false) {
                    return $response;
                }
            }
            if (array_key_exists('pattern_text', $body)) {
                $entry['pattern_text'] = is_string($body['pattern_text']) ? $body['pattern_text'] : null;
            }
            if ($entry !== []) {
                $updates[$key] = $entry;
            }
        }

        // Shape 2: { "patterns": { "appointment_reminder": { "body_id": 123456, "pattern_text": "..." } } }
        if (isset($body['patterns']) && is_array($body['patterns'])) {
            foreach ($body['patterns'] as $key => $cfg) {
                $keyStr = trim((string) $key);
                if (!isset(SmsPatternSettings::DEFINITIONS[$keyStr])) {
                    return $this->validationError($response, "کلید پترن «{$keyStr}» معتبر نیست");
                }
                if (!is_array($cfg)) {
                    $parsed = $this->parseValidatedBodyId($cfg, $keyStr, $response);
                    if ($parsed === false) {
                        return $response;
                    }
                    $updates[$keyStr] = ['body_id' => $parsed];
                    continue;
                }
                $entry = [];
                if (array_key_exists('body_id', $cfg)) {
                    $parsed = $this->parseValidatedBodyId($cfg['body_id'], $keyStr, $response);
                    if ($parsed === false) {
                        return $response;
                    }
                    $entry['body_id'] = $parsed;
                }
                if (array_key_exists('pattern_text', $cfg)) {
                    $entry['pattern_text'] = is_string($cfg['pattern_text']) ? $cfg['pattern_text'] : null;
                }
                if ($entry !== []) {
                    $updates[$keyStr] = $entry;
                }
            }
        }

        // Shape 3: Direct key objects { "appointment_reminder": { "body_id": 123456 } }
        foreach (array_keys(SmsPatternSettings::DEFINITIONS) as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $cfg = $body[$key];
                $entry = $updates[$key] ?? [];
                if (array_key_exists('body_id', $cfg)) {
                    $parsed = $this->parseValidatedBodyId($cfg['body_id'], $key, $response);
                    if ($parsed === false) {
                        return $response;
                    }
                    $entry['body_id'] = $parsed;
                }
                if (array_key_exists('pattern_text', $cfg)) {
                    $entry['pattern_text'] = is_string($cfg['pattern_text']) ? $cfg['pattern_text'] : null;
                }
                if ($entry !== []) {
                    $updates[$key] = $entry;
                }
            }
        }

        // Shape 4: Flat alias fields { "appointment_body_id": 123456, "exam_body_id": ..., "auth_body_id": ... }
        foreach (self::ALIAS_TO_KEY as $alias => $key) {
            if (array_key_exists($alias, $body)) {
                $parsed = $this->parseValidatedBodyId($body[$alias], $alias, $response);
                if ($parsed === false) {
                    return $response;
                }
                $updates[$key] = array_merge($updates[$key] ?? [], ['body_id' => $parsed]);
            }
        }

        if ($updates === []) {
            return $this->validationError(
                $response,
                'حداقل یکی از کدهای پترن (مانند appointment_body_id یا patterns) باید ارسال شود'
            );
        }

        try {
            $data = SmsPatternSettings::updateMany($updates, $actorUserId);
        } catch (ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $data,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @return int|null|false false when invalid and error response already written
     */
    private function parseValidatedBodyId(mixed $raw, string $field, Response &$response): int|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $parsed = SmsPatternSettings::parsePositiveInt($raw);
        if ($parsed === null) {
            $response = $this->validationError(
                $response,
                "کد پترن ({$field}) باید یک عدد صحیح مثبت باشد"
            );
            return false;
        }
        return $parsed;
    }

    private function validationError(Response $response, string $message): Response
    {
        $response->getBody()->write(json_encode([
            'success'    => false,
            'data'       => null,
            'pagination' => null,
            'error'      => [
                'code'    => 'VALIDATION_ERROR',
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
