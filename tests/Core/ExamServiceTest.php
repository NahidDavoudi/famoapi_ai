<?php

namespace Tests\Core;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\EnvValidator;
use App\Core\SmsPatternSettings;
use App\Modules\Exams\ExamService;
use PDO;
use PHPUnit\Framework\TestCase;

class ExamServiceTest extends TestCase
{
    private PDO $db;
    private ExamService $service;
    /** @var array<int, array{reason: string, context: array<string, mixed>}> */
    private array $loggedRejections = [];
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'PUBLIC_SITE_URL' => $_ENV['PUBLIC_SITE_URL'] ?? null,
            'API_URL' => $_ENV['API_URL'] ?? null,
        ];

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Database::setConnection($this->db);

        $this->db->exec('CREATE TABLE students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            grade INTEGER NULL,
            field TEXT NULL,
            phone TEXT NULL,
            national_id TEXT NULL
        )');

        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            full_name TEXT NULL,
            role TEXT NOT NULL,
            linked_id INTEGER NULL
        )');

        $this->db->exec('CREATE TABLE instructors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');

        $this->db->exec('CREATE TABLE exam_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            exam_date TEXT NOT NULL,
            subject TEXT NOT NULL,
            chapter TEXT,
            total_q INTEGER NOT NULL,
            correct INTEGER NOT NULL,
            wrong INTEGER NOT NULL,
            skipped INTEGER NOT NULL,
            percentage REAL NOT NULL DEFAULT 60.0
        )');

        $this->db->exec('CREATE TABLE exam_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            exam_date TEXT NOT NULL,
            teacher_user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec('CREATE TABLE exam_result_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            student_id INTEGER NOT NULL,
            exam_date TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            revoked_at TEXT NULL,
            sms_sent_at TEXT NULL,
            created_by INTEGER NULL,
            last_viewed_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->loggedRejections = [];
        $this->service = new ExamService(
            null,
            function (string $reason, array $context): void {
                $this->loggedRejections[] = ['reason' => $reason, 'context' => $context];
            }
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $val) {
            if ($val === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
        Database::reset();
        parent::tearDown();
    }

    public function testBulkSaveReplacesPriorResultsForTheStudentAndDate(): void
    {
        $subjects = [[
            'subject' => 'Math', 'total_q' => 10, 'correct' => 5, 'wrong' => 2, 'skipped' => 3,
        ]];
        self::assertSame(['inserted' => 1], $this->service->save(17, '2026-09-01', $subjects));
        self::assertSame(['inserted' => 1], $this->service->save(17, '2026-09-01', $subjects));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM exam_results')->fetchColumn());
    }

    public function testNegativeExamCountsAreRejectedBeforeWriting(): void
    {
        try {
            $this->service->save(17, '2026-09-01', [[
                'subject' => 'Math', 'total_q' => 10, 'correct' => 8, 'wrong' => -1, 'skipped' => 0,
            ]]);
            self::fail('Expected invalid negative exam counts to be rejected');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->getHttpStatus());
            self::assertSame('VALIDATION_ERROR', $exception->getErrorCode());
        }

        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM exam_results')->fetchColumn());
    }

    public function testInvalidDatesAndNonArrayRowsAreRejected(): void
    {
        foreach ([
            ['date' => '2026-02-30', 'subjects' => [['subject' => 'Math', 'total_q' => 1]]],
            ['date' => '2026-09-01', 'subjects' => ['bad row']],
        ] as $case) {
            try {
                $this->service->save(17, $case['date'], $case['subjects']);
                self::fail('Expected invalid exam input to be rejected');
            } catch (ApiException $exception) {
                self::assertSame(422, $exception->getHttpStatus());
            }
        }
    }

    public function testCreateShareLinkUsesPublicSiteUrlSpaRouteAndRequiresEnvInProduction(): void
    {
        $this->seedStudentAndExam(17, '2026-09-01');

        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $_ENV['PUBLIC_SITE_URL'] = 'https://famoacademy.ir/';
        $_SERVER['PUBLIC_SITE_URL'] = 'https://famoacademy.ir/';

        $created = $this->service->createShareLink(17, '2026-09-01', 1);
        $token = $created['token'];

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame('/r/' . $token, $created['share_path']);
        self::assertSame('/api/v1/public/exam-results/' . $token, $created['api_path']);
        self::assertSame('https://famoacademy.ir/r/' . $token, $created['url']);
        self::assertSame($created['url'], $created['share_url']);

        // Only SHA-256 token hash is persisted in exam_result_links
        $storedHash = (string) $this->db->query('SELECT token_hash FROM exam_result_links LIMIT 1')->fetchColumn();
        self::assertSame(hash('sha256', $token), $storedHash);
        self::assertNotSame($token, $storedHash);

        // Production fails loudly if PUBLIC_SITE_URL is missing
        unset($_ENV['PUBLIC_SITE_URL'], $_SERVER['PUBLIC_SITE_URL']);
        try {
            $this->service->createShareLink(17, '2026-09-01', 1);
            self::fail('Expected missing PUBLIC_SITE_URL in production to fail loudly');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->getHttpStatus());
            self::assertSame('ENV_CONFIGURATION_ERROR', $exception->getErrorCode());
        }
    }

    public function testPublicSharedResultIsMultiUseAndLogsDistinctInternal404Reasons(): void
    {
        $this->seedStudentAndExam(17, '2026-09-01');
        $_ENV['APP_ENV'] = 'production';
        $_ENV['PUBLIC_SITE_URL'] = 'https://famoacademy.ir';

        $created = $this->service->createShareLink(17, '2026-09-01', 1);
        $token = $created['token'];

        // Multi-use: subsequent fetches continue to work and never revoke the link
        $first = $this->service->getPublicSharedResult($token);
        $second = $this->service->getPublicSharedResult($token);
        self::assertSame('دانش‌آموز تست', $first['student_name']);
        self::assertSame($first['avg_percentage'], $second['avg_percentage']);
        self::assertArrayNotHasKey('national_id', $first['student']);
        self::assertArrayNotHasKey('phone', $first['student']);
        $linkRow = $this->db->query('SELECT revoked_at, last_viewed_at FROM exam_result_links WHERE id = 1')->fetch();
        self::assertNull($linkRow['revoked_at']);
        self::assertNotNull($linkRow['last_viewed_at']);

        // 1. Malformed token -> uniform 404 + internal 'malformed' reason
        $this->assertUniformNotFoundAndReason('bad-token', 'malformed');

        // 2. Unknown token -> uniform 404 + internal 'unknown_token' reason
        $this->assertUniformNotFoundAndReason('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq', 'unknown_token');

        // 3. Expired link -> uniform 404 + internal 'expired' reason
        $this->db->exec("UPDATE exam_result_links SET expires_at = '2020-01-01 00:00:00' WHERE id = 1");
        $this->assertUniformNotFoundAndReason($token, 'expired');

        // 4. Revoked link -> uniform 404 + internal 'revoked' reason
        $this->db->exec("UPDATE exam_result_links SET expires_at = '2099-01-01 00:00:00', revoked_at = '2026-09-02 10:00:00' WHERE id = 1");
        $this->assertUniformNotFoundAndReason($token, 'revoked');

        // 5. Missing underlying exam results -> uniform 404 + internal 'result_missing' reason
        $this->db->exec('UPDATE exam_result_links SET revoked_at = NULL WHERE id = 1');
        $this->db->exec('DELETE FROM exam_results WHERE student_id = 17');
        $this->assertUniformNotFoundAndReason($token, 'result_missing');
    }

    public function testExamSmsPatternHasNonUrlSeparatorAfterLinkPlaceholder(): void
    {
        $patternText = SmsPatternSettings::DEFINITIONS[SmsPatternSettings::KEY_EXAM_RESULT]['default_pattern_text'];
        self::assertMatchesRegularExpression('/\{2\}(?:\s|$)/u', $patternText);
        $rendered = SmsPatternSettings::renderExamResultMessage(
            'علی رضایی',
            '1405/08/03',
            'https://famoacademy.ir/r/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abc'
        );
        self::assertStringContainsString("https://famoacademy.ir/r/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abc\n", $rendered);
        self::assertStringContainsString('PUBLIC_SITE_URL', implode(',', array_keys(['PUBLIC_SITE_URL' => EnvValidator::requirePublicSiteUrl()])));
    }

    private function seedStudentAndExam(int $studentId, string $examDate): void
    {
        $stmt = $this->db->prepare("INSERT INTO students (id, name, grade, field, phone, national_id) VALUES (?, 'دانش‌آموز تست', 12, 'تجربی', '09120000000', '1234567890')");
        $stmt->execute([$studentId]);

        $resStmt = $this->db->prepare("INSERT INTO exam_results (student_id, exam_date, subject, chapter, total_q, correct, wrong, skipped, percentage) VALUES (?, ?, 'فیزیک', 'سینماتیک', 20, 14, 2, 4, 66.7)");
        $resStmt->execute([$studentId, $examDate]);
    }

    private function assertUniformNotFoundAndReason(string $token, string $expectedReason): void
    {
        $beforeCount = count($this->loggedRejections);
        try {
            $this->service->getPublicSharedResult($token);
            self::fail("Expected uniform 404 for {$expectedReason}");
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->getHttpStatus());
            self::assertSame('NOT_FOUND', $exception->getErrorCode());
            self::assertSame('لینک نتیجه آزمون معتبر نیست یا منقضی شده است', $exception->getMessage());
            self::assertStringNotContainsString($expectedReason, $exception->getMessage());
        }

        self::assertCount($beforeCount + 1, $this->loggedRejections);
        self::assertSame($expectedReason, $this->loggedRejections[$beforeCount]['reason']);
    }
}
