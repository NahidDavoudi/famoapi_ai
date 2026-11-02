import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createAppContext, handlePublicEndpoint, handleHealthEndpoint } from './dev-server.mjs';

test('Health endpoint (/health) returns current database and Redis connection status', () => {
  const ctx = createAppContext();
  const res = handleHealthEndpoint(ctx);

  assert.equal(res.status, 200);
  assert.equal(res.body.success, true);
  assert.equal(res.body.data.status, 'healthy');
  assert.equal(res.body.data.database.status, 'connected');
  assert.equal(res.body.data.database.connected, true);
  assert.equal(res.body.data.redis.status, 'connected');
  assert.equal(res.body.data.redis.connected, true);

  // Degraded when Redis is disconnected but database is connected
  const degradedRes = handleHealthEndpoint({ ...ctx, redisConnected: false });
  assert.equal(degradedRes.status, 200);
  assert.equal(degradedRes.body.data.status, 'degraded');
  assert.equal(degradedRes.body.data.redis.status, 'disconnected');
  assert.equal(degradedRes.body.data.redis.connected, false);

  // Unhealthy (503) when database is disconnected
  const unhealthyRes = handleHealthEndpoint({ ...ctx, databaseConnected: false });
  assert.equal(unhealthyRes.status, 503);
  assert.equal(unhealthyRes.body.success, false);
  assert.equal(unhealthyRes.body.data.status, 'unhealthy');
  assert.equal(unhealthyRes.body.data.database.status, 'disconnected');
  assert.equal(unhealthyRes.body.data.database.connected, false);
});

test('Public endpoints cache responses in Redis and return X-Cache MISS then HIT', () => {
  const ctx = createAppContext();
  const url = new URL('http://localhost:3000/api/v1/public/courses');

  const first = handlePublicEndpoint(ctx, 'GET', url, '203.0.113.5');
  assert.equal(first.status, 200);
  assert.equal(first.headers['X-Cache'], 'MISS');
  assert.equal(first.body.success, true);

  const second = handlePublicEndpoint(ctx, 'GET', url, '203.0.113.5');
  assert.equal(second.status, 200);
  assert.equal(second.headers['X-Cache'], 'HIT');
  assert.deepEqual(second.body, first.body);

  ctx.cache.flushGroup('public');

  const third = handlePublicEndpoint(ctx, 'GET', url, '203.0.113.5');
  assert.equal(third.status, 200);
  assert.equal(third.headers['X-Cache'], 'MISS');
});

test('Blog post slug endpoint caches post in Redis while incrementing live views', () => {
  const ctx = createAppContext();
  const url = new URL(
    'http://localhost:3000/api/v1/public/blog/posts/high-retention-weekly-study-plan'
  );

  const first = handlePublicEndpoint(ctx, 'GET', url, '203.0.113.9');
  assert.equal(first.status, 200);
  assert.equal(first.headers['X-Cache'], 'MISS');
  const firstViews = first.body.data.post.views;

  const second = handlePublicEndpoint(ctx, 'GET', url, '203.0.113.9');
  assert.equal(second.status, 200);
  assert.equal(second.headers['X-Cache'], 'HIT');
  assert.equal(second.body.data.post.views, firstViews + 1);
});

test('Public blog endpoints validate page and per_page query parameters', () => {
  const ctx = createAppContext();

  const invalidPageUrl = new URL('http://localhost:3000/api/v1/public/blog/posts?page=0');
  const r1 = handlePublicEndpoint(ctx, 'GET', invalidPageUrl, '203.0.113.20');
  assert.equal(r1.status, 422);
  assert.equal(r1.body.error.code, 'VALIDATION_ERROR');

  const invalidPerPageUrl = new URL(
    'http://localhost:3000/api/v1/public/blog/posts?page=1&per_page=999'
  );
  const r2 = handlePublicEndpoint(ctx, 'GET', invalidPerPageUrl, '203.0.113.20');
  assert.equal(r2.status, 422);
  assert.equal(r2.body.error.code, 'VALIDATION_ERROR');
});

test('Redis RateLimiter enforces maxAttempts and locks out with 429 RATE_LIMITED and Retry-After', () => {
  const ctx = createAppContext();
  ctx.rateLimiter.maxAttempts = 3;
  const url = new URL('http://localhost:3000/api/v1/public/instructors');

  const r1 = handlePublicEndpoint(ctx, 'GET', url, '198.51.100.42');
  assert.equal(r1.status, 200);
  assert.equal(r1.headers['X-RateLimit-Remaining'], '2');

  const r2 = handlePublicEndpoint(ctx, 'GET', url, '198.51.100.42');
  assert.equal(r2.status, 200);
  assert.equal(r2.headers['X-RateLimit-Remaining'], '1');

  const r3 = handlePublicEndpoint(ctx, 'GET', url, '198.51.100.42');
  assert.equal(r3.status, 429);
  assert.equal(r3.body.error.code, 'RATE_LIMITED');
  assert.ok(Number(r3.headers['Retry-After']) > 0);
});

