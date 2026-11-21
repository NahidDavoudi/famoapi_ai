<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ApiException;
use App\Core\Cache;
use App\Core\Database;
use App\Core\RedisClient;
use App\Modules\Admissions\AdmissionService;
use PDO;
use PHPUnit\Framework\TestCase;

class AdmissionServiceTest extends TestCase
{
    private PDO $db;
    private AdmissionService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Database::setConnection($this->db);

        RedisClient::useInMemoryDriver(true);
        Cache::configure('', true);

        $this->db->exec('CREATE TABLE admissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_name TEXT NOT NULL,
            jalali_year INTEGER NOT NULL,
            exam_type TEXT NOT NULL DEFAULT "konkur",
            field TEXT NULL,
            result_title TEXT NOT NULL,
            rank_value TEXT NULL,
            quote TEXT NULL,
            image_url TEXT NULL,
            display_order INTEGER NOT NULL DEFAULT 0,
            is_featured INTEGER NOT NULL DEFAULT 0,
            consent_confirmed INTEGER NOT NULL DEFAULT 0,
            consent_confirmed_at TEXT NULL,
            consent_confirmed_by INTEGER NULL,
            status TEXT NOT NULL DEFAULT "draft",
            published_at TEXT NULL,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->service = new AdmissionService();
    }

    protected function tearDown(): void
    {
        RedisClient::reset();
        Database::reset();
        parent::tearDown();
    }

    public function testCreateDraftWithoutConsentSucceedsAndCannotPublishWithoutConsent(): void
    {
        $draft = $this->service->create([
            'student_name'      => 'سارا احمدی',
            'jalali_year'       => 1403,
            'exam_type'         => 'konkur',
            'field'             => 'تجربی',
            'result_title'      => 'پزشکی دانشگاه علوم پزشکی تهران',
            'rank_value'        => 'رتبه ۱۴۲ منطقه ۱',
            'status'            => 'draft',
            'consent_confirmed' => false,
        ], 7);

        self::assertSame('draft', $draft['status']);
        self::assertFalse($draft['consent_confirmed']);
        self::assertNull($draft['consent_confirmed_at']);
        self::assertNull($draft['consent_confirmed_by']);
        self::assertNull($draft['published_at']);
        self::assertSame(7, $draft['created_by']);

        try {
            $this->service->create([
                'student_name'      => 'علی رضایی',
                'jalali_year'       => 1403,
                'result_title'      => 'مهندسی کامپیوتر دانشگاه شریف',
                'status'            => 'published',
                'consent_confirmed' => false,
            ], 7);
            self::fail('Expected publishing without consent_confirmed to be rejected');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('CONSENT_REQUIRED', $e->getErrorCode());
        }
    }

    public function testCreatePublishedWithConsentSetsAuditTimestampsAndFlushesPublicCache(): void
    {
        Cache::remember('public', 'http_response:/api/v1/public/admissions', 300, static fn () => '{"cached":true}');
        self::assertNotNull(RedisClient::get('cache:public:' . md5('http_response:/api/v1/public/admissions')));

        $created = $this->service->create([
            'student_name'      => 'مهدی کریمی',
            'jalali_year'       => 1403,
            'exam_type'         => 'konkur',
            'field'             => 'ریاضی',
            'result_title'      => 'مهندسی برق دانشگاه صنعتی شریف',
            'rank_value'        => 'رتبه ۴۵ کشوری',
            'is_featured'       => true,
            'display_order'     => 1,
            'consent_confirmed' => true,
            'status'            => 'published',
        ], 12);

        self::assertSame('published', $created['status']);
        self::assertTrue($created['consent_confirmed']);
        self::assertNotNull($created['consent_confirmed_at']);
        self::assertSame(12, $created['consent_confirmed_by']);
        self::assertNotNull($created['published_at']);
        self::assertTrue($created['is_featured']);

        // Public cache group must be flushed on admin mutation
        self::assertNull(RedisClient::get('cache:public:' . md5('http_response:/api/v1/public/admissions')));
    }

    public function testConsentGateEnforcedOnUpdateAndStatusTransitions(): void
    {
        $draft = $this->service->create([
            'student_name' => 'نگار محمدی',
            'jalali_year'  => 1402,
            'result_title' => 'دندانپزشکی دانشگاه شهید بهشتی',
        ], 3);

        // Cannot publish draft without consent
        try {
            $this->service->updateStatus($draft['id'], 'published', null, 3);
            self::fail('Expected publishing draft without consent to fail');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('CONSENT_REQUIRED', $e->getErrorCode());
        }

        // Confirming consent + publishing together succeeds
        $published = $this->service->updateStatus($draft['id'], 'published', true, 5);
        self::assertSame('published', $published['status']);
        self::assertTrue($published['consent_confirmed']);
        self::assertSame(5, $published['consent_confirmed_by']);
        self::assertNotNull($published['published_at']);

        // Revoking consent while keeping status = published fails
        try {
            $this->service->update($draft['id'], ['consent_confirmed' => false], 5);
            self::fail('Expected revoking consent while published to fail');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('CONSENT_REQUIRED', $e->getErrorCode());
        }

        // Revoking consent together with moving back to draft succeeds and clears timestamps
        $reverted = $this->service->updateStatus($draft['id'], 'draft', false, 5);
        self::assertSame('draft', $reverted['status']);
        self::assertFalse($reverted['consent_confirmed']);
        self::assertNull($reverted['consent_confirmed_at']);
        self::assertNull($reverted['consent_confirmed_by']);
        self::assertNull($reverted['published_at']);
    }

