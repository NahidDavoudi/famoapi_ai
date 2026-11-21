<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ApiException;
use App\Core\Cache;
use App\Core\Database;
use App\Core\RedisClient;
use App\Modules\Testimonials\TestimonialService;
use PDO;
use PHPUnit\Framework\TestCase;

class TestimonialServiceTest extends TestCase
{
    private PDO $db;
    private TestimonialService $service;
    private mixed $jwtSecretBackup = null;

    protected function setUp(): void
    {
        $this->jwtSecretBackup = $_ENV['JWT_SECRET'] ?? null;
        $_ENV['JWT_SECRET'] = 'unit-test-jwt-secret-key-for-hmac-dedup-0123456789abcdef';

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Database::setConnection($this->db);

        RedisClient::useInMemoryDriver(true);
        RedisClient::setTestNow(1_700_000_000);
        Cache::configure('', true);

        $this->db->exec('CREATE TABLE courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');

        $this->db->exec('CREATE TABLE instructors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');

        $this->db->exec('CREATE TABLE testimonials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_name TEXT NOT NULL,
            author_role TEXT NOT NULL DEFAULT "student",
            author_meta TEXT NULL,
            rating INTEGER NOT NULL DEFAULT 5,
            content TEXT NOT NULL,
            course_id INTEGER NULL,
            instructor_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT "pending",
            is_featured INTEGER NOT NULL DEFAULT 0,
            display_order INTEGER NOT NULL DEFAULT 0,
            source TEXT NOT NULL DEFAULT "public_form",
            moderated_by INTEGER NULL,
            moderated_at TEXT NULL,
            rejection_reason TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->db->exec("INSERT INTO courses (id, name) VALUES (10, 'دوره جامع فیزیک کنکور')");
        $this->db->exec("INSERT INTO instructors (id, name) VALUES (20, 'استاد حسینی')");

        $this->service = new TestimonialService();
    }

    protected function tearDown(): void
    {
        if ($this->jwtSecretBackup === null) {
            unset($_ENV['JWT_SECRET']);
        } else {
            $_ENV['JWT_SECRET'] = $this->jwtSecretBackup;
        }
        RedisClient::reset();
        Database::reset();
        parent::tearDown();
    }

    public function testPublicSubmissionAlwaysStartsPendingIgnoresPrivilegedFieldsAndHoneypot(): void
    {
        // Honeypot silently accepted without persisting
        $botReply = $this->service->submitPublic([
            'author_name' => 'Bot User',
            'rating'      => 5,
            'content'     => 'این یک پیام تست ربات اسپم برای هانی‌پات است',
            'website'     => 'https://spam.example',
        ], '203.0.113.10');

        self::assertTrue($botReply['accepted']);
        self::assertNull($botReply['id']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM testimonials')->fetchColumn());

        // Real submission with injected admin-only fields must ignore them
        $created = $this->service->submitPublic([
            'author_name'   => 'زهرا نوری',
            'author_role'   => 'parent',
            'author_meta'   => 'ولی دانش‌آموز دوازدهم تجربی',
            'rating'        => 5,
            'content'       => 'پیگیری مشاورها و برنامه‌ریزی هفتگی فامو واقعاً عالی و منظم بود.',
            'course_id'     => 10,
            'instructor_id' => 20,
            'status'        => 'approved',
            'is_featured'   => true,
            'display_order' => 1,
            'author_phone'  => '09120000000',
        ], '203.0.113.11');

        self::assertSame('pending', $created['status']);
        self::assertIsInt($created['id']);

        $row = $this->db->query('SELECT * FROM testimonials WHERE id = ' . (int) $created['id'])->fetch();
        self::assertSame('pending', $row['status']);
        self::assertSame('public_form', $row['source']);
        self::assertNull($row['course_id']);
        self::assertNull($row['instructor_id']);
        self::assertSame(0, (int) $row['is_featured']);
        self::assertSame(0, (int) $row['display_order']);
    }

    public function testRedisDedupUsesServerKeyedHmacAndExpiresAfterTtl(): void
    {
        $ip = '198.51.100.42';
        $content = 'کلاس‌های ریاضی و رفع اشکال هفتگی خیلی کمک کرد به پیشرفت درصدها.';

        // Verify dedup key is HMAC-SHA256 with server key, not plain SHA-256
        $dedupKey = TestimonialService::buildDedupRedisKey($ip, $content);
        $plainSha = 'testimonial:dedup:' . hash('sha256', strtolower($ip) . '|' . mb_strtolower($content));
        self::assertStringStartsWith('testimonial:dedup:', $dedupKey);
        self::assertNotSame($plainSha, $dedupKey);

        // First submission succeeds and sets TTL in Redis
        $this->service->submitPublic([
            'author_name' => 'محمد علوی',
            'rating'      => 5,
            'content'     => $content,
        ], $ip);

        self::assertSame('1', RedisClient::get($dedupKey));
        self::assertSame(TestimonialService::DEDUP_TTL_SECONDS, RedisClient::ttl($dedupKey));

        // Duplicate from same IP with extra whitespace is blocked with 409
        try {
            $this->service->submitPublic([
                'author_name' => 'محمد علوی',
                'rating'      => 5,
                'content'     => "  کلاس‌های   ریاضی و رفع اشکال هفتگی خیلی کمک کرد به پیشرفت درصدها.  ",
            ], $ip);
            self::fail('Expected duplicate submission within TTL to return 409');
        } catch (ApiException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('DUPLICATE_TESTIMONIAL', $e->getErrorCode());
        }

        // Different IP with same text is allowed
        $otherIp = $this->service->submitPublic([
            'author_name' => 'رضا قاسمی',
            'rating'      => 4,
            'content'     => $content,
        ], '198.51.100.99');
        self::assertSame('pending', $otherIp['status']);

        // After TTL expires, same IP can submit again
        RedisClient::setTestNow(1_700_000_000 + TestimonialService::DEDUP_TTL_SECONDS + 1);
        $afterTtl = $this->service->submitPublic([
            'author_name' => 'محمد علوی',
            'rating'      => 5,
            'content'     => $content,
        ], $ip);
        self::assertSame('pending', $afterTtl['status']);
    }

    public function testAdminDirectCreateDefaultsToApprovedAndValidatesForeignKeys(): void
    {
        Cache::remember('public', 'http_response:/api/v1/public/testimonials', 300, static fn () => '{"cached":true}');

        $created = $this->service->createByAdmin([
            'author_name'   => 'پویا رضایی',
            'author_role'   => 'graduate',
            'author_meta'   => 'قبولی پزشکی تهران ۱۴۰۲',
            'rating'        => 5,
            'content'       => 'بهترین تجربه آموزشی من در سال کنکور با اساتید مجموعه فامو بود.',
            'course_id'     => 10,
            'instructor_id' => 20,
            'is_featured'   => true,
        ], 9);

        self::assertSame('approved', $created['status']);
        self::assertSame('admin', $created['source']);
        self::assertSame(9, $created['moderated_by']);
        self::assertNotNull($created['moderated_at']);
        self::assertSame(10, $created['course_id']);
        self::assertSame('دوره جامع فیزیک کنکور', $created['course_name']);
        self::assertSame(20, $created['instructor_id']);
        self::assertSame('استاد حسینی', $created['instructor_name']);
        self::assertNull(RedisClient::get('cache:public:' . md5('http_response:/api/v1/public/testimonials')));

        // Invalid course_id fails with 422
        try {
            $this->service->createByAdmin([
                'author_name' => 'کاربر تست',
                'rating'      => 5,
                'content'     => 'متن نظر معتبر با طول کافی برای اعتبارسنجی دوره ناموجود.',
                'course_id'   => 9999,
            ], 9);
            self::fail('Expected nonexistent course_id to fail with 422');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
        }
    }

    public function testModerationFlowPublicVisibilityAndSummary(): void
    {
        $pending = $this->service->submitPublic([
            'author_name' => 'فاطمه کریمی',
            'rating'      => 4,
            'content'     => 'آزمون‌های منظم و تحلیل کارنامه خیلی به من کمک کرد.',
        ], '203.0.113.20');

        // Pending testimonials must not appear publicly
        $before = $this->service->listPublic();
        self::assertCount(0, $before['data']['items']);
        self::assertSame(0, $before['data']['summary']['total_approved']);
        self::assertSame(0.0, $before['data']['summary']['average_rating']);

        // Admin approves and links course_id
        $approved = $this->service->moderate($pending['id'], [
            'status'      => 'approved',
            'course_id'   => 10,
            'is_featured' => true,
        ], 4);
        self::assertSame('approved', $approved['status']);
        self::assertSame(4, $approved['moderated_by']);
        self::assertSame(10, $approved['course_id']);

        // Second approved testimonial with rating 5 -> avg should be 4.5
        $this->service->createByAdmin([
            'author_name' => 'امیرحسین راد',
            'rating'      => 5,
            'content'     => 'مشاوره فردی و پیگیری روزانه بی‌نظیر بود، ممنون از تیم فامو.',
        ], 4);

        $public = $this->service->listPublic();
        self::assertCount(2, $public['data']['items']);
        self::assertSame(2, $public['data']['summary']['total_approved']);
        self::assertSame(4.5, $public['data']['summary']['average_rating']);
        self::assertArrayNotHasKey('moderated_by', $public['data']['items'][0]);
        self::assertArrayNotHasKey('rejection_reason', $public['data']['items'][0]);
        self::assertArrayNotHasKey('source', $public['data']['items'][0]);

        // Rejecting removes it from public list
        $rejected = $this->service->moderate($pending['id'], [
            'status'           => 'rejected',
            'rejection_reason' => 'نیاز به ویرایش متن',
        ], 4);
        self::assertSame('rejected', $rejected['status']);
        self::assertSame('نیاز به ویرایش متن', $rejected['rejection_reason']);

        $afterReject = $this->service->listPublic();
        self::assertCount(1, $afterReject['data']['items']);
        self::assertSame(5.0, $afterReject['data']['summary']['average_rating']);
    }

    public function testBulkRejectUpdatesSelectedIdsAndEnforcesMax100Limit(): void
    {
        $t1 = $this->service->submitPublic([
            'author_name' => 'اسپم ۱',
            'rating'      => 1,
            'content'     => 'متن تبلیغاتی شماره یک برای تست رد دسته‌جمعی نظرات.',
        ], '203.0.113.31');

        $t2 = $this->service->submitPublic([
            'author_name' => 'اسپم ۲',
            'rating'      => 1,
            'content'     => 'متن تبلیغاتی شماره دو برای تست رد دسته‌جمعی نظرات.',
        ], '203.0.113.32');

        $kept = $this->service->createByAdmin([
            'author_name' => 'کاربر واقعی',
            'rating'      => 5,
            'content'     => 'نظر واقعی که نباید در رد دسته‌جمعی تغییر کند.',
        ], 2);

        Cache::remember('public', 'http_response:/api/v1/public/testimonials', 300, static fn () => '{"cached":true}');

        $res = $this->service->bulkReject([$t1['id'], $t2['id'], $t1['id']], 'محتوای تبلیغاتی', 8);
        self::assertSame(2, $res['rejected_count']);
        self::assertSame([$t1['id'], $t2['id']], $res['requested_ids']);
        self::assertSame('محتوای تبلیغاتی', $res['rejection_reason']);
        self::assertNull(RedisClient::get('cache:public:' . md5('http_response:/api/v1/public/testimonials')));

        self::assertSame('rejected', $this->service->get($t1['id'])['status']);
        self::assertSame('محتوای تبلیغاتی', $this->service->get($t2['id'])['rejection_reason']);
        self::assertSame(8, $this->service->get($t2['id'])['moderated_by']);
        self::assertSame('approved', $this->service->get($kept['id'])['status']);

        // > 100 IDs rejected with 422
        try {
            $this->service->bulkReject(range(1, 101), 'spam', 8);
            self::fail('Expected > 100 IDs to fail with 422');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
        }

        // Empty or non-positive IDs rejected with 422
        foreach ([[], [0], [-5], ['abc']] as $badIds) {
            try {
                $this->service->bulkReject($badIds, null, 8);
                self::fail('Expected invalid ids list to fail with 422');
            } catch (ApiException $e) {
                self::assertSame(422, $e->getHttpStatus());
                self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
            }
        }
    }

    public function testAdminListSortingAndDelete(): void
    {
        $low = $this->service->createByAdmin([
            'author_name' => 'مریم',
            'rating'      => 3,
            'content'     => 'نظر با امتیاز متوسط برای تست مرتب‌سازی ادمین.',
        ], 1);

        $high = $this->service->createByAdmin([
            'author_name' => 'آیدا',
            'rating'      => 5,
            'content'     => 'نظر با امتیاز عالی برای تست مرتب‌سازی ادمین.',
        ], 1);

        $sorted = $this->service->listAdmin([], 1, 10, ['sort' => 'rating', 'order' => 'asc']);
        self::assertSame($low['id'], $sorted['items'][0]['id']);
        self::assertSame($high['id'], $sorted['items'][1]['id']);

        $this->service->delete($low['id']);
        try {
            $this->service->get($low['id']);
            self::fail('Expected deleted testimonial to throw 404');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getHttpStatus());
            self::assertSame('NOT_FOUND', $e->getErrorCode());
        }
    }
}
