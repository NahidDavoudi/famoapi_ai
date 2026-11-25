<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * FAMO internal SMS module - the single public entry point is Sms::send().
 *
 * Design constraints (see app/Modules/Sms/README.md):
 * - Self-contained: never imports Famo domain code, only App\Core\Database
 *   for the sms_log table. Can be extracted into a shared package with a
 *   namespace rename and one composer.json autoload entry.
 * - No Redis, no queues: one DB row per message (sms_log) plus a simple CLI
 *   scheduled task (scripts/sms_cron.php).
 * - Provider failures NEVER throw; only invalid input / configuration does.
 * - The Kavenegar API key is never logged, stored in the DB error column, or
 *   returned (see Redactor).
 */
final class Sms
{
    private static ?SmsProvider $providerOverride = null;

    /** Test seam: inject a provider (FakeProvider) or null to restore env config. */
    public static function setProvider(?SmsProvider $provider): void
    {
        self::$providerOverride = $provider;
    }

    /** Resolve the active provider from SMS_PROVIDER / SmsConfig. */
    public static function provider(): SmsProvider
    {
        if (self::$providerOverride instanceof SmsProvider) {
            return self::$providerOverride;
        }

        $config = SmsConfig::fromEnv();
        if ($config->provider === SmsConfig::PROVIDER_FAKE) {
            return new FakeProvider();
        }

        return new KavenegarProvider($config->apiKey, $config->timeoutMs);
    }

    /**
     * Send one SMS through the configured provider, exactly once per
     * (client, purpose, receptor, ref) tuple.
     *
     * $input keys:
     * - purpose  (required) billing/reporting tag, e.g. "otp.auth_2fa",
     *            "appointment.reminder". Pattern: [A-Za-z0-9_.-]{1,64}.
     *            Purposes starting with "otp" have their text redacted in
     *            the database.
     * - receptor (required) Iranian mobile number in any common format.
     * - text     (required) message body; for template sends this is only
     *            used as the token when no explicit tokens are given.
     * - ref      (required) opaque caller-provided idempotency key (at most
     *            191 chars). No foreign key, no interpretation by this module.
     * - template (optional) Kavenegar VerifyLookup template name; switches
     *            the provider call to verify/lookup.json.
     * - tokens   (optional) up to 3 template tokens: positional list or map
     *            with token/token2/token3 keys. Defaults to [text].
     * - retry    (optional, default false) when true and the deduplicated
     *            row exists with status "failed", re-send using the SAME row.
     *            Rows with status "unknown" are never retried automatically.
     *
     * @param array<string,mixed> $input
     * @return array{id:int, status:string, providerMsgId:?string}
     *         status is one of: sent|failed|unknown|pending|delivered
     *         (the last two are returned for already-existing deduplicated rows)
     *
     * @throws SmsInputException  invalid input (thrown BEFORE any DB write or provider call)
     * @throws SmsConfigException missing/invalid module configuration
     */
    public static function send(array $input): array
    {
        [$purpose, $receptor, $text, $ref, $template, $tokens, $retry] = self::validateInput($input);

        $config = SmsConfig::fromEnv();
        $segments = SegmentCounter::count($text);
        $storedText = Redactor::isOtpPurpose($purpose)
            ? Redactor::maskOtpText($text)
            : $text;

        $id = SmsLog::insertPending([
            'client'   => SmsConfig::CLIENT,
            'purpose'  => $purpose,
            'receptor' => $receptor,
            'ref'      => $ref,
            'text'     => $storedText,
            'segments' => $segments,
            'provider' => $config->provider,
        ]);

        if ($id === 0) {
            // Unique key (client, purpose, receptor, ref) already exists:
            // never send the same message twice, return the existing row.
            $existing = SmsLog::findByDedupKey(SmsConfig::CLIENT, $purpose, $receptor, $ref);
            if ($existing === null) {
                // Defensive: duplicate insert reported but row not found.
                return ['id' => 0, 'status' => 'unknown', 'providerMsgId' => null];
            }

            $existingStatus = (string) $existing['status'];
            if ($existingStatus === 'failed' && $retry) {
                // Explicit retry request: reuse the SAME failed row.
                $id = (int) $existing['id'];
                SmsLog::reuseForRetry($id);
            } else {
                return [
                    'id'            => (int) $existing['id'],
                    'status'        => $existingStatus,
                    'providerMsgId' => $existing['provider_msg_id'] !== null
                        ? (string) $existing['provider_msg_id']
                        : null,
                ];
            }
        }

        $provider = self::provider();

        try {
            $result = $template !== null
                ? $provider->sendTemplate($receptor, $template, $tokens, $config->sender)
                : $provider->send($receptor, $text, $config->sender);
        } catch (\Throwable $e) {
            $error = Redactor::redactSecrets($e->getMessage(), [$config->apiKey]);
            SmsLog::markUnknown($id, null, null, mb_substr($error, 0, 500));
            return ['id' => $id, 'status' => 'unknown', 'providerMsgId' => null];
        }

        $redactedError = Redactor::redactSecrets((string) $result->error, [$config->apiKey]);

        if ($result->accepted && $result->messageId !== null) {
            SmsLog::markSent(
                $id,
                $result->messageId,
                $result->cost,
                $result->providerStatusCode,
                $result->providerStatusText
            );
            return ['id' => $id, 'status' => 'sent', 'providerMsgId' => $result->messageId];
        }

        if (KavenegarProvider::isInsufficientBalanceCode($result->providerStatusCode)) {
            BalanceMonitor::alertInsufficientBalance($redactedError, $config);
        }

        if ($result->definitive) {
            // Provider explicitly refused the message (it will never go out).
            SmsLog::markFailed(
                $id,
                $result->providerStatusCode,
                $result->providerStatusText,
                mb_substr($redactedError, 0, 500)
            );
            return ['id' => $id, 'status' => 'failed', 'providerMsgId' => null];
        }

        // Unknown outcome (timeout / network error / unparseable response):
        // the message MAY have been sent, so it is NEVER re-sent here.
        // Reconciliation (scripts/sms_cron.php reconcile) resolves the state.
        SmsLog::markUnknown(
            $id,
            $result->providerStatusCode,
            $result->providerStatusText,
            mb_substr($redactedError, 0, 500)
        );
        return ['id' => $id, 'status' => 'unknown', 'providerMsgId' => null];
    }

