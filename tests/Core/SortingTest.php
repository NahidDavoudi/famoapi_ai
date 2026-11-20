<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\Pagination;
use App\Core\Sorting;
use App\Modules\Appointments\AppointmentService;
use App\Modules\Blog\BlogService;
use App\Modules\Exams\ExamService;
use App\Modules\Students\StudentService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class SortingTest extends TestCase
{
    private ?PDO $db = null;

    protected function tearDown(): void
    {
        Database::reset();
        $this->db = null;
        parent::tearDown();
    }

    public function testDefaultFallbackWhenNoParamsProvided(): void
    {
        $whitelist = [
            'created_at' => ['expr' => 's.created_at'],
            'name'       => ['expr' => 's.name', 'collate' => 'utf8mb4_persian_ci'],
        ];

        $resolved = Sorting::resolve([], $whitelist, 'created_at', 'DESC', 's.id', 'mysql');

        self::assertSame('created_at', $resolved['key']);
        self::assertSame('desc', $resolved['order']);
        self::assertSame('DESC', $resolved['sql_order']);
        self::assertFalse($resolved['is_explicit']);
        self::assertSame(
            'ORDER BY CASE WHEN s.created_at IS NULL THEN 1 ELSE 0 END ASC, s.created_at DESC, s.id DESC',
            $resolved['order_by']
        );
    }

    public function testParsesPsr7RequestAndPreservesValidSortAndOrderCaseInsensitively(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/students?sort=name&order=AsC')
            ->withQueryParams(['sort' => 'name', 'order' => 'AsC']);

        $whitelist = [
            'created_at' => ['expr' => 's.created_at'],
            'name'       => ['expr' => 's.name', 'collate' => 'utf8mb4_persian_ci'],
        ];

        $resolved = Sorting::resolve($request, $whitelist, 'created_at', 'DESC', 's.id', 'mysql');

        self::assertSame('name', $resolved['key']);
        self::assertSame('asc', $resolved['order']);
        self::assertSame('ASC', $resolved['sql_order']);
        self::assertTrue($resolved['is_explicit']);
        self::assertSame(
            'ORDER BY CASE WHEN s.name IS NULL THEN 1 ELSE 0 END ASC, s.name COLLATE utf8mb4_persian_ci ASC, s.id ASC',
            $resolved['order_by']
        );
    }

    public function testUnknownKeysAndInjectionAttemptsFallBackSilentlyToDefault(): void
    {
        $whitelist = [
            'created_at' => ['expr' => 's.created_at'],
            'name'       => ['expr' => 's.name', 'collate' => 'utf8mb4_persian_ci'],
        ];
        $expectedDefault = 'ORDER BY CASE WHEN s.created_at IS NULL THEN 1 ELSE 0 END ASC, s.created_at DESC, s.id DESC';

        $maliciousInputs = [
            'unknown_col',
            'name; DROP',
            'name; DROP TABLE students;--',
            'name,id',
            '`name`',
            'name DESC --',
            '1 UNION SELECT 1',
            ['name'],
        ];

        foreach ($maliciousInputs as $badSort) {
            $resolved = Sorting::resolve(
                ['sort' => $badSort, 'order' => 'asc'],
                $whitelist,
                'created_at',
                'DESC',
                's.id',
                'mysql'
            );

            self::assertSame('created_at', $resolved['key']);
            self::assertSame('desc', $resolved['order']);
            self::assertSame($expectedDefault, $resolved['order_by']);
            self::assertStringNotContainsString('DROP', $resolved['order_by']);
            self::assertStringNotContainsString('`', $resolved['order_by']);
            self::assertStringNotContainsString(';', $resolved['order_by']);
            self::assertStringNotContainsString('--', $resolved['order_by']);
        }
    }

    public function testInvalidOrderFallsBackToDefaultOrderAndAlwaysIncludesTiebreaker(): void
    {
        $whitelist = [
            'created_at' => ['expr' => 's.created_at'],
            'name'       => ['expr' => 's.name'],
        ];

        foreach (['sideways', 'asc; DROP', '', '1', ['desc']] as $badOrder) {
            $resolved = Sorting::resolve(
                ['sort' => 'name', 'order' => $badOrder],
                $whitelist,
                'created_at',
                'DESC',
                's.id',
                'sqlite'
            );

            self::assertSame('name', $resolved['key']);
            self::assertSame('desc', $resolved['order']);
            self::assertStringEndsWith(', s.id DESC', $resolved['order_by']);
        }

        // Passing only ?order=asc reverses the default sort cleanly while keeping tiebreaker
        $reversedDefault = Sorting::resolve(['order' => 'asc'], $whitelist, 'created_at', 'DESC', 's.id', 'sqlite');
        self::assertSame('created_at', $reversedDefault['key']);
        self::assertSame('asc', $reversedDefault['order']);
        self::assertStringEndsWith(', s.id ASC', $reversedDefault['order_by']);
    }

    public function testPersianCollationOnlyEmittedForMysqlDriver(): void
    {
        $whitelist = [
            'title' => ['expr' => 'bp.title', 'collate' => 'utf8mb4_persian_ci'],
        ];

        $mysql = Sorting::resolve(['sort' => 'title', 'order' => 'asc'], $whitelist, 'title', 'DESC', 'bp.id', 'mysql');
        $pgsql = Sorting::resolve(['sort' => 'title', 'order' => 'asc'], $whitelist, 'title', 'DESC', 'bp.id', 'pgsql');
        $sqlite = Sorting::resolve(['sort' => 'title', 'order' => 'asc'], $whitelist, 'title', 'DESC', 'bp.id', 'sqlite');

        self::assertStringContainsString('COLLATE utf8mb4_persian_ci', $mysql['order_by']);
        self::assertStringNotContainsString('COLLATE', $pgsql['order_by']);
        self::assertStringNotContainsString('COLLATE', $sqlite['order_by']);
    }

    public function testNullsSortLastInBothAscAndDescDirections(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, score INTEGER NULL)');
        $db->exec('INSERT INTO items (id, score) VALUES (1, NULL), (2, 10), (3, 30), (4, 20), (5, NULL)');

        $whitelist = ['score' => ['expr' => 'score']];

        $ascSql = Sorting::orderBy(['sort' => 'score', 'order' => 'asc'], $whitelist, 'score', 'DESC', 'id', 'sqlite');
        $ascIds = array_column($db->query("SELECT id FROM items {$ascSql}")->fetchAll(), 'id');
        self::assertSame([2, 4, 3, 1, 5], array_map('intval', $ascIds));

        $descSql = Sorting::orderBy(['sort' => 'score', 'order' => 'desc'], $whitelist, 'score', 'DESC', 'id', 'sqlite');
        $descIds = array_column($db->query("SELECT id FROM items {$descSql}")->fetchAll(), 'id');
        self::assertSame([3, 4, 2, 5, 1], array_map('intval', $descIds));
    }

    public function testAttachToPaginationOnlyAddsSortMetadataWhenExplicit(): void
    {
        $base = Pagination::build(1, 20, 42);
        $whitelist = ['created_at' => 's.created_at', 'name' => 's.name'];

        $implicit = Sorting::resolve([], $whitelist, 'created_at', 'DESC', 's.id', 'sqlite');
        self::assertSame($base, Sorting::attachToPagination($base, $implicit));

        $explicit = Sorting::resolve(['sort' => 'name', 'order' => 'asc'], $whitelist, 'created_at', 'DESC', 's.id', 'sqlite');
        self::assertSame([
            'page'        => 1,
            'per_page'    => 20,
            'total'       => 42,
            'total_pages' => 3,
            'sort'        => 'name',
            'order'       => 'asc',
        ], Sorting::attachToPagination($base, $explicit));
    }

    public function testStudentServiceSortingAndPaginationContract(): void
    {
        $db = $this->bootSqliteSchema();
        $db->exec("INSERT INTO students (id, name, grade, field, is_active, created_at) VALUES
            (1, 'زهرا', 10, 'تجربی', 1, '2026-01-01 10:00:00'),
            (2, 'امیر', 12, 'ریاضی', 1, '2026-01-03 10:00:00'),
            (3, 'بهرام', 11, 'انسانی', 1, '2026-01-02 10:00:00'),
            (4, 'پارسا', 11, 'ریاضی', 1, '2026-01-02 10:00:00')");

        $service = new StudentService();

        // Default order unchanged (created_at DESC, id DESC) and pagination has no sort/order keys
        $default = $service->list([], 1, 20);
        self::assertSame([2, 4, 3, 1], array_map('intval', array_column($default['items'], 'id')));
        self::assertArrayNotHasKey('sort', $default['pagination']);
        self::assertArrayNotHasKey('order', $default['pagination']);

        // Sort by grade ASC (tiebreaker id ASC: ids 3 and 4 share grade 11)
        $byGrade = $service->list([], 1, 20, ['sort' => 'grade', 'order' => 'asc']);
        self::assertSame([1, 3, 4, 2], array_map('intval', array_column($byGrade['items'], 'id')));
        self::assertSame('grade', $byGrade['pagination']['sort']);
        self::assertSame('asc', $byGrade['pagination']['order']);

        // Malicious sort falls back safely to default
        $badSort = $service->list([], 1, 20, ['sort' => 'name; DROP', 'order' => 'asc']);
        self::assertSame([2, 4, 3, 1], array_map('intval', array_column($badSort['items'], 'id')));
        self::assertSame('created_at', $badSort['pagination']['sort']);
        self::assertSame('desc', $badSort['pagination']['order']);
    }

    public function testExamServiceSortingForResultsListAndStudentsByDate(): void
    {
        $db = $this->bootSqliteSchema();
        $db->exec("INSERT INTO students (id, name, grade, field, is_active, created_at) VALUES
            (1, 'سارا', 12, 'تجربی', 1, '2026-01-01 10:00:00'),
            (2, 'آرش', 12, 'ریاضی', 1, '2026-01-01 10:00:00')");
        $db->exec("INSERT INTO exam_results (id, student_id, exam_date, subject, total_q, correct, wrong, skipped, percentage) VALUES
            (1, 1, '2026-02-01', 'فیزیک', 10, 5, 0, 5, 50.0),
            (2, 2, '2026-02-01', 'فیزیک', 10, 9, 0, 1, 90.0),
            (3, 1, '2026-02-01', 'شیمی', 10, 7, 0, 3, 70.0),
            (4, 2, '2026-02-10', 'ریاضی', 10, 4, 0, 6, 40.0)");

        $service = new ExamService();

        // /api/v1/exams default: exam_date DESC, student_name ASC, er.id DESC
        $defaultExams = $service->getAll([], 1, 20);
        self::assertSame([4, 2, 3, 1], array_map('intval', array_column($defaultExams['exams'], 'id')));

        // /api/v1/exams sorted by stored percentage DESC
        $byPct = $service->getAll([], 1, 20, ['sort' => 'percentage', 'order' => 'desc']);
        self::assertSame([2, 3, 1, 4], array_map('intval', array_column($byPct['exams'], 'id')));

        // /api/v1/exams/students sorted by avg_percentage DESC (student 2: 90.0, student 1: 60.0)
        $byAvg = $service->getStudentsByDate('2026-02-01', 1, 20, ['sort' => 'avg_percentage', 'order' => 'desc']);
        self::assertSame([2, 1], array_map('intval', array_column($byAvg['students'], 'student_id')));
        self::assertSame('avg_percentage', $byAvg['pagination']['sort']);
    }

    public function testBlogAdminListSortingPutsNullPublishedAtDraftsLastInBothDirections(): void
    {
        $db = $this->bootSqliteSchema();
        $db->exec("INSERT INTO blog_posts (id, title, slug, is_published, published_at, views) VALUES
            (1, 'ب پست', 'post-b', 1, '2026-01-10 00:00:00', 50),
            (2, 'پست پیش‌نویس', 'post-draft', 0, NULL, 5),
            (3, 'الف پست', 'post-a', 1, '2026-01-20 00:00:00', 200)");

        $service = new BlogService();

        // Default: published_at DESC (draft NULL last!)
        $desc = $service->getAllPosts(1, 20);
        self::assertSame([3, 1, 2], array_map('intval', array_column($desc['posts'], 'id')));

        // Ascending published_at: draft NULL STILL last!
        $asc = $service->getAllPosts(1, 20, ['sort' => 'published_at', 'order' => 'asc']);
        self::assertSame([1, 3, 2], array_map('intval', array_column($asc['posts'], 'id')));

        // By views DESC
        $byViews = $service->getAllPosts(1, 20, ['sort' => 'views', 'order' => 'desc']);
        self::assertSame([3, 1, 2], array_map('intval', array_column($byViews['posts'], 'id')));

        // By status ASC (draft 0 first, published 1 tiebroken by id ASC)
        $byStatus = $service->getAllPosts(1, 20, ['sort' => 'status', 'order' => 'asc']);
        self::assertSame([2, 1, 3], array_map('intval', array_column($byStatus['posts'], 'id')));
    }

    public function testAppointmentServiceSortingSupportsDateTimeStudentAndStatus(): void
    {
        $db = $this->bootSqliteSchema();
        $db->exec("INSERT INTO students (id, name, grade, field, is_active, created_at) VALUES
            (1, 'مریم', 12, 'تجربی', 1, '2026-01-01 10:00:00'),
            (2, 'احمد', 11, 'ریاضی', 1, '2026-01-01 10:00:00')");
        $db->exec("INSERT INTO appointments (id, student_id, appointment_date, start_time, status) VALUES
            (1, 1, '2026-03-10', '14:00', 'pending'),
            (2, 2, '2026-03-10', '09:00', 'confirmed'),
            (3, 1, '2026-03-12', '10:00', 'cancelled')");

        $service = new AppointmentService();

        // Default matches existing appointment_date DESC, id DESC
        $default = $service->list(1, 20);
        self::assertSame([3, 2, 1], array_map('intval', array_column($default['items'], 'id')));

        // Explicit time ASC orders chronologically by appointment_date + start_time
        $byTimeAsc = $service->list(1, 20, ['sort' => 'time', 'order' => 'asc']);
        self::assertSame([2, 1, 3], array_map('intval', array_column($byTimeAsc['items'], 'id')));

        // Status ASC (cancelled, confirmed, pending)
        $byStatus = $service->list(1, 20, ['sort' => 'status', 'order' => 'asc']);
        self::assertSame([3, 2, 1], array_map('intval', array_column($byStatus['items'], 'id')));
    }

    private function bootSqliteSchema(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Database::setConnection($db);
        $this->db = $db;

        $db->exec('CREATE TABLE students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            grade INTEGER,
            field TEXT,
            phone TEXT,
            national_id TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            role TEXT NOT NULL,
            linked_id INTEGER
        )');

        $db->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_path TEXT
        )');

        $db->exec('CREATE TABLE exam_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            file_id INTEGER,
            exam_date TEXT NOT NULL,
            subject TEXT NOT NULL,
            chapter TEXT,
            total_q INTEGER NOT NULL,
            correct INTEGER NOT NULL,
            wrong INTEGER NOT NULL,
            skipped INTEGER NOT NULL,
            percentage REAL NOT NULL DEFAULT 0,
            created_at TEXT
        )');

        $db->exec('CREATE TABLE blog_category (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            slug TEXT
        )');

        $db->exec('CREATE TABLE blog_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            category_id INTEGER,
            excerpt TEXT,
            content TEXT,
            cover_image TEXT,
            meta_description TEXT,
            published_at TEXT,
            views INTEGER NOT NULL DEFAULT 0,
            is_published INTEGER NOT NULL DEFAULT 1
        )');

        $db->exec('CREATE TABLE appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            appointment_date TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT,
            status TEXT NOT NULL DEFAULT \'pending\',
            type TEXT,
            description TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        return $db;
    }
}