    public function testPublicListingRequiresPublishedAndConsentSupportsYearFilterAndGrouping(): void
    {
        $this->service->create([
            'student_name'      => 'قبولی ۱۴۰۳ اول',
            'jalali_year'       => 1403,
            'exam_type'         => 'konkur',
            'result_title'      => 'پزشکی تهران',
            'display_order'     => 1,
            'consent_confirmed' => true,
            'status'            => 'published',
        ], 1);

        $this->service->create([
            'student_name'      => 'قبولی ۱۴۰۲ اول',
            'jalali_year'       => 1402,
            'exam_type'         => 'tizhooshan',
            'result_title'      => 'تیزهوشان علامه حلی',
            'display_order'     => 1,
            'consent_confirmed' => true,
            'status'            => 'published',
        ], 1);

        // Draft must never appear publicly
        $this->service->create([
            'student_name'      => 'پیش‌نویس محرمانه',
            'jalali_year'       => 1403,
            'result_title'      => 'پزشکی شیراز',
            'consent_confirmed' => true,
            'status'            => 'draft',
        ], 1);

        // Even if a rogue DB row has status=published with consent_confirmed=0, public query must exclude it
        $this->db->exec("INSERT INTO admissions (student_name, jalali_year, exam_type, result_title, consent_confirmed, status)
                         VALUES ('بدون رضایت', 1403, 'konkur', 'حقوق تهران', 0, 'published')");

        // Flat public list
        $public = $this->service->listPublic([], 1, 20, false);
        self::assertSame([1403, 1402], $public['data']['years']);
        self::assertCount(2, $public['data']['items']);
        self::assertSame(2, $public['pagination']['total']);
        self::assertArrayNotHasKey('consent_confirmed', $public['data']['items'][0]);
        self::assertArrayNotHasKey('created_by', $public['data']['items'][0]);

        // Year filter
        $only1402 = $this->service->listPublic(['year' => '1402'], 1, 20, false);
        self::assertCount(1, $only1402['data']['items']);
        self::assertSame('قبولی ۱۴۰۲ اول', $only1402['data']['items'][0]['student_name']);

        // Grouped by year
        $grouped = $this->service->listPublic([], 1, 20, true);
        self::assertNull($grouped['pagination']);
        self::assertSame([1403, 1402], $grouped['data']['years']);
        self::assertCount(2, $grouped['data']['groups']);
        self::assertSame(1403, $grouped['data']['groups'][0]['jalali_year']);
        self::assertSame(1, $grouped['data']['groups'][0]['count']);
        self::assertSame(1402, $grouped['data']['groups'][1]['jalali_year']);
    }

    public function testAdminListSortingAndDeleteLifecycle(): void
    {
        $a = $this->service->create([
            'student_name'      => 'بهمن',
            'jalali_year'       => 1401,
            'result_title'      => 'معماری تهران',
            'display_order'     => 2,
            'consent_confirmed' => true,
            'status'            => 'published',
        ], 1);

        $b = $this->service->create([
            'student_name'      => 'آرش',
            'jalali_year'       => 1403,
            'result_title'      => 'پزشکی ایران',
            'display_order'     => 1,
            'consent_confirmed' => false,
            'status'            => 'draft',
        ], 1);

        // Sort by jalali_year ASC; unknown sort key falls back safely without SQL error
        $asc = $this->service->list([], 1, 10, ['sort' => 'jalali_year', 'order' => 'asc']);
        self::assertSame($a['id'], $asc['items'][0]['id']);
        self::assertSame('jalali_year', $asc['pagination']['sort']);
        self::assertSame('asc', $asc['pagination']['order']);

        $fallback = $this->service->list([], 1, 10, ['sort' => 'DROP TABLE admissions', 'order' => 'invalid']);
        self::assertSame($b['id'], $fallback['items'][0]['id']);
        self::assertSame('jalali_year', $fallback['pagination']['sort']);
        self::assertSame('desc', $fallback['pagination']['order']);

        // Reject invalid image extension
        try {
            $this->service->create([
                'student_name' => 'تست فایل',
                'jalali_year'  => 1403,
                'result_title' => 'ریاضی شریف',
                'image'        => ['name' => 'shell.php', 'tmp_name' => '/tmp/nonexistent', 'error' => 0],
            ], 1);
            self::fail('Expected invalid image extension to be rejected');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('UPLOAD_ERROR', $e->getErrorCode());
        }

        // Delete removes record and subsequent get throws 404
        $this->service->delete($a['id']);
        try {
            $this->service->get($a['id']);
            self::fail('Expected deleted admission to return 404');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getHttpStatus());
            self::assertSame('NOT_FOUND', $e->getErrorCode());
        }
    }
}