test('OpenAPI specification files document all public endpoints and input requirements', () => {
  const publicSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');

  for (const spec of [publicSpec, rootSpec]) {
    assert.match(spec, /openapi:\s*"3\.1\.0"/);
    assert.match(spec, /\/api\/v1\/public\/courses:/);
    assert.match(spec, /\/api\/v1\/public\/instructors:/);
    assert.match(spec, /\/api\/v1\/public\/supporters:/);
    assert.match(spec, /\/api\/v1\/public\/blog\/posts:/);
    assert.match(spec, /\/api\/v1\/public\/blog\/posts\/\{slug\}:/);
    assert.match(spec, /\/api\/v1\/public\/blog\/categories:/);
    assert.match(spec, /\/api\/v1\/public\/blog\/categories\/\{category\}\/posts:/);
    assert.match(spec, /PublicPerPage:/);
    assert.match(spec, /PublicBlogSlug:/);
    assert.match(spec, /PublicBlogCategorySlug:/);
    assert.match(spec, /XCacheHeader:/);
    assert.match(spec, /XRateLimitLimitHeader:/);
  }
});

test('PostgreSQL schema, migrations, and database configuration are properly normalized and free of MySQL syntax', () => {
  const migrationFiles = [
    './database/migrations/001_core_identity_and_users.sql',
    './database/migrations/002_academic_and_planner.sql',
    './database/migrations/003_courses_blog_and_public.sql',
    './database/migrations/004_attendance_and_tutoring.sql',
    './database/migrations/005_telegram_bot_threads_and_outbox.sql',
  ];
  const combinedMigrationsSql = migrationFiles
    .map((rel) => fs.readFileSync(new URL(rel, import.meta.url), 'utf8'))
    .join('\n');
  const dbConfig = fs.readFileSync(new URL('./config/database.php', import.meta.url), 'utf8');
  const dbCore = fs.readFileSync(new URL('./app/Core/Database.php', import.meta.url), 'utf8');
  const migratorCore = fs.readFileSync(new URL('./app/Core/Migrator.php', import.meta.url), 'utf8');

  // Verify PostgreSQL identity PKs, partial unique indexes, and PL/pgSQL trigger
  assert.match(combinedMigrationsSql, /BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY/);
  assert.match(combinedMigrationsSql, /CREATE UNIQUE INDEX IF NOT EXISTS uk_active_student/);
  assert.match(combinedMigrationsSql, /CREATE UNIQUE INDEX IF NOT EXISTS uq_sess_one_open_per_student/);
  assert.match(combinedMigrationsSql, /CREATE INDEX IF NOT EXISTS idx_users_phone ON users \(phone\)/);
  assert.match(combinedMigrationsSql, /LANGUAGE plpgsql/);
  assert.doesNotMatch(combinedMigrationsSql, /\bemail\b/i);
  assert.doesNotMatch(combinedMigrationsSql, /ENGINE=InnoDB/i);
  assert.doesNotMatch(combinedMigrationsSql, /AUTO_INCREMENT/i);

  // Verify config and core use pgsql driver and PostgreSQL advisory locks
  assert.match(dbConfig, /'driver'\s*=>\s*\$_ENV\['DB_DRIVER'\]\s*\?\?\s*'pgsql'/);
  assert.match(dbCore, /pgsql:host=/);
  assert.match(migratorCore, /pg_try_advisory_lock/);
});

