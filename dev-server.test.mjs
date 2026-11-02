import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
  createAppContext,
  handlePublicEndpoint,
  handleHealthEndpoint,
  handleAuthEndpoint,
  handleExamEndpoint,
} from './dev-server.mjs';

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
    './database/migrations/006_exam_comments_and_share_links.sql',
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

test('Phase 1 Step 1 — Auth change-password and profile endpoints (PHP implementation, OpenAPI, README, and behavioral scenarios)', () => {
  const authCtrl = fs.readFileSync(new URL('./app/Modules/Auth/AuthController.php', import.meta.url), 'utf8');
  const authSvc = fs.readFileSync(new URL('./app/Modules/Auth/AuthService.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');

  // 1. Verify PHP Controller, Service, and Routes
  assert.match(authCtrl, /public function changePassword\(Request \$request,\s*Response \$response\): Response/);
  assert.match(authCtrl, /public function updateProfile\(Request \$request,\s*Response \$response\): Response/);
  assert.match(authSvc, /public function changePassword\(int \$userId,\s*string \$currentPassword,\s*string \$newPassword\): void/);
  assert.match(authSvc, /public function updateProfile\(int \$userId,\s*array \$data\): array/);
  assert.match(authSvc, /INVALID_PASSWORD/);
  assert.match(authSvc, /DUPLICATE_PHONE/);
  assert.match(authSvc, /\$twoFaRoles = \['admin', 'supporter', 'teacher'\]/);
  assert.match(authSvc, /UPDATE admins SET name = :name, phone = :phone WHERE id = :id/);
  assert.match(authSvc, /UPDATE supporters SET name = :name, phone = :phone WHERE id = :id/);
  assert.match(authSvc, /UPDATE students SET name = :name, phone = :phone WHERE id = :id/);
  assert.match(authSvc, /UPDATE instructors SET name = :name WHERE id = :id/);
  assert.match(routesPhp, /\$app->post\('\/api\/v1\/auth\/change-password',\s*\[\$authController,\s*'changePassword'\]\)->add\(\$authMiddleware\)/);
  assert.match(routesPhp, /\$app->put\('\/api\/v1\/auth\/profile',\s*\[\$authController,\s*'updateProfile'\]\)->add\(\$authMiddleware\)/);

  // 2. Verify OpenAPI & README documentation
  assert.match(rootSpec, /\/api\/v1\/auth\/change-password:/);
  assert.match(rootSpec, /operationId:\s*authChangePassword/);
  assert.match(rootSpec, /\/api\/v1\/auth\/profile:/);
  assert.match(rootSpec, /operationId:\s*authUpdateProfile/);
  assert.match(rootSpec, /AuthChangePasswordRequest:/);
  assert.match(rootSpec, /AuthProfileUpdateRequest:/);
  assert.match(rootSpec, /AuthUserProfile:/);
  assert.match(readmeMd, /\/api\/v1\/auth\/change-password/);
  assert.match(readmeMd, /\/api\/v1\/auth\/profile/);

  // 3. Behavioral tests via simulator
  const ctx = createAppContext();

  // 3a. Unauthenticated requests -> 401
  const unauthChange = handleAuthEndpoint(ctx, 'POST', '/api/v1/auth/change-password', {
    current_password: 'stud123',
    new_password: 'newpassword',
  }, null);
  assert.equal(unauthChange.status, 401);

  // 3b. change-password: validation error (new_password < 4 chars) -> 422
  const shortPass = handleAuthEndpoint(ctx, 'POST', '/api/v1/auth/change-password', {
    current_password: 'stud123',
    new_password: '123',
  }, { sub: 3, role: 'student' });
  assert.equal(shortPass.status, 422);
  assert.equal(shortPass.body.error.code, 'VALIDATION_ERROR');

  // 3c. change-password: wrong current_password -> 401 INVALID_PASSWORD
  const wrongPass = handleAuthEndpoint(ctx, 'POST', '/api/v1/auth/change-password', {
    current_password: 'wrong_password',
    new_password: 'newPass456',
  }, { sub: 3, role: 'student' });
  assert.equal(wrongPass.status, 401);
  assert.equal(wrongPass.body.error.code, 'INVALID_PASSWORD');

  // 3d. change-password: success -> 200
  const okPass = handleAuthEndpoint(ctx, 'POST', '/api/v1/auth/change-password', {
    current_password: 'stud123',
    new_password: 'newPass456',
  }, { sub: 3, role: 'student' });
  assert.equal(okPass.status, 200);
  assert.equal(okPass.body.success, true);

  // 3e. profile update (student): normalizes Persian digits, syncs username (since username === old phone) & students table, returns me() structure
  const studentProfile = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', {
    full_name: 'دانش‌آموز ویرایش‌شده',
    phone: '+98 ۹۱۲ ۳۳۳ ۴۴۵۵',
  }, { sub: 3, role: 'student' });
  assert.equal(studentProfile.status, 200);
  assert.equal(studentProfile.body.data.full_name, 'دانش‌آموز ویرایش‌شده');
  assert.equal(studentProfile.body.data.phone, '09123334455');
  assert.equal(studentProfile.body.data.username, '09123334455');
  assert.equal(studentProfile.body.data.student_id, 20);
  assert.equal(ctx.students.find((s) => s.id === 20).name, 'دانش‌آموز ویرایش‌شده');
  assert.equal(ctx.students.find((s) => s.id === 20).phone, '09123334455');

  // 3f. profile update: duplicate phone in other users -> 409 DUPLICATE_PHONE
  const dupPhone = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', {
    full_name: 'دانش‌آموز ویرایش‌شده',
    phone: '09120000001', // belongs to admin (user id 1)
  }, { sub: 3, role: 'student' });
  assert.equal(dupPhone.status, 409);
  assert.equal(dupPhone.body.error.code, 'DUPLICATE_PHONE');

  // 3g. profile update (2FA role: admin/supporter/teacher): changing phone without current_password -> 422 VALIDATION_ERROR
  const adminNoPass = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', {
    full_name: 'ادمین جدید',
    phone: '09129998877',
  }, { sub: 1, role: 'admin' });
  assert.equal(adminNoPass.status, 422);
  assert.equal(adminNoPass.body.error.code, 'VALIDATION_ERROR');

  // 3h. profile update (2FA role): changing phone with wrong current_password -> 401 INVALID_PASSWORD
  const adminWrongPass = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', {
    full_name: 'ادمین جدید',
    phone: '09129998877',
    current_password: 'wrong',
  }, { sub: 1, role: 'admin' });
  assert.equal(adminWrongPass.status, 401);
  assert.equal(adminWrongPass.body.error.code, 'INVALID_PASSWORD');

  // 3i. profile update (2FA role): changing phone with valid current_password -> 200 & preserves custom username ('admin_root')
  const adminOk = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', {
    full_name: 'ادمین جدید',
    phone: '09129998877',
    current_password: 'admin123',
  }, { sub: 1, role: 'admin' });
  assert.equal(adminOk.status, 200);
  assert.equal(adminOk.body.data.phone, '09129998877');
  assert.equal(adminOk.body.data.username, 'admin_root');
  assert.equal(ctx.admins.find((a) => a.id === 1).name, 'ادمین جدید');
  assert.equal(ctx.admins.find((a) => a.id === 1).phone, '09129998877');

  // 3j. profile update (teacher): syncs instructors.name while storing phone in users.phone
  const teacherOk = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', {
    name: 'استاد ریاضی جدید',
    phone: '09120000004', // unchanged phone -> no current_password needed
  }, { sub: 4, role: 'teacher' });
  assert.equal(teacherOk.status, 200);
  assert.equal(teacherOk.body.data.full_name, 'استاد ریاضی جدید');
  assert.equal(teacherOk.body.data.instructor_id, 30);
  assert.equal(ctx.instructors.find((i) => i.id === 30).name, 'استاد ریاضی جدید');
});