    /**
     * Validate + normalize all input. Any error here is thrown BEFORE a DB
     * row is created or the provider is called.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:string,2:string,3:string,4:?string,5:array<int,string>,6:bool}
     */
    private static function validateInput(array $input): array
    {
        $purpose = isset($input['purpose']) && is_scalar($input['purpose'])
            ? trim((string) $input['purpose'])
            : '';
        if ($purpose === '' || preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $purpose) !== 1) {
            throw new SmsInputException(
                'Invalid purpose: expected 1-64 chars matching [A-Za-z0-9_.-], got: "' . $purpose . '"'
            );
        }

        $text = isset($input['text']) && is_string($input['text']) ? $input['text'] : '';
        if (trim($text) === '') {
            throw new SmsInputException('Invalid text: message body must be a non-empty string.');
        }
        if (mb_strlen($text) > 1000) {
            throw new SmsInputException('Invalid text: message body must be at most 1000 characters.');
        }

        $ref = isset($input['ref']) && is_scalar($input['ref'])
            ? trim((string) $input['ref'])
            : '';
        if ($ref === '' || mb_strlen($ref) > 191) {
            throw new SmsInputException('Invalid ref: expected a non-empty opaque string of at most 191 characters.');
        }

        $template = null;
        if (array_key_exists('template', $input)) {
            if (!is_string($input['template'])) {
                throw new SmsInputException('Invalid template: expected a string template name.');
            }
            $template = trim($input['template']) !== '' ? trim($input['template']) : null;
        }

        $tokens = [];
        if (array_key_exists('tokens', $input)) {
            if (!is_array($input['tokens'])) {
                throw new SmsInputException('Invalid tokens: expected an array of up to 3 template tokens.');
            }
            if (array_is_list($input['tokens'])) {
                $tokens = array_values(array_slice(array_map(
                    static fn ($token) => is_scalar($token) ? (string) $token : '',
                    $input['tokens']
                ), 0, 3));
            } else {
                foreach (['token', 'token2', 'token3'] as $tokenKey) {
                    $value = $input['tokens'][$tokenKey] ?? null;
                    if (is_scalar($value) && (string) $value !== '') {
                        $tokens[] = (string) $value;
                    }
                }
            }
        }
        if ($template !== null && $tokens === []) {
            // Default: use the message text as the first template token.
            $tokens = [$text];
        }

        $retry = !empty($input['retry']);

        $receptor = SmsPhone::normalizeOrFail($input['receptor'] ?? null);

        return [$purpose, $receptor, $text, $ref, $template, $tokens, $retry];
    }
}