test('PhoneNumber helper validates and normalizes Iranian mobile numbers before saving to the database', () => {
  const phoneHelper = fs.readFileSync(new URL('./app/Core/PhoneNumber.php', import.meta.url), 'utf8');
  const validatorPhp = fs.readFileSync(new URL('./app/Core/Validator.php', import.meta.url), 'utf8');
  const userPhp = fs.readFileSync(new URL('./app/Modules/Auth/User.php', import.meta.url), 'utf8');
  const authServicePhp = fs.readFileSync(new URL('./app/Modules/Auth/AuthService.php', import.meta.url), 'utf8');
  const studentPhp = fs.readFileSync(new URL('./app/Modules/Students/Student.php', import.meta.url), 'utf8');
  const studentServicePhp = fs.readFileSync(new URL('./app/Modules/Students/StudentService.php', import.meta.url), 'utf8');
  const supporterPhp = fs.readFileSync(new URL('./app/Modules/Supporters/Supporter.php', import.meta.url), 'utf8');
  const supporterServicePhp = fs.readFileSync(new URL('./app/Modules/Supporters/SupporterService.php', import.meta.url), 'utf8');
  const parentContactPhp = fs.readFileSync(new URL('./app/Modules/ParentContacts/ParentContact.php', import.meta.url), 'utf8');

  // Verify helper methods and Persian/Arabic digit + prefix normalization
  assert.match(phoneHelper, /final class PhoneNumber/);
  assert.match(phoneHelper, /public static function toEnglishDigits\(string \$input\): string/);
  assert.match(phoneHelper, /public static function normalize\(mixed \$raw\): \?string/);
  assert.match(phoneHelper, /public static function normalizeOrFail\(/);
  assert.match(phoneHelper, /public static function isValid\(mixed \$raw\): bool/);
  assert.match(phoneHelper, /public static function toE164\(mixed \$raw\): \?string/);
  assert.match(phoneHelper, /public static function mask\(string \$phone\): string/);

  // Verify integration across Validator, Models, and Services before DB persistence
  assert.match(validatorPhp, /PhoneNumber::isValid\(\$value\)/);
  assert.match(userPhp, /PhoneNumber::normalizeOrFail\(\$data\['phone'\]\)/);
  assert.match(authServicePhp, /PhoneNumber::normalizeOrFail\(\$data\['phone'\]/);
  assert.match(studentPhp, /PhoneNumber::normalizeOrFail\(\$data\['phone'\]/);
  assert.match(studentServicePhp, /PhoneNumber::normalizeOrFail\(\$data\['phone'\]/);
  assert.match(supporterPhp, /PhoneNumber::normalizeOrFail\(\$data\['phone'\]/);
  assert.match(supporterServicePhp, /PhoneNumber::normalizeOrFail\(\$phone/);
  assert.match(parentContactPhp, /PhoneNumber::normalizeOrFail\(\$data\['phone'\]/);
});

test('Course Subscription module (Model, Service, Controller, Routes) and documentation (openapi.yaml & README.md) are complete and synchronized', () => {
  const subModel = fs.readFileSync(new URL('./app/Modules/Subscription/Subscription.php', import.meta.url), 'utf8');
  const subService = fs.readFileSync(new URL('./app/Modules/Subscription/SubscriptionService.php', import.meta.url), 'utf8');
  const subController = fs.readFileSync(new URL('./app/Modules/Subscription/SubscriptionController.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');

  // Verify Subscription model, service, and controller
  assert.match(subModel, /class Subscription/);
  assert.match(subModel, /public static function findActiveOrPendingByStudentAndCourse\(/);
  assert.match(subService, /class SubscriptionService/);
  assert.match(subService, /ALREADY_SUBSCRIBED/);
  assert.match(subController, /class SubscriptionController/);
  assert.match(subController, /public function mySubscriptions\(/);
  assert.match(subController, /public function studentSubscriptions\(/);
  assert.match(subController, /public function updateStatus\(/);

  // Verify routes in routes/api.php
  assert.match(routesPhp, /\/subscriptions\/me/);
  assert.match(routesPhp, /\/subscriptions\/\{id:\[0-9\]\+\}\/status/);
  assert.match(routesPhp, /\/students\/\{id:\[0-9\]\+\}\/subscriptions/);

  // Verify OpenAPI 3.1.0 documentation
  assert.match(rootSpec, /\/api\/v1\/subscriptions:/);
  assert.match(rootSpec, /\/api\/v1\/subscriptions\/me:/);
  assert.match(rootSpec, /\/api\/v1\/subscriptions\/\{id\}:/);
  assert.match(rootSpec, /\/api\/v1\/subscriptions\/\{id\}\/status:/);
  assert.match(rootSpec, /\/api\/v1\/students\/\{id\}\/subscriptions:/);
  assert.match(rootSpec, /SubscriptionCreateRequest:/);
  assert.match(rootSpec, /SubscriptionUpdateRequest:/);
  assert.match(rootSpec, /SubscriptionStatusUpdateRequest:/);

  // Verify README.md documentation
  assert.match(readmeMd, /Course Subscription Endpoints \(`Subscriptions`\)/);
  assert.match(readmeMd, /\/api\/v1\/subscriptions\/me/);
  assert.match(readmeMd, /PhoneNumber\.php/);
});

test('Dashboard pending_reports query, blog categories route, PATCH catch-all, and attendance excused status are properly configured and documented', () => {
  const dashboardPhp = fs.readFileSync(new URL('./app/Modules/Dashboard/DashboardController.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const attendanceCtrl = fs.readFileSync(new URL('./app/Modules/Attendance/AttendanceController.php', import.meta.url), 'utf8');
  const attendanceSvc = fs.readFileSync(new URL('./app/Modules/Attendance/AttendanceService.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');

  assert.match(dashboardPhp, /SELECT COUNT\(\*\) FROM reports_status WHERE status = 0 OR status IS NULL/);
  assert.doesNotMatch(dashboardPhp, /status = 'pending'/);

  assert.match(routesPhp, /\$app->get\('\/api\/v1\/blog\/categories',\s*\[\$blogController,\s*'getCategories'\]\)/);
  assert.match(routesPhp, /\$app->map\(\['GET',\s*'POST',\s*'PUT',\s*'PATCH',\s*'DELETE'\],\s*'\/api\/v1\/\{routes:\.\+\}'/);

  assert.match(attendanceCtrl, /\['present',\s*'absent',\s*'excused'\]/);
  assert.match(attendanceSvc, /\['present',\s*'absent',\s*'excused'\]/);

  assert.match(rootSpec, /\/api\/v1\/blog\/categories:/);
  assert.match(rootSpec, /enum:\s*\[present,\s*absent,\s*excused\]/);
});