test('Exam Comments & Share Links Stage 1 — Migration 006, PUT /api/v1/exams/comments, and GET /api/v1/exams/details comments field', () => {
  const mig006 = fs.readFileSync(
    new URL('./database/migrations/006_exam_comments_and_share_links.sql', import.meta.url),
    'utf8'
  );
  const schemaSql = fs.readFileSync(new URL('./database/schema.postgres.sql', import.meta.url), 'utf8');
  const authPhp = fs.readFileSync(new URL('./app/Core/Authorization.php', import.meta.url), 'utf8');
  const examResPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamResult.php', import.meta.url), 'utf8');
  const examSvcPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamService.php', import.meta.url), 'utf8');
  const examCtrlPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamController.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');

  // 1. Verify Migration 006 (exam_comments & exam_result_links)
  assert.match(mig006, /CREATE TABLE IF NOT EXISTS exam_comments/);
  assert.match(
    mig006,
    /CONSTRAINT uk_exam_comments_student_date_teacher UNIQUE \(student_id,\s*exam_date,\s*teacher_user_id\)/
  );
  assert.match(mig006, /CREATE TABLE IF NOT EXISTS exam_result_links/);
  assert.match(mig006, /token_hash\s+CHAR\(64\)\s+NOT NULL UNIQUE/);
  assert.match(mig006, /expires_at\s+TIMESTAMP NOT NULL/);
  assert.match(mig006, /revoked_at\s+TIMESTAMP DEFAULT NULL/);
  assert.match(mig006, /sms_sent_at\s+TIMESTAMP DEFAULT NULL/);
  assert.match(mig006, /created_by\s+BIGINT/);
  assert.match(mig006, /last_viewed_at\s+TIMESTAMP DEFAULT NULL/);
  assert.match(schemaSql, /006_exam_comments_and_share_links\.sql/);

  // 2. Verify PHP Authorization, Model, Service, Controller, and Routes
  assert.match(authPhp, /'staff'\s*=>\s*\[\s*'roles'\s*=>\s*\['admin',\s*'supporter',\s*'teacher'\]/);
  assert.match(examResPhp, /ON CONFLICT \(student_id, exam_date, teacher_user_id\)/);
  assert.match(examResPhp, /'comments'\s*=>\s*\$comments/);
  assert.match(examSvcPhp, /public function upsertComment\(int \$studentId,\s*string \$examDate,\s*int \$teacherUserId,\s*string \$body\): array/);
  assert.match(examCtrlPhp, /public function upsertComment\(Request \$request,\s*Response \$response\): Response/);
  assert.match(
    routesPhp,
    /\$app->put\('\/api\/v1\/exams\/comments',\s*\[\$examController,\s*'upsertComment'\]\)->add\(\$requireStaff\)->add\(\$authMiddleware\)/
  );

  // 3. Verify OpenAPI & README
  assert.match(rootSpec, /\/api\/v1\/exams\/comments:/);
  assert.match(rootSpec, /operationId:\s*examUpsertComment/);
  assert.match(rootSpec, /ExamComment:/);
  assert.match(rootSpec, /ExamCommentUpsertRequest:/);
  assert.match(readmeMd, /\/api\/v1\/exams\/comments/);

  // 4. Behavioral verification via simulator
  const ctx = createAppContext();
  const commentUrl = new URL('http://localhost:3000/api/v1/exams/comments');

  // 4a. Unauthenticated -> 401
  const unauthRes = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: 'نظر تستی',
  }, null);
  assert.equal(unauthRes.status, 401);

  // 4b. Student role -> 403 FORBIDDEN
  const studentRes = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: 'نظر دانش‌آموز غیرمجاز',
  }, { sub: 3, role: 'student' });
  assert.equal(studentRes.status, 403);
  assert.equal(studentRes.body.error.code, 'FORBIDDEN');

  // 4c. Validation error (empty body) -> 422
  const invalidRes = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: '   ',
  }, { sub: 4, role: 'teacher' });
  assert.equal(invalidRes.status, 422);
  assert.equal(invalidRes.body.error.code, 'VALIDATION_ERROR');

  // 4d. Missing exam on date -> 404 NOT_FOUND
  const missingExamRes = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-01-01',
    body: 'نظر روی آزمون ناموجود',
  }, { sub: 4, role: 'teacher' });
  assert.equal(missingExamRes.status, 404);
  assert.equal(missingExamRes.body.error.code, 'NOT_FOUND');

  // 4e. Teacher creates comment -> 200
  const teacherComment1 = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: 'در درس ریاضی پیشرفت بسیار خوبی داشته‌اید.',
  }, { sub: 4, role: 'teacher' });
  assert.equal(teacherComment1.status, 200);
  assert.equal(teacherComment1.body.data.comment.author_name, 'استاد نمونه');
  assert.equal(teacherComment1.body.data.comment.author_role, 'teacher');
  assert.equal(teacherComment1.body.data.comments.length, 1);

  // 4f. Same teacher updates comment on same (student_id, exam_date) -> upserts in place (still 1 comment from teacher)
  const teacherComment2 = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: 'در درس ریاضی و فیزیک پیشرفت عالی داشته‌اید؛ تست‌های زمان‌دار بیشتری کار کنید.',
  }, { sub: 4, role: 'teacher' });
  assert.equal(teacherComment2.status, 200);
  assert.equal(teacherComment2.body.data.comments.length, 1);
  assert.equal(
    teacherComment2.body.data.comment.body,
    'در درس ریاضی و فیزیک پیشرفت عالی داشته‌اید؛ تست‌های زمان‌دار بیشتری کار کنید.'
  );

  // 4g. Supporter also adds a comment on the same exam -> 2 comments total
  const supporterComment = handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: 'پیگیری برنامه هفتگی طبق زمان‌بندی انجام شد.',
  }, { sub: 2, role: 'supporter' });
  assert.equal(supporterComment.status, 200);
  assert.equal(supporterComment.body.data.comments.length, 2);

  // 4h. GET /api/v1/exams/details returns backward-compatible student, subjects, avg_percentage, AND comments
  const detailsUrl = new URL('http://localhost:3000/api/v1/exams/details?student_id=20&exam_date=2026-10-25');
  const detailsRes = handleExamEndpoint(ctx, 'GET', detailsUrl, {}, { sub: 3, role: 'student' });
  assert.equal(detailsRes.status, 200);
  assert.equal(detailsRes.body.data.student.id, 20);
  assert.equal(detailsRes.body.data.subjects.length, 2);
  assert.equal(detailsRes.body.data.avg_percentage, 65);
  assert.equal(detailsRes.body.data.comments.length, 2);
});

