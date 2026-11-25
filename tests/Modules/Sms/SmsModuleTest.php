<?php

declare(strict_types=1);

namespace Tests\Modules\Sms;

use App\Modules\Sms\BalanceMonitor;
use App\Modules\Sms\FakeProvider;
use App\Modules\Sms\KavenegarProvider;
use App\Modules\Sms\Reconciler;
use App\Modules\Sms\Redactor;
use App\Modules\Sms\SegmentCounter;
use App\Modules\Sms\Sms;
use App\Modules\Sms\SmsConfig;
use App\Modules\Sms\SmsConfigException;
use App\Modules\Sms\SmsInputException;
use App\Modules\Sms\SmsLog;
use App\Modules\Sms\SmsMessageStatus;
use App\Modules\Sms\SmsPhone;
use App\Modules\Sms\SmsProvider;
use App\Modules\Sms\SmsSendResult;
use Tests\TestCase;

final class SmsModuleTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    private string $tempDir = '';

    private const ENV_KEYS = [
        'APP_ENV',
        'SMS_PROVIDER',
        'KAVENEGAR_API_KEY',
        'KAVENEGAR_SENDER',
        'SMS_LOW_BALANCE_THRESHOLD',
        'SMS_REQUEST_TIMEOUT_MS',
        'SMS_RECONCILE_MAX_AGE_DAYS',
        'SMS_RECONCILE_MIN_AGE_SECONDS',
        'SMS_LOW_BALANCE_NOTIFY_MINUTES',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null;
        }

        $migrationSql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/015_sms_log.sql');
        if (is_string($migrationSql) && trim($migrationSql) !== '') {
            $this->db->exec($migrationSql);
        }

        $this->db->exec("DELETE FROM sms_log WHERE ref LIKE 'sms-test:%'");

        $this->tempDir = sys_get_temp_dir() . '/famo_sms_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0775, true);

        BalanceMonitor::reset();
        BalanceMonitor::setStatePath($this->tempDir . '/alerts.json');
        BalanceMonitor::setLogPath($this->tempDir . '/app.log');

        $this->setEnv('APP_ENV', 'testing');
        $this->setEnv('SMS_PROVIDER', 'fake');
        $this->setEnv('KAVENEGAR_API_KEY', 'test-secret-api-key-987654');
        $this->setEnv('KAVENEGAR_SENDER', '10004346');
        $this->setEnv('SMS_LOW_BALANCE_THRESHOLD', '500000');
        $this->setEnv('SMS_RECONCILE_MIN_AGE_SECONDS', '60');
        $this->setEnv('SMS_RECONCILE_MAX_AGE_DAYS', '3');
        $this->setEnv('SMS_LOW_BALANCE_NOTIFY_MINUTES', '360');

        Sms::setProvider(null);
    }

    protected function tearDown(): void
    {
        Sms::setProvider(null);
        BalanceMonitor::reset();
        $this->db->exec("DELETE FROM sms_log WHERE ref LIKE 'sms-test:%'");

        foreach ($this->savedEnv as $key => $val) {
            if ($val === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $this->setEnv($key, $val);
            }
        }

        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    public function testSegmentCounterGsm7ExtensionCharsAndUcs2Boundaries(): void
    {
        $this->assertSame(0, SegmentCounter::count(''));
        $this->assertTrue(SegmentCounter::isGsm7('Hello FAMO 123!'));
        $this->assertSame(1, SegmentCounter::count(str_repeat('A', 160)));
        $this->assertSame(2, SegmentCounter::count(str_repeat('A', 161)));
        $this->assertSame(2, SegmentCounter::count(str_repeat('A', 306)));
        $this->assertSame(3, SegmentCounter::count(str_repeat('A', 307)));

        // GSM-7 extension characters including Euro (€ = U+20AC) consume 2 septets each.
        $euro = "\u{20AC}";
        $this->assertTrue(SegmentCounter::isGsm7($euro . '^{}\\[~]|'));
        $this->assertSame(1, SegmentCounter::count(str_repeat('A', 158) . $euro));
        $this->assertSame(2, SegmentCounter::count(str_repeat('A', 159) . $euro));

        // Persian text, ZWNJ (U+200C), and astral emoji switch to UCS-2 (70/67 code points).
        $this->assertFalse(SegmentCounter::isGsm7('سلام'));
        $this->assertFalse(SegmentCounter::isGsm7("hi\u{200C}there"));
        $this->assertSame(1, SegmentCounter::count(str_repeat('س', 70)));
        $this->assertSame(2, SegmentCounter::count(str_repeat('س', 71)));
        $this->assertSame(2, SegmentCounter::count(str_repeat('س', 134)));
        $this->assertSame(3, SegmentCounter::count(str_repeat('س', 135)));

        // Astral-plane emoji counts as a single Unicode code point.
        $emoji = "\u{1F600}";
        $this->assertSame(1, SegmentCounter::count(str_repeat('A', 69) . $emoji));
        $this->assertSame(2, SegmentCounter::count(str_repeat('A', 70) . $emoji));
    }

    public function testSmsPhoneNormalizesPersianArabicDigitsAndInternationalPrefixes(): void
    {
        $this->assertSame('09123456789', SmsPhone::normalize('09123456789'));
        $this->assertSame('09123456789', SmsPhone::normalize('9123456789'));
        $this->assertSame('09123456789', SmsPhone::normalize('+989123456789'));
        $this->assertSame('09123456789', SmsPhone::normalize('+9809123456789'));
        $this->assertSame('09123456789', SmsPhone::normalize('00989123456789'));
        $this->assertSame('09123456789', SmsPhone::normalize('989123456789'));
        $this->assertSame('09123456789', SmsPhone::normalize('9809123456789'));

        // Persian digits (۰-۹) and Arabic-Indic digits (٠-٩) with formatting and directional marks.
        $this->assertSame('09123456789', SmsPhone::normalize("\u{200E}+۹۸ ۹۱۲-۳۴۵-۶۷۸۹"));
        $this->assertSame('09123456789', SmsPhone::normalize('٠٩١٢ ٣٤٥ ٦٧٨٩'));

        // Invalid numbers return null and throw SmsInputException from normalizeOrFail().
        foreach (['', '   ', '02112345678', '0912345678', '091234567890', 'abc', null, []] as $invalid) {
            $this->assertNull(SmsPhone::normalize($invalid));
        }

        $this->expectException(SmsInputException::class);
        SmsPhone::normalizeOrFail('02188887777');
    }

    public function testRedactorMasksSecretsAndDetectsOtpPurposes(): void
    {
        $secret = 'super-secret-kavenegar-key';
        $raw = "Request failed for https://api.kavenegar.com/v1/{$secret}/sms/send.json";
        $redacted = Redactor::redactSecrets($raw, [$secret]);

        $this->assertStringNotContainsString($secret, $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);

        $this->assertTrue(Redactor::isOtpPurpose('otp'));
        $this->assertTrue(Redactor::isOtpPurpose('otp.auth_2fa'));
        $this->assertTrue(Redactor::isOtpPurpose('OTP_PASSWORD_RESET'));
        $this->assertFalse(Redactor::isOtpPurpose('appointment.reminder'));
        $this->assertSame('[otp-redacted len=6]', Redactor::maskOtpText('482910'));
    }

    public function testSmsConfigValidationRules(): void
    {
        $this->setEnv('APP_ENV', 'testing');
        $this->setEnv('SMS_PROVIDER', null);
        $cfg = SmsConfig::fromEnv();
        $this->assertSame(SmsConfig::PROVIDER_FAKE, $cfg->provider);

        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('SMS_PROVIDER', null);
        $this->setEnv('KAVENEGAR_API_KEY', 'live-key');
        $this->setEnv('KAVENEGAR_SENDER', '10004346');
        $this->setEnv('SMS_LOW_BALANCE_THRESHOLD', '250000');
        $this->setEnv('SMS_REQUEST_TIMEOUT_MS', '200'); // clamped to min 1000
        $prodCfg = SmsConfig::fromEnv();
        $this->assertSame(SmsConfig::PROVIDER_KAVENEGAR, $prodCfg->provider);
        $this->assertSame(1000, $prodCfg->timeoutMs);
        $this->assertSame(250000.0, $prodCfg->lowBalanceThreshold);

        $this->setEnv('KAVENEGAR_API_KEY', '');
        try {
            SmsConfig::fromEnv();
            $this->fail('Expected SmsConfigException when KAVENEGAR_API_KEY is missing.');
        } catch (SmsConfigException $e) {
            $this->assertStringContainsString('KAVENEGAR_API_KEY', $e->getMessage());
        }

        $this->setEnv('KAVENEGAR_API_KEY', 'live-key');
        $this->setEnv('SMS_LOW_BALANCE_THRESHOLD', '');
        $this->expectException(SmsConfigException::class);
        SmsConfig::fromEnv();
    }

    public function testSmsSendHappyPathAndIdempotentDeduplication(): void
    {
        $fake = new FakeProvider([FakeProvider::okResult(1250.0)]);
        Sms::setProvider($fake);

        $payload = [
            'purpose'  => 'appointment.reminder',
            'receptor' => '+98 ۹۱۲ ۱۱۱ ۲۲۳۳',
            'text'     => 'یادآوری جلسه فردا',
            'ref'      => 'sms-test:dedup:1',
        ];

        $first = Sms::send($payload);
        $this->assertGreaterThan(0, $first['id']);
        $this->assertSame('sent', $first['status']);
        $this->assertNotNull($first['providerMsgId']);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('09121112233', $fake->calls[0]['receptor']);

        // Second call with the same tuple (even with a different phone representation) deduplicates.
        $second = Sms::send(array_merge($payload, ['receptor' => '09121112233']));
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('sent', $second['status']);
        $this->assertSame($first['providerMsgId'], $second['providerMsgId']);
        $this->assertCount(1, $fake->calls);

        $row = SmsLog::findById($first['id']);
        $this->assertNotNull($row);
        $this->assertSame('یادآوری جلسه فردا', $row['text']);
        $this->assertSame('1250.00', number_format((float) $row['provider_cost'], 2, '.', ''));
    }

    public function testSmsSendRetryReusesFailedRowAndNeverAutoRetriesUnknownRow(): void
    {
        $fake = new FakeProvider([
            FakeProvider::failedResult(402, 'Definitive rejection', true),
            FakeProvider::okResult(900.0),
        ]);
        Sms::setProvider($fake);

        $input = [
            'purpose'  => 'exam.share',
            'receptor' => '09123334455',
            'text'     => 'Your exam link is ready.',
            'ref'      => 'sms-test:retry:1',
        ];

        $first = Sms::send($input);
        $this->assertSame('failed', $first['status']);
        $this->assertNull($first['providerMsgId']);
        $this->assertCount(1, $fake->calls);

        // Without retry: true, existing failed row is returned with no extra provider call.
        $repeatNoRetry = Sms::send($input);
        $this->assertSame($first['id'], $repeatNoRetry['id']);
        $this->assertSame('failed', $repeatNoRetry['status']);
        $this->assertCount(1, $fake->calls);

        // With retry: true, the SAME row id is reused and sent.
        $retried = Sms::send(array_merge($input, ['retry' => true]));
        $this->assertSame($first['id'], $retried['id']);
        $this->assertSame('sent', $retried['status']);
        $this->assertNotNull($retried['providerMsgId']);
        $this->assertCount(2, $fake->calls);

        // Unknown outcome (non-definitive failure) is NEVER auto-retried even with retry: true.
        $unknownFake = new FakeProvider([
            FakeProvider::failedResult(409, 'Server busy', false),
            FakeProvider::okResult(900.0),
        ]);
        Sms::setProvider($unknownFake);

        $unknownInput = [
            'purpose'  => 'exam.share',
            'receptor' => '09123334455',
            'text'     => 'Timeout test',
            'ref'      => 'sms-test:unknown:1',
        ];

        $unknownFirst = Sms::send($unknownInput);
        $this->assertSame('unknown', $unknownFirst['status']);
        $this->assertCount(1, $unknownFake->calls);

        $unknownRetry = Sms::send(array_merge($unknownInput, ['retry' => true]));
        $this->assertSame($unknownFirst['id'], $unknownRetry['id']);
        $this->assertSame('unknown', $unknownRetry['status']);
        $this->assertCount(1, $unknownFake->calls);
    }

    public function testSmsSendRedactsOtpTextAndStripsApiKeyFromProviderExceptions(): void
    {
        $fake = new FakeProvider([FakeProvider::okResult(500.0)]);
        Sms::setProvider($fake);

        $otpRes = Sms::send([
            'purpose'  => 'otp.auth_2fa',
            'receptor' => '09125556677',
            'text'     => '739104',
            'ref'      => 'sms-test:otp:1',
            'template' => 'famo-otp',
        ]);

        $this->assertSame('sent', $otpRes['status']);
        $this->assertSame('sendTemplate', $fake->calls[0]['op']);
        $this->assertSame(['739104'], $fake->calls[0]['tokens']);

        $otpRow = SmsLog::findById($otpRes['id']);
        $this->assertNotNull($otpRow);
        $this->assertSame('[otp-redacted len=6]', $otpRow['text']);
        $this->assertStringNotContainsString('739104', (string) $otpRow['text']);

        // Throwing provider whose message accidentally includes KAVENEGAR_API_KEY must not throw and must redact key.
        $throwingProvider = new class () implements SmsProvider {
            public function send(string $receptor, string $text, string $sender): SmsSendResult
            {
                throw new \RuntimeException('cURL failed on https://api.kavenegar.com/v1/test-secret-api-key-987654/sms/send.json');
            }

            public function sendTemplate(string $receptor, string $template, array $tokens, string $sender): SmsSendResult
            {
                throw new \RuntimeException('not used');
            }

            public function fetchStatuses(array $messageIds): array
            {
                return [];
            }

            public function getBalance(): ?float
            {
                return null;
            }
        };

        Sms::setProvider($throwingProvider);
        $errRes = Sms::send([
            'purpose'  => 'appointment.reminder',
            'receptor' => '09125556677',
            'text'     => 'Reminder',
            'ref'      => 'sms-test:exception:1',
        ]);

        $this->assertSame('unknown', $errRes['status']);
        $errRow = SmsLog::findById($errRes['id']);
        $this->assertNotNull($errRow);
        $this->assertStringNotContainsString('test-secret-api-key-987654', (string) $errRow['error']);
        $this->assertStringContainsString('[REDACTED]', (string) $errRow['error']);
    }

    public function testSmsSendRejectsInvalidInputBeforeDbWriteOrProviderCall(): void
    {
        $fake = new FakeProvider();
        Sms::setProvider($fake);

        try {
            Sms::send([
                'purpose'  => 'bad purpose with spaces!',
                'receptor' => '09121112233',
                'text'     => 'Hello',
                'ref'      => 'sms-test:invalid:1',
            ]);
            $this->fail('Expected SmsInputException for invalid purpose.');
        } catch (SmsInputException) {
            // expected
        }

        $this->assertCount(0, $fake->calls);
        $this->assertNull(SmsLog::findByDedupKey(SmsConfig::CLIENT, 'bad purpose with spaces!', '09121112233', 'sms-test:invalid:1'));
    }

    public function testReconcilerAppliesDeliveredAndFailedStatusesAndListsUnknownWithoutMsgId(): void
    {
        $sendFake = new FakeProvider([
            new SmsSendResult(true, 'msg-deliv-1', 1100.0, 1, 'Queued', null, true),
            new SmsSendResult(true, 'msg-fail-2', 1100.0, 1, 'Queued', null, true),
            FakeProvider::failedResult(409, 'Timeout', false),
        ]);
        Sms::setProvider($sendFake);

        $r1 = Sms::send([
            'purpose'  => 'reconcile.test',
            'receptor' => '09121000001',
            'text'     => 'Message 1',
            'ref'      => 'sms-test:rec:1',
        ]);
        $r2 = Sms::send([
            'purpose'  => 'reconcile.test',
            'receptor' => '09121000002',
            'text'     => 'Message 2',
            'ref'      => 'sms-test:rec:2',
        ]);
        $r3 = Sms::send([
            'purpose'  => 'reconcile.test',
            'receptor' => '09121000003',
            'text'     => 'Message 3',
            'ref'      => 'sms-test:rec:3',
        ]);

        // Backdate created_at by 5 minutes (UTC) so rows are eligible for reconciliation.
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 300);
        $stmt = $this->db->prepare('UPDATE sms_log SET created_at = :ts WHERE id IN (:id1, :id2, :id3)');
        $stmt->execute([
            'ts'  => $pastUtc,
            'id1' => $r1['id'],
            'id2' => $r2['id'],
            'id3' => $r3['id'],
        ]);

        $pollFake = new FakeProvider([], [
            'msg-deliv-1' => [new SmsMessageStatus('msg-deliv-1', 'delivered', 10, 'Delivered', null, null)],
            'msg-fail-2'  => [new SmsMessageStatus('msg-fail-2', 'failed', 11, 'Not delivered', 0.0, null)],
        ]);

        $stats = Reconciler::reconcile(50, $pollFake);
        $this->assertGreaterThanOrEqual(2, $stats['scanned']);
        $this->assertGreaterThanOrEqual(2, $stats['updated']);
        $this->assertGreaterThanOrEqual(1, $stats['delivered']);
        $this->assertGreaterThanOrEqual(1, $stats['failed']);

        $row1 = SmsLog::findById($r1['id']);
        $this->assertNotNull($row1);
        $this->assertSame('delivered', $row1['status']);
        $this->assertNotNull($row1['delivered_at']);
        // COALESCE preserves previously recorded provider_cost when status reply omits cost.
        $this->assertSame('1100.00', number_format((float) $row1['provider_cost'], 2, '.', ''));

        $row2 = SmsLog::findById($r2['id']);
        $this->assertNotNull($row2);
        $this->assertSame('failed', $row2['status']);
        $this->assertNull($row2['delivered_at']);

        // Row 3 has status=unknown and provider_msg_id=NULL -> appears in manual review list.
        $unknownList = Reconciler::listUnknownWithoutMessageId(100);
        $unknownIds = array_map(static fn (array $r): int => (int) $r['id'], $unknownList);
        $this->assertContains($r3['id'], $unknownIds);
    }

    public function testBalanceMonitorRateLimitingCode411AlertAndStatusMap(): void
    {
        $alerts = [];
        BalanceMonitor::setNotifier(static function (string $message, array $context) use (&$alerts): void {
            $alerts[] = ['message' => $message, 'context' => $context];
        });

        $lowBalanceFake = new FakeProvider([], [], 150000.0);
        $firstCheck = BalanceMonitor::checkBalance($lowBalanceFake);
        $this->assertTrue($firstCheck['below_threshold']);
        $this->assertTrue($firstCheck['alerted']);
        $this->assertCount(1, $alerts);

        // Immediate second check is suppressed by the cooldown window.
        $secondCheck = BalanceMonitor::checkBalance($lowBalanceFake);
        $this->assertTrue($secondCheck['below_threshold']);
        $this->assertFalse($secondCheck['alerted']);
        $this->assertCount(1, $alerts);

        // Send-time Kavenegar status 411 triggers insufficient-balance alert with secret redaction.
        $code411Fake = new FakeProvider([
            FakeProvider::failedResult(411, 'Insufficient credit for key test-secret-api-key-987654', false),
        ]);
        Sms::setProvider($code411Fake);
        $res = Sms::send([
            'purpose'  => 'billing.alert',
            'receptor' => '09129990000',
            'text'     => 'Test 411',
            'ref'      => 'sms-test:code411:1',
        ]);
        $this->assertSame('unknown', $res['status']);
        $this->assertCount(2, $alerts);
        $this->assertStringNotContainsString('test-secret-api-key-987654', $alerts[1]['message']);
        $this->assertStringContainsString('[REDACTED]', $alerts[1]['message']);

        // Verify Kavenegar delivery status mapping table.
        $this->assertSame('sent', KavenegarProvider::mapMessageStatus(1));
        $this->assertSame('sent', KavenegarProvider::mapMessageStatus(2));
        $this->assertSame('sent', KavenegarProvider::mapMessageStatus(4));
        $this->assertSame('sent', KavenegarProvider::mapMessageStatus(5));
        $this->assertSame('delivered', KavenegarProvider::mapMessageStatus(10));
        $this->assertSame('failed', KavenegarProvider::mapMessageStatus(6));
        $this->assertSame('failed', KavenegarProvider::mapMessageStatus(11));
        $this->assertSame('failed', KavenegarProvider::mapMessageStatus(13));
        $this->assertSame('failed', KavenegarProvider::mapMessageStatus(14));
        $this->assertSame('unknown', KavenegarProvider::mapMessageStatus(100));
        $this->assertSame('unknown', KavenegarProvider::mapMessageStatus(null));
        $this->assertSame('unknown', KavenegarProvider::mapMessageStatus(999));
    }
}
