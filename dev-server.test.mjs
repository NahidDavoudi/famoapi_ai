import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
  createAppContext,
  handlePublicEndpoint,
  handleHealthEndpoint,
  handleAuthEndpoint,
  handleExamEndpoint,
  handlePublicExamResultEndpoint,
  handleAppointmentSmsEndpoint,
  handleTutoringClassroomEndpoint,
  handleAdminActivityLogEndpoint,
  handleSupporterEndpoint,
  handleSubscriptionEndpoint,
  handleSmsPatternEndpoint,
  recordStaffActivity,
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

test('MySQL schema, migrations, upgrade script, and database configuration are properly configured', () => {
  const migrationFiles = [
    './database/migrations/001_core_identity_and_users.sql',
    './database/migrations/002_academic_and_planner.sql',
    './database/migrations/003_courses_blog_and_public.sql',
    './database/migrations/004_attendance_and_tutoring.sql',
    './database/migrations/005_telegram_bot_threads_and_outbox.sql',
    './database/migrations/006_exam_comments_and_share_links.sql',
    './database/migrations/007_appointment_sms_log.sql',
  ];
  const combinedMigrationsSql = migrationFiles
    .map((rel) => fs.readFileSync(new URL(rel, import.meta.url), 'utf8'))
    .join('\n');
  const upgradeSql = fs.readFileSync(new URL('./database/mysql_upgrade_from_dump.sql', import.meta.url), 'utf8');
  const dbConfig = fs.readFileSync(new URL('./config/database.php', import.meta.url), 'utf8');
  const dbCore = fs.readFileSync(new URL('./app/Core/Database.php', import.meta.url), 'utf8');
  const migratorCore = fs.readFileSync(new URL('./app/Core/Migrator.php', import.meta.url), 'utf8');

  // Verify MySQL InnoDB tables, AUTO_INCREMENT PKs, and upgrade script
  assert.match(combinedMigrationsSql, /AUTO_INCREMENT/);
  assert.match(combinedMigrationsSql, /ENGINE=InnoDB/);
  assert.match(combinedMigrationsSql, /UNIQUE KEY uk_active_student/);
  assert.match(combinedMigrationsSql, /UNIQUE KEY uq_sess_one_open_per_student/);
  assert.match(combinedMigrationsSql, /KEY idx_users_phone \(phone\)/);
  assert.match(upgradeSql, /CREATE TABLE IF NOT EXISTS `exam_comments`/);
  assert.match(upgradeSql, /CREATE TABLE IF NOT EXISTS `exam_result_links`/);
  assert.match(upgradeSql, /CREATE TABLE IF NOT EXISTS `appointment_sms_log`/);

  // Verify config and core default to mysql driver and support GET_LOCK
  assert.match(dbConfig, /'driver'\s*=>\s*\$_ENV\['DB_DRIVER'\]\s*\?\?\s*'mysql'/);
  assert.match(dbCore, /mysql:host=/);
  assert.match(migratorCore, /GET_LOCK\('famo_schema_migrations'/);
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
  assert.match(authSvc, /public function changePassword\(int \$userId,\s*string \$currentPassword,\s*string \$newPassword\): array/);
  assert.match(authSvc, /public function updateProfile\(int \$userId,\s*array \$data\): array/);
  assert.match(authSvc, /INVALID_PASSWORD/);
  assert.match(authSvc, /DUPLICATE_PHONE/);
  assert.match(authSvc, /\$twoFaRoles = \['admin', 'supporter', 'teacher'\]/);
  assert.match(authSvc, /UPDATE admins SET name = :name, phone = :phone WHERE id = :id/);
  assert.match(authSvc, /UPDATE supporters SET name = :name, phone = :phone WHERE id = :id/);
  assert.match(authSvc, /UPDATE students SET name = :name, phone = :phone WHERE id = :id/);
  assert.match(authSvc, /UPDATE instructors SET name = :name WHERE id = :id/);
  assert.match(routesPhp, /\$app->post\('\/api\/v1\/auth\/change-password',\s*\[\$authController,\s*'changePassword'\]\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/);
  assert.match(routesPhp, /\$app->put\('\/api\/v1\/auth\/profile',\s*\[\$authController,\s*'updateProfile'\]\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/);

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
    /UNIQUE KEY uk_exam_comments_student_date_teacher \(student_id,\s*exam_date,\s*teacher_user_id\)/
  );
  assert.match(mig006, /CREATE TABLE IF NOT EXISTS exam_result_links/);
  assert.match(mig006, /token_hash\s+CHAR\(64\)\s+NOT NULL/);
  assert.match(mig006, /UNIQUE KEY uk_exam_result_links_token_hash \(token_hash\)/);
  assert.match(mig006, /expires_at\s+DATETIME NOT NULL/);
  assert.match(mig006, /revoked_at\s+DATETIME DEFAULT NULL/);
  assert.match(mig006, /sms_sent_at\s+DATETIME DEFAULT NULL/);
  assert.match(mig006, /created_by\s+INT/);
  assert.match(mig006, /last_viewed_at\s+DATETIME DEFAULT NULL/);

  // 2. Verify PHP Authorization, Model, Service, Controller, and Routes
  assert.match(authPhp, /'staff'\s*=>\s*\[\s*'roles'\s*=>\s*\['admin',\s*'supporter',\s*'teacher'\]/);
  assert.match(examResPhp, /ON DUPLICATE KEY UPDATE/);
  assert.match(examResPhp, /'comments'\s*=>\s*\$comments/);
  assert.match(examSvcPhp, /public function upsertComment\(int \$studentId,\s*string \$examDate,\s*int \$teacherUserId,\s*string \$body\): array/);
  assert.match(examCtrlPhp, /public function upsertComment\(Request \$request,\s*Response \$response\): Response/);
  assert.match(
    routesPhp,
    /\$app->put\('\/api\/v1\/exams\/comments',\s*\[\$examController,\s*'upsertComment'\]\)->add\(\$requireStaff\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/
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

test('Exam Comments & Share Links Stage 2 — POST /api/v1/exams/share-links and GET /api/v1/public/exam-results/{token}', () => {
  const envExample = fs.readFileSync(new URL('./.env.example', import.meta.url), 'utf8');
  const envValidatorPhp = fs.readFileSync(new URL('./app/Core/EnvValidator.php', import.meta.url), 'utf8');
  const pubCachePhp = fs.readFileSync(new URL('./app/Core/PublicCacheMiddleware.php', import.meta.url), 'utf8');
  const examResPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamResult.php', import.meta.url), 'utf8');
  const examSvcPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamService.php', import.meta.url), 'utf8');
  const examCtrlPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamController.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const publicSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');
  const examTestPhp = fs.readFileSync(new URL('./tests/Core/ExamServiceTest.php', import.meta.url), 'utf8');

  // 1. Verify PHP & Env Implementation (SPA route /r/{token}, PUBLIC_SITE_URL, headers, distinct internal 404 logs)
  assert.match(envExample, /^PUBLIC_SITE_URL=/m);
  assert.match(envValidatorPhp, /'PUBLIC_SITE_URL'/);
  assert.match(envValidatorPhp, /public static function requirePublicSiteUrl\(\): string/);
  assert.match(pubCachePhp, /str_starts_with\(\$path,\s*'\/api\/v1\/public\/exam-results\/'\)/);
  assert.match(examResPhp, /public static function createShareLink\(/);
  assert.match(examResPhp, /public static function findShareLinkByHash\(string \$tokenHash\): \?array/);
  assert.match(examResPhp, /public static function findActiveShareLinkByHash\(string \$tokenHash\): \?array/);
  assert.match(examResPhp, /public static function findPublicResult\(int \$studentId,\s*string \$examDate\): \?array/);
  assert.match(examSvcPhp, /random_bytes\(32\)/);
  assert.match(examSvcPhp, /hash\('sha256',\s*\$rawToken\)/);
  assert.match(examSvcPhp, /14\s*\*\s*86400/);
  assert.match(examSvcPhp, /EnvValidator::requirePublicSiteUrl\(\)/);
  assert.match(examSvcPhp, /'\/r\/'\s*\.\s*\$rawToken/);
  assert.match(examSvcPhp, /logShareLinkRejection\('malformed'/);
  assert.match(examSvcPhp, /logShareLinkRejection\('unknown_token'/);
  assert.match(examSvcPhp, /logShareLinkRejection\('revoked'/);
  assert.match(examSvcPhp, /logShareLinkRejection\('expired'/);
  assert.match(examSvcPhp, /logShareLinkRejection\('result_missing'/);
  assert.match(examCtrlPhp, /'Cache-Control',\s*'no-store, no-cache, must-revalidate'/);
  assert.match(examCtrlPhp, /'X-Robots-Tag',\s*'noindex, nofollow'/);
  assert.match(examCtrlPhp, /'Referrer-Policy',\s*'no-referrer'/);
  assert.match(examTestPhp, /testCreateShareLinkUsesPublicSiteUrlSpaRouteAndRequiresEnvInProduction/);
  assert.match(examTestPhp, /testPublicSharedResultIsMultiUseAndLogsDistinctInternal404Reasons/);
  assert.match(
    routesPhp,
    /\$app->post\('\/api\/v1\/exams\/share-links',\s*\[\$examController,\s*'createShareLink'\]\)->add\(\$smsShareIpLimit\)->add\(\$requireSupporter\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/
  );
  assert.match(
    routesPhp,
    /\$app->get\('\/api\/v1\/public\/exam-results\/\{token\}',\s*\[\$examController,\s*'getPublicSharedResult'\]\)\s*->add\(\$publicExamLimit\)/
  );

  // 2. Verify OpenAPI & README
  assert.equal(rootSpec, publicSpec);
  assert.match(rootSpec, /\/api\/v1\/exams\/share-links:/);
  assert.match(rootSpec, /operationId:\s*examCreateShareLink/);
  assert.match(rootSpec, /\/api\/v1\/public\/exam-results\/\{token\}:/);
  assert.match(rootSpec, /operationId:\s*publicGetSharedExamResult/);
  assert.match(rootSpec, /Referrer-Policy:/);
  assert.match(rootSpec, /ExamShareLinkCreateRequest:/);
  assert.match(rootSpec, /ExamShareLinkResponse:/);
  assert.match(rootSpec, /PublicSharedExamResult:/);
  assert.match(readmeMd, /\/api\/v1\/exams\/share-links/);
  assert.match(readmeMd, /\/api\/v1\/public\/exam-results\/\{token\}/);

  // 3. Behavioral verification via simulator
  const ctx = createAppContext();
  ctx.publicSiteUrl = 'https://famoacademy.ir';
  const shareUrl = new URL('http://localhost:3000/api/v1/exams/share-links');
  const commentUrl = new URL('http://localhost:3000/api/v1/exams/comments');

  // Seed a teacher comment so public view includes comments
  handleExamEndpoint(ctx, 'PUT', commentUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    body: 'عملکرد در ریاضی بسیار خوب بود.',
  }, { sub: 4, role: 'teacher' });

  // 3a. Unauthenticated POST /api/v1/exams/share-links -> 401
  const unauthShare = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
  }, null);
  assert.equal(unauthShare.status, 401);

  // 3b. Student and Teacher roles -> 403 FORBIDDEN (only admin/supporter allowed)
  const studentShare = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
  }, { sub: 3, role: 'student' });
  assert.equal(studentShare.status, 403);

  const teacherShare = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
  }, { sub: 4, role: 'teacher' });
  assert.equal(teacherShare.status, 403);

  // 3c. Validation error -> 422
  const invalidShare = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: 'invalid-date',
  }, { sub: 2, role: 'supporter' });
  assert.equal(invalidShare.status, 422);

  // 3c-bis. Production fail-loud when PUBLIC_SITE_URL is missing
  ctx.appEnv = 'production';
  ctx.publicSiteUrl = '';
  const missingSiteUrlRes = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
  }, { sub: 2, role: 'supporter' });
  assert.equal(missingSiteUrlRes.status, 500);
  assert.equal(missingSiteUrlRes.body.error.code, 'ENV_CONFIGURATION_ERROR');
  ctx.publicSiteUrl = 'https://famoacademy.ir';

  // 3d. Supporter creates share link -> 201 Created with SPA URL {PUBLIC_SITE_URL}/r/{token}, only SHA-256 stored in DB
  const createRes = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
  }, { sub: 2, role: 'supporter' });
  assert.equal(createRes.status, 201);
  const rawToken = createRes.body.data.token;
  assert.match(rawToken, /^[A-Za-z0-9_-]{43}$/);
  assert.equal(createRes.body.data.share_path, `/r/${rawToken}`);
  assert.equal(createRes.body.data.api_path, `/api/v1/public/exam-results/${rawToken}`);
  assert.equal(createRes.body.data.url, `https://famoacademy.ir/r/${rawToken}`);
  assert.equal(createRes.body.data.share_url, `https://famoacademy.ir/r/${rawToken}`);
  assert.equal(ctx.examResultLinks.length, 1);
  assert.notEqual(ctx.examResultLinks[0].token_hash, rawToken);
  assert.match(ctx.examResultLinks[0].token_hash, /^[a-f0-9]{64}$/);
  assert.equal(ctx.examResultLinks[0].sms_sent_at, null);
  assert.equal(ctx.examResultLinks[0].last_viewed_at, null);

  // 3e. GET /api/v1/public/exam-results/{token} returns 200 with privacy-safe payload, no-store/noindex/no-referrer headers, and is multi-use
  const pubRes = handlePublicExamResultEndpoint(ctx, rawToken, '198.51.100.25');
  assert.equal(pubRes.status, 200);
  assert.equal(pubRes.headers['Cache-Control'], 'no-store, no-cache, must-revalidate');
  assert.equal(pubRes.headers['X-Robots-Tag'], 'noindex, nofollow');
  assert.equal(pubRes.headers['Referrer-Policy'], 'no-referrer');
  assert.equal(pubRes.headers['X-Cache'], undefined);
  assert.equal(pubRes.headers['X-RateLimit-Limit'], '20');
  assert.equal(pubRes.body.data.student_name, 'دانش‌آموز نمونه');
  assert.equal(pubRes.body.data.exam_date, '2026-10-25');
  assert.equal(pubRes.body.data.avg_percentage, 65);
  assert.equal(pubRes.body.data.subjects.length, 2);
  assert.equal(pubRes.body.data.comments.length, 1);
  // Privacy guard: no national_id, phone, or student id exposed
  assert.equal(pubRes.body.data.student.national_id, undefined);
  assert.equal(pubRes.body.data.student.phone, undefined);
  assert.equal(pubRes.body.data.national_id, undefined);
  assert.equal(pubRes.body.data.phone, undefined);
  assert.notEqual(ctx.examResultLinks[0].last_viewed_at, null);

  // Multi-use: a second GET with the same token still succeeds and keeps revoked_at = null
  const pubResSecond = handlePublicExamResultEndpoint(ctx, rawToken, '198.51.100.25');
  assert.equal(pubResSecond.status, 200);
  assert.equal(ctx.examResultLinks[0].revoked_at, null);

  // 3f. Uniform 404 (with no-referrer/no-store/noindex) and distinct internal log reasons for malformed, unknown, expired, or revoked tokens
  const malformedRes = handlePublicExamResultEndpoint(ctx, 'short-token', '198.51.100.25');
  const unknownRes = handlePublicExamResultEndpoint(
    ctx,
    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq',
    '198.51.100.25'
  );
  assert.equal(malformedRes.status, 404);
  assert.equal(unknownRes.status, 404);
  assert.equal(malformedRes.headers['Referrer-Policy'], 'no-referrer');
  assert.deepEqual(malformedRes.body, unknownRes.body);
  assert.equal(ctx.shareLinkLogs.at(-2).reason, 'malformed');
  assert.equal(ctx.shareLinkLogs.at(-1).reason, 'unknown_token');

  // Expired token -> identical 404 + internal 'expired' reason
  const savedExpires = ctx.examResultLinks[0].expires_at;
  ctx.examResultLinks[0].expires_at = '2020-01-01 00:00:00';
  const expiredRes = handlePublicExamResultEndpoint(ctx, rawToken, '198.51.100.25');
  assert.equal(expiredRes.status, 404);
  assert.deepEqual(expiredRes.body, unknownRes.body);
  assert.equal(ctx.shareLinkLogs.at(-1).reason, 'expired');
  ctx.examResultLinks[0].expires_at = savedExpires;

  // Revoked token -> identical 404 + internal 'revoked' reason
  ctx.examResultLinks[0].revoked_at = '2026-10-26 12:00:00';
  const revokedRes = handlePublicExamResultEndpoint(ctx, rawToken, '198.51.100.25');
  assert.equal(revokedRes.status, 404);
  assert.deepEqual(revokedRes.body, unknownRes.body);
  assert.equal(ctx.shareLinkLogs.at(-1).reason, 'revoked');
  ctx.examResultLinks[0].revoked_at = null;

  // Ensure client 404 response body never leaks internal rejection reason strings
  for (const res of [malformedRes, unknownRes, expiredRes, revokedRes]) {
    const serialized = JSON.stringify(res.body);
    for (const leakedReason of ['malformed', 'unknown_token', 'expired', 'revoked', 'result_missing']) {
      assert.equal(serialized.includes(leakedReason), false);
    }
  }

  // 3g. Stricter rate limit (maxAttempts = 20 per 60s window; attempts >= 20 locks out with 429)
  const testIp = '203.0.113.77';
  for (let i = 1; i < 20; i++) {
    const r = handlePublicExamResultEndpoint(ctx, rawToken, testIp);
    assert.equal(r.status, 200);
  }
  const rateLimitedRes = handlePublicExamResultEndpoint(ctx, rawToken, testIp);
  assert.equal(rateLimitedRes.status, 429);
  assert.equal(rateLimitedRes.body.error.code, 'RATE_LIMITED');
});

test('Exam Comments & Share Links Stage 3 — Melipayamak SMS dispatch, SMS rate limiting, and share link panel management', () => {
  const envExample = fs.readFileSync(new URL('./.env.example', import.meta.url), 'utf8');
  const smsPhp = fs.readFileSync(new URL('./app/Core/SmsService.php', import.meta.url), 'utf8');
  const patternSettingsPhp = fs.readFileSync(new URL('./app/Core/SmsPatternSettings.php', import.meta.url), 'utf8');
  const mig012 = fs.readFileSync(new URL('./database/migrations/012_sms_pattern_settings.sql', import.meta.url), 'utf8');
  const upgradeSql = fs.readFileSync(new URL('./database/mysql_upgrade_from_dump.sql', import.meta.url), 'utf8');
  const examResPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamResult.php', import.meta.url), 'utf8');
  const examSvcPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamService.php', import.meta.url), 'utf8');
  const examCtrlPhp = fs.readFileSync(new URL('./app/Modules/Exams/ExamController.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');

  // 1. Verify .env.example & PHP Melipayamak SmsService implementation
  assert.match(envExample, /MELIPAYAMAK_EXAM_BODY_ID=/);
  assert.match(envExample, /EXAM_SMS_PHONE_HOURLY_MAX=/);
  assert.match(envExample, /EXAM_SMS_IP_HOURLY_MAX=/);
  assert.match(
    smsPhp,
    /public function sendExamResultLink\(string \$mobile,\s*string \$studentName,\s*string \$examDate,\s*string \$shareUrl\): bool/
  );
  assert.match(smsPhp, /https:\/\/rest\.payamak-panel\.com\/api\/SendSMS\/BaseServiceNumber/);
  assert.match(smsPhp, /MELIPAYAMAK_EXAM_BODY_ID/);
  // Verify SMS exam pattern text includes whitespace/non-URL separator after {2}
  assert.match(patternSettingsPhp, /کارنامه آزمون \{0\} در تاریخ \{1\}:\\n\{2\}\\nآکادمی فامو/);
  assert.match(mig012, /کارنامه آزمون \{0\} در تاریخ \{1\}:\\n\{2\}\\nآکادمی فامو/);
  assert.match(upgradeSql, /کارنامه آزمون \{0\} در تاریخ \{1\}:\\n\{2\}\\nآکادمی فامو/);

  // 2. Verify ExamResult, ExamService, ExamController, and Routes
  assert.match(examResPhp, /public static function markShareLinkSmsSent\(int \$linkId/);
  assert.match(examResPhp, /public static function listShareLinks\(/);
  assert.match(examResPhp, /public static function revokeShareLink\(int \$linkId\): bool/);
  assert.match(examSvcPhp, /public function sendShareLinkSms\(/);
  assert.match(examSvcPhp, /private function resolveParentMobile\(/);
  assert.match(examSvcPhp, /private function enforceSmsRateLimits\(/);
  assert.match(examSvcPhp, /exam_sms:cooldown/);
  assert.match(examSvcPhp, /exam_sms:phone/);
  assert.match(examSvcPhp, /exam_sms:ip/);
  assert.match(examCtrlPhp, /public function sendShareLinkSms\(/);
  assert.match(examCtrlPhp, /public function listShareLinks\(/);
  assert.match(examCtrlPhp, /public function revokeShareLink\(/);
  assert.match(routesPhp, /\/api\/v1\/exams\/share-links\/send-sms/);
  assert.match(routesPhp, /\/api\/v1\/exams\/share-links\/\{id:\[0-9\]\+\}/);

  // 3. Verify OpenAPI & README
  assert.match(rootSpec, /\/api\/v1\/exams\/share-links\/send-sms:/);
  assert.match(rootSpec, /operationId:\s*examSendShareLinkSms/);
  assert.match(rootSpec, /operationId:\s*examListShareLinks/);
  assert.match(rootSpec, /operationId:\s*examRevokeShareLink/);
  assert.match(rootSpec, /ExamShareLinkItem:/);
  assert.match(readmeMd, /\/api\/v1\/exams\/share-links\/send-sms/);

  // 4. Behavioral verification via simulator
  const ctx = createAppContext();
  ctx.publicSiteUrl = 'https://famoacademy.ir';
  assert.match(ctx.smsPatterns.exam_result.pattern_text, /\{2\}\s+\S/);
  const sendSmsUrl = new URL('http://localhost:3000/api/v1/exams/share-links/send-sms');
  const shareUrl = new URL('http://localhost:3000/api/v1/exams/share-links');

  // 4a. Role enforcement on SMS endpoint -> 403 for student/teacher
  const teacherSms = handleExamEndpoint(ctx, 'POST', sendSmsUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
  }, { sub: 4, role: 'teacher' });
  assert.equal(teacherSms.status, 403);

  // 4b. Dispatch SMS using primary parent contact (09121112233) via Melipayamak
  const smsRes1 = handleExamEndpoint(ctx, 'POST', sendSmsUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    ip: '198.51.100.80',
  }, { sub: 2, role: 'supporter' });
  assert.equal(smsRes1.status, 201);
  assert.equal(smsRes1.body.data.sms_sent, true);
  assert.notEqual(smsRes1.body.data.sms_sent_at, null);
  assert.equal(smsRes1.body.data.recipient_phone_mask, '0912***33');
  assert.equal(ctx.smsDispatches.length, 1);
  assert.equal(ctx.smsDispatches[0].provider, 'melipayamak');
  assert.equal(ctx.smsDispatches[0].to, '09121112233');
  assert.match(ctx.smsDispatches[0].text, /^دانش‌آموز نمونه;2026-10-25;https:\/\/famoacademy\.ir\/r\/[A-Za-z0-9_-]{43}$/);

  // 4c. Cooldown protection: immediate duplicate SMS to same (student_id, exam_date, mobile) -> 429 RATE_LIMITED
  const duplicateSms = handleExamEndpoint(ctx, 'POST', sendSmsUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    ip: '198.51.100.80',
  }, { sub: 2, role: 'supporter' });
  assert.equal(duplicateSms.status, 429);
  assert.equal(duplicateSms.body.error.code, 'RATE_LIMITED');

  // 4d. Dispatch SMS to secondary parent_contact_id (id: 2 -> mother 09124445566) via POST /api/v1/exams/share-links with send_sms: true
  const smsRes2 = handleExamEndpoint(ctx, 'POST', shareUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    send_sms: true,
    parent_contact_id: 2,
    ip: '198.51.100.80',
  }, { sub: 1, role: 'admin' });
  assert.equal(smsRes2.status, 201);
  assert.equal(smsRes2.body.data.sms_sent, true);
  assert.equal(smsRes2.body.data.recipient_phone_mask, '0912***66');
  assert.equal(ctx.smsDispatches.length, 2);
  assert.equal(ctx.smsDispatches[1].to, '09124445566');

  // 4e. Invalid explicit phone -> 422 VALIDATION_ERROR
  const badPhoneSms = handleExamEndpoint(ctx, 'POST', sendSmsUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    phone: '12345',
  }, { sub: 2, role: 'supporter' });
  assert.equal(badPhoneSms.status, 422);
  assert.equal(badPhoneSms.body.error.code, 'VALIDATION_ERROR');

  // 4f. Hourly phone rate limit (smsPhoneHourlyMax = 1 -> 1st succeeds, 2nd within hour returns 429 RATE_LIMITED)
  ctx.smsPhoneHourlyMax = 1;
  const customPhone = '+98 ۹۱۲ ۷۷۷ ۸۸۹۹'; // normalizes to 09127778899
  const customSms1 = handleExamEndpoint(ctx, 'POST', sendSmsUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    phone: customPhone,
    ip: '198.51.100.81',
  }, { sub: 2, role: 'supporter' });
  assert.equal(customSms1.status, 201);
  assert.equal(customSms1.body.data.recipient_phone_mask, '0912***99');

  // Clear cooldown to test hourly phone cap on the 2nd attempt
  ctx.rateLimiter.clear(ctx.rateLimiter.key('exam_sms:cooldown', '20:2026-10-25:09127778899'));
  const customSms2 = handleExamEndpoint(ctx, 'POST', sendSmsUrl, {
    student_id: 20,
    exam_date: '2026-10-25',
    phone: customPhone,
    ip: '198.51.100.81',
  }, { sub: 2, role: 'supporter' });
  assert.equal(customSms2.status, 429);
  assert.equal(customSms2.body.error.code, 'RATE_LIMITED');

  // 4g. Panel GET /api/v1/exams/share-links lists links with status and sms_sent_at (without token_hash)
  const listUrl = new URL('http://localhost:3000/api/v1/exams/share-links?student_id=20');
  const listRes = handleExamEndpoint(ctx, 'GET', listUrl, {}, { sub: 2, role: 'supporter' });
  assert.equal(listRes.status, 200);
  assert.equal(listRes.body.data.links.length, 3);
  assert.equal(listRes.body.data.links[0].status, 'active');
  assert.equal(listRes.body.data.links[0].token_hash, undefined);
  assert.notEqual(listRes.body.data.links[0].sms_sent_at, null);

  // 4h. Panel DELETE /api/v1/exams/share-links/{id} revokes link and blocks public access immediately
  const linkToRevokeId = smsRes1.body.data.id;
  const linkToRevokeToken = smsRes1.body.data.token;
  const revokeUrl = new URL(`http://localhost:3000/api/v1/exams/share-links/${linkToRevokeId}`);
  const revokeRes = handleExamEndpoint(ctx, 'DELETE', revokeUrl, {}, { sub: 2, role: 'supporter' });
  assert.equal(revokeRes.status, 200);
  assert.equal(revokeRes.body.data.link.status, 'revoked');
  assert.notEqual(revokeRes.body.data.link.revoked_at, null);

  const pubAfterRevoke = handlePublicExamResultEndpoint(ctx, linkToRevokeToken, '198.51.100.99');
  assert.equal(pubAfterRevoke.status, 404);
});

test('Weekly Appointment SMS Reminders — Migration 007, preview, bulk send modes (unsent/failed/changed), parent-only recipient, rate limits, double-click lock, and log', () => {
  const mig007 = fs.readFileSync(
    new URL('./database/migrations/007_appointment_sms_log.sql', import.meta.url),
    'utf8'
  );
  const schemaSql = fs.readFileSync(new URL('./database/schema.postgres.sql', import.meta.url), 'utf8');
  const envExample = fs.readFileSync(new URL('./.env.example', import.meta.url), 'utf8');
  const authPhp = fs.readFileSync(new URL('./app/Core/Authorization.php', import.meta.url), 'utf8');
  const smsPhp = fs.readFileSync(new URL('./app/Core/SmsService.php', import.meta.url), 'utf8');
  const apptModelPhp = fs.readFileSync(
    new URL('./app/Modules/Appointments/Appointment.php', import.meta.url),
    'utf8'
  );
  const apptSvcPhp = fs.readFileSync(
    new URL('./app/Modules/Appointments/AppointmentService.php', import.meta.url),
    'utf8'
  );
  const apptCtrlPhp = fs.readFileSync(
    new URL('./app/Modules/Appointments/AppointmentController.php', import.meta.url),
    'utf8'
  );
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const publicSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');

  // 1. Verify Migration 007 & Schema
  assert.match(mig007, /CREATE TABLE IF NOT EXISTS appointment_sms_log/);
  assert.match(
    mig007,
    /UNIQUE KEY uk_appointment_sms_log_student_week_mobile \(student_id,\s*week_date,\s*mobile\)/
  );
  assert.match(mig007, /recipient_type\s+VARCHAR\(20\)\s+NOT NULL DEFAULT 'parent'/);
  assert.match(mig007, /appointment_at\s+DATETIME/);
  assert.match(mig007, /status\s+VARCHAR\(30\)\s+NOT NULL/);

  // 2. Verify .env.example & Melipayamak SmsService
  assert.match(envExample, /MELIPAYAMAK_APPOINTMENT_BODY_ID=/);
  assert.match(envExample, /APPOINTMENT_SMS_PHONE_HOURLY_MAX=/);
  assert.match(envExample, /APPOINTMENT_SMS_USER_HOURLY_MAX=/);
  assert.match(
    smsPhp,
    /public function sendAppointmentReminder\(string \$mobile,\s*string \$studentName,\s*string \$dayAndDate,\s*string \$time\): bool/
  );
  assert.match(smsPhp, /MELIPAYAMAK_APPOINTMENT_BODY_ID/);
  assert.doesNotMatch(smsPhp, /KAVENEGAR_REMINDER_TEMPLATE/);

  // 3. Verify no secretary role added, automatic SMS removed from createOrUpdate, and routes strictly admin-only
  assert.doesNotMatch(authPhp, /secretary/);
  assert.doesNotMatch(apptSvcPhp, /\$this->sms->sendAppointmentReminder\(\$student\['phone'\]/);
  assert.match(apptModelPhp, /public static function findPrimaryParentsForStudents\(/);
  assert.match(apptModelPhp, /ORDER BY student_id ASC, is_primary DESC, id ASC/);
  assert.match(apptModelPhp, /public static function upsertSmsLog\(/);
  assert.match(apptSvcPhp, /IranDay::weekStart\(/);
  assert.match(apptSvcPhp, /appointment_sms:user/);
  assert.match(apptSvcPhp, /appointment_sms:phone/);
  assert.match(apptSvcPhp, /CONCURRENT_SEND_LOCKED/);
  assert.match(apptCtrlPhp, /public function previewSms\(/);
  assert.match(apptCtrlPhp, /public function sendSms\(/);
  assert.match(apptCtrlPhp, /public function smsLog\(/);
  assert.match(
    routesPhp,
    /\$app->get\('\/api\/v1\/appointments\/sms\/preview',\s*\[\$appointmentController,\s*'previewSms'\]\)->add\(\$requireAdmin\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/
  );
  assert.match(
    routesPhp,
    /\$app->post\('\/api\/v1\/appointments\/sms\/send',\s*\[\$appointmentController,\s*'sendSms'\]\)->add\(\$smsIpLimit\)->add\(\$requireAdmin\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/
  );
  assert.match(
    routesPhp,
    /\$app->get\('\/api\/v1\/appointments\/sms\/log',\s*\[\$appointmentController,\s*'smsLog'\]\)->add\(\$requireAdmin\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/
  );

  // 4. Verify OpenAPI & README synchronization
  assert.equal(rootSpec, publicSpec);
  assert.match(rootSpec, /\/api\/v1\/appointments\/sms\/preview:/);
  assert.match(rootSpec, /\/api\/v1\/appointments\/sms\/send:/);
  assert.match(rootSpec, /\/api\/v1\/appointments\/sms\/log:/);
  assert.match(rootSpec, /AppointmentSmsPreviewResponse:/);
  assert.match(rootSpec, /AppointmentSmsSendRequest:/);
  assert.match(rootSpec, /AppointmentSmsSendResponse:/);
  assert.match(rootSpec, /AppointmentSmsLogItem:/);
  assert.match(readmeMd, /Weekly Appointment SMS Reminders \(`Appointments`\)/);
  assert.match(readmeMd, /\/api\/v1\/appointments\/sms\/preview/);

  // 5. Behavioral verification via simulator
  const ctx = createAppContext();

  // Seed additional students & appointments for comprehensive testing:
  // Student 20 (already in ctx): has primary parent (09121112233) & secondary mother (09124445566).
  // Add a second active appointment in the same week for Student 20 to verify nearest selection & multiple_appointments=true.
  ctx.appointments.push({
    id: 2,
    student_id: 20,
    supporter_id: 10,
    appointment_date: '2026-10-27',
    start_time: '19:00:00',
    end_time: '19:30:00',
    status: 'confirmed',
    notes: 'جلسه دوم در همان هفته',
  });

  // Student 21: has primary parent (09125556677) and 1 active appointment
  ctx.students.push({
    id: 21,
    name: 'سارا احمدی',
    phone: '09120000021',
    national_id: '0012345621',
    grade: 12,
    field: 'تجربی',
    is_active: 1,
  });
  ctx.parentContacts.push({
    id: 3,
    student_id: 21,
    parent_name: 'مادر سارا',
    relationship: 'mother',
    phone: '09125556677',
    is_primary: 1,
  });
  ctx.appointments.push({
    id: 3,
    student_id: 21,
    supporter_id: 10,
    appointment_date: '2026-10-25',
    start_time: '17:15:00',
    end_time: '17:45:00',
    status: 'confirmed',
    notes: 'مشاوره تجربی',
  });

  // Student 22: has student phone (09120000022) and active appointment, but NO parent contact -> must be skipped_no_phone without falling back to student phone
  ctx.students.push({
    id: 22,
    name: 'علی رضایی',
    phone: '09120000022',
    national_id: '0012345622',
    grade: 11,
    field: 'ریاضی',
    is_active: 1,
  });
  ctx.appointments.push({
    id: 4,
    student_id: 22,
    supporter_id: 10,
    appointment_date: '2026-10-26',
    start_time: '15:00:00',
    end_time: '15:30:00',
    status: 'pending',
    notes: 'جلسه بدون شماره والد',
  });

  // Student 23: only has a cancelled appointment in this week -> no_appointment
  ctx.students.push({
    id: 23,
    name: 'مریم کاظمی',
    phone: '09120000023',
    national_id: '0012345623',
    grade: 12,
    field: 'تجربی',
    is_active: 1,
  });
  ctx.parentContacts.push({
    id: 4,
    student_id: 23,
    parent_name: 'پدر مریم',
    relationship: 'father',
    phone: '09128889900',
    is_primary: 1,
  });
  ctx.appointments.push({
    id: 5,
    student_id: 23,
    supporter_id: 10,
    appointment_date: '2026-10-26',
    start_time: '14:00:00',
    end_time: '14:30:00',
    status: 'cancelled',
    notes: 'نوبت لغوشده',
  });

  const previewUrl = new URL('http://localhost:3000/api/v1/appointments/sms/preview?week_date=2026-10-25');
  const sendUrl = new URL('http://localhost:3000/api/v1/appointments/sms/send');
  const logUrl = new URL('http://localhost:3000/api/v1/appointments/sms/log?week_date=2026-10-24');

  // 5a. Role enforcement: supporter (sub: 2), student (sub: 3), teacher (sub: 4) -> 403 FORBIDDEN
  const suppRes = handleAppointmentSmsEndpoint(ctx, 'GET', previewUrl, {}, { sub: 2, role: 'supporter' });
  assert.equal(suppRes.status, 403);
  assert.equal(suppRes.body.error.code, 'FORBIDDEN');

  const studRes = handleAppointmentSmsEndpoint(ctx, 'POST', sendUrl, { week_date: '2026-10-24', mode: 'unsent' }, { sub: 3, role: 'student' });
  assert.equal(studRes.status, 403);
  assert.equal(studRes.body.error.code, 'FORBIDDEN');

  const teachRes = handleAppointmentSmsEndpoint(ctx, 'GET', logUrl, {}, { sub: 4, role: 'teacher' });
  assert.equal(teachRes.status, 403);
  assert.equal(teachRes.body.error.code, 'FORBIDDEN');

  // 5b. Admin preview: normalizes 2026-10-25 (Sunday) to week_date 2026-10-24 (Saturday) -> 2026-10-30 (Friday)
  const prev1 = handleAppointmentSmsEndpoint(ctx, 'GET', previewUrl, {}, { sub: 1, role: 'admin' });
  assert.equal(prev1.status, 200);
  assert.equal(prev1.body.data.week_date, '2026-10-24');
  assert.equal(prev1.body.data.week_end, '2026-10-30');
  assert.equal(prev1.body.data.recipient_type, 'parent');
  assert.equal(prev1.body.data.summary.total_students, 4);
  assert.equal(prev1.body.data.summary.will_send, 2);
  assert.equal(prev1.body.data.summary.no_phone, 1);
  assert.equal(prev1.body.data.summary.no_appointment, 1);
  assert.equal(prev1.body.data.summary.multiple_appointments, 1);
  assert.equal(ctx.smsDispatches.length, 0); // Preview never sends SMS

  const item20 = prev1.body.data.items.find((i) => i.student_id === 20);
  assert.equal(item20.status, 'will_send');
  assert.equal(item20.mobile, '09121112233'); // Primary parent (father), not mother or student
  assert.equal(item20.appointment_at, '2026-10-24 16:30:00'); // Nearest of the 2 active appointments
  assert.equal(item20.multiple_appointments, true);
  assert.equal(item20.active_appointments_count, 2);
  assert.equal(item20.day_and_date_fa, 'شنبه 1405/08/02');
  assert.equal(item20.time_fa, '16:30');

  const item22 = prev1.body.data.items.find((i) => i.student_id === 22);
  assert.equal(item22.status, 'no_phone');
  assert.equal(item22.mobile, null); // No fallback to student phone 09120000022

  const item23 = prev1.body.data.items.find((i) => i.student_id === 23);
  assert.equal(item23.status, 'no_appointment'); // Cancelled appointment ignored

  // 5c. Bulk send (mode='unsent') with Melipayamak failing for Student 21's parent (09125556677):
  // Student 20 succeeds, Student 21 fails (isolated via try/catch), Student 22 is skipped_no_phone.
  ctx.smsFailPhones = ['09125556677'];
  const send1 = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-25', mode: 'unsent', operation_id: 'op-week1-initial' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(send1.status, 200);
  assert.equal(send1.body.data.summary.sent, 1);
  assert.equal(send1.body.data.summary.failed, 1);
  assert.equal(send1.body.data.summary.skipped_no_phone, 1);
  assert.equal(send1.body.data.summary.skipped, 1);

  // Verify Melipayamak payload for Student 20's parent
  assert.equal(ctx.smsDispatches.length, 1);
  assert.equal(ctx.smsDispatches[0].to, '09121112233');
  assert.equal(ctx.smsDispatches[0].body_id_env, 'MELIPAYAMAK_APPOINTMENT_BODY_ID');
  assert.equal(ctx.smsDispatches[0].text, 'دانش‌آموز نمونه;شنبه 1405/08/02;16:30');

  // 5d. Double-click protection: repeating same operation_id or active week lock -> 409 CONCURRENT_SEND_LOCKED
  const doubleClickRes = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-25', mode: 'unsent', operation_id: 'op-week1-initial' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(doubleClickRes.status, 409);
  assert.equal(doubleClickRes.body.error.code, 'CONCURRENT_SEND_LOCKED');

  ctx.redis.setex('lock:appointment_sms:2026-10-24', 60, '1');
  const activeWeekLockRes = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'unsent' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(activeWeekLockRes.status, 409);
  assert.equal(activeWeekLockRes.body.error.code, 'CONCURRENT_SEND_LOCKED');
  ctx.redis.del('lock:appointment_sms:2026-10-24');

  // 5e. Duplicate send protection: calling mode='unsent' again skips Student 20 (already_sent) and skips Student 21 (failed requires mode='failed')
  ctx.smsFailPhones = [];
  const sendDuplicate = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'unsent' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(sendDuplicate.status, 200);
  assert.equal(sendDuplicate.body.data.summary.sent, 0);
  const d20 = sendDuplicate.body.data.details.find((d) => d.student_id === 20);
  assert.equal(d20.action, 'skipped_already_sent');
  const d21 = sendDuplicate.body.data.details.find((d) => d.student_id === 21);
  assert.equal(d21.action, 'skipped_failed_requires_failed_mode');

  // 5f. Retry failed (mode='failed'): sends only to Student 21 and updates status to 'sent' with attempts = 2
  const sendFailedRetry = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'failed' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(sendFailedRetry.status, 200);
  assert.equal(sendFailedRetry.body.data.summary.sent, 1);
  assert.equal(sendFailedRetry.body.data.summary.failed, 0);
  assert.equal(ctx.smsDispatches.length, 2);
  assert.equal(ctx.smsDispatches[1].to, '09125556677');
  assert.equal(ctx.smsDispatches[1].text, 'سارا احمدی;یکشنبه 1405/08/03;17:15');

  const log21 = ctx.appointmentSmsLogs.find((l) => l.student_id === 21);
  assert.equal(log21.status, 'sent');
  assert.equal(log21.attempts, 2);

  // 5g. Appointment time changed after send -> preview shows changed_since_sent, mode='unsent' skips, mode='changed' sends update
  ctx.appointments.find((a) => a.id === 1).start_time = '18:00:00';

  const prevAfterChange = handleAppointmentSmsEndpoint(ctx, 'GET', previewUrl, {}, { sub: 1, role: 'admin' });
  const item20Changed = prevAfterChange.body.data.items.find((i) => i.student_id === 20);
  assert.equal(item20Changed.status, 'changed_since_sent');
  assert.equal(item20Changed.last_sent_appointment_at, '2026-10-24 16:30:00');
  assert.equal(item20Changed.appointment_at, '2026-10-24 18:00:00');

  const unsentAfterChange = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'unsent' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(unsentAfterChange.body.data.summary.sent, 0);
  assert.equal(
    unsentAfterChange.body.data.details.find((d) => d.student_id === 20).action,
    'skipped_changed_requires_changed_mode'
  );

  const sendChanged = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'changed' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(sendChanged.status, 200);
  assert.equal(sendChanged.body.data.summary.sent, 1);
  assert.equal(ctx.smsDispatches.length, 3);
  assert.equal(ctx.smsDispatches[2].to, '09121112233');
  assert.equal(ctx.smsDispatches[2].text, 'دانش‌آموز نمونه;شنبه 1405/08/02;18:00');

  const log20 = ctx.appointmentSmsLogs.find((l) => l.student_id === 20);
  assert.equal(log20.appointment_at, '2026-10-24 18:00:00');
  assert.equal(log20.attempts, 2);

  // 5h. Separate per-phone hourly rate limit (default 3/hour; Student 20's parent has received 2 SMS so far; set max=2 and change time again -> fails with rate limit)
  ctx.appointmentSmsPhoneHourlyMax = 2;
  ctx.appointments.find((a) => a.id === 1).start_time = '18:30:00';
  const sendRateLimited = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'changed' },
    { sub: 1, role: 'admin' }
  );
  assert.equal(sendRateLimited.status, 200);
  assert.equal(sendRateLimited.body.data.summary.failed, 1);
  assert.match(
    sendRateLimited.body.data.details.find((d) => d.student_id === 20).error,
    /سقف ارسال ساعتی برای این شماره تلفن تکمیل شده است/
  );

  // 5i. GET /api/v1/appointments/sms/log returns logs with filters
  const allLogsRes = handleAppointmentSmsEndpoint(ctx, 'GET', logUrl, {}, { sub: 1, role: 'admin' });
  assert.equal(allLogsRes.status, 200);
  assert.equal(allLogsRes.body.data.logs.length, 3); // students 20, 21, 22 (unique per student+week+mobile)

  const skippedLogsUrl = new URL(
    'http://localhost:3000/api/v1/appointments/sms/log?week_date=2026-10-24&status=skipped_no_phone'
  );
  const skippedLogsRes = handleAppointmentSmsEndpoint(ctx, 'GET', skippedLogsUrl, {}, { sub: 1, role: 'admin' });
  assert.equal(skippedLogsRes.status, 200);
  assert.equal(skippedLogsRes.body.data.logs.length, 1);
  assert.equal(skippedLogsRes.body.data.logs[0].student_id, 22);
  assert.equal(skippedLogsRes.body.data.logs[0].recipient_type, 'parent');
});

test('CORS middleware, AuthCookie, and Slim bootstrap inject CORS_ORIGINS for all requests including OPTIONS preflight', () => {
  const authCookiePhp = fs.readFileSync('app/Core/AuthCookie.php', 'utf8');
  const corsMiddlewarePhp = fs.readFileSync('app/Core/CorsMiddleware.php', 'utf8');
  const bootstrapPhp = fs.readFileSync('bootstrap/app.php', 'utf8');
  const routesPhp = fs.readFileSync('routes/api.php', 'utf8');
  const exceptionHandlerPhp = fs.readFileSync('app/Core/ExceptionHandler.php', 'utf8');
  const envExample = fs.readFileSync('.env.example', 'utf8');

  assert.match(authCookiePhp, /public static function rawCorsOrigins\(\): string/);
  assert.match(authCookiePhp, /\$_ENV\['CORS_ORIGINS'\]/);
  assert.match(authCookiePhp, /getenv\('CORS_ORIGINS'\)/);
  assert.match(authCookiePhp, /'https:\/\/auth\.famoacademy\.ir'/);
  assert.match(authCookiePhp, /foreach \(self::allowedOrigins\(\) as \$allowedItem\)/);

  assert.match(corsMiddlewarePhp, /public static function applyHeaders\(ServerRequestInterface \$request, ResponseInterface \$response\): ResponseInterface/);
  assert.match(corsMiddlewarePhp, /->withHeader\('Access-Control-Allow-Origin', \$origin\)/);
  assert.match(corsMiddlewarePhp, /->withHeader\('Access-Control-Allow-Credentials', 'true'\)/);
  assert.match(corsMiddlewarePhp, /if \(\$method === 'OPTIONS'\)/);

  assert.match(bootstrapPhp, /\$app->add\(new App\\Core\\CorsMiddleware\(\)\);/);
  assert.match(bootstrapPhp, /App\\Core\\CorsMiddleware::applyHeaders/);
  assert.match(exceptionHandlerPhp, /CorsMiddleware::applyHeaders\(\$request, \$response\)/);
  assert.match(routesPhp, /\$app->options\('\/\{routes:\.\+\}'/);
  assert.match(envExample, /CORS_ORIGINS=https:\/\/famoacademy\.ir.*https:\/\/auth\.famoacademy\.ir/);
});

test('Tutoring classrooms endpoints (POST/PUT/DELETE /api/v1/tutoring/classrooms) and classroom configuration audit logging are implemented, documented, and verified', () => {
  const mig008 = fs.readFileSync(
    'database/migrations/008_tutoring_classroom_audit_log.sql',
    'utf8'
  );
  const upgradeSql = fs.readFileSync('database/mysql_upgrade_from_dump.sql', 'utf8');
  const schemaPostgres = fs.readFileSync('database/schema.postgres.sql', 'utf8');
  const tutoringPhp = fs.readFileSync('app/Modules/Tutoring/Tutoring.php', 'utf8');
  const tutoringSvcPhp = fs.readFileSync('app/Modules/Tutoring/TutoringService.php', 'utf8');
  const tutoringCtrlPhp = fs.readFileSync('app/Modules/Tutoring/TutoringController.php', 'utf8');
  const routesPhp = fs.readFileSync('routes/api.php', 'utf8');
  const rootSpec = fs.readFileSync('openapi.yaml', 'utf8');
  const publicSpec = fs.readFileSync('public/openapi.yaml', 'utf8');

  // 1. Verify Migration 008, Upgrade SQL, and Postgres Schema
  assert.match(mig008, /CREATE TABLE IF NOT EXISTS tutoring_classroom_audit_logs/);
  assert.match(mig008, /actor_user_id/);
  assert.match(mig008, /actor_instructor_id/);
  assert.match(mig008, /changes_json\s+JSON/);
  assert.match(upgradeSql, /CREATE TABLE IF NOT EXISTS `tutoring_classroom_audit_logs`/);
  assert.match(upgradeSql, /008_tutoring_classroom_audit_log\.sql/);
  assert.match(schemaPostgres, /008_tutoring_classroom_audit_log\.sql/);

  // 2. Verify PHP Model, Service, Controller, Routes, and OpenAPI
  assert.equal(rootSpec, publicSpec);
  assert.match(tutoringPhp, /public static function createClassroom\(array \$data, \?object \$actor = null\): array/);
  assert.match(tutoringPhp, /public static function updateClassroom\(int \$classroomId, array \$data, \?object \$actor = null\): array/);
  assert.match(tutoringPhp, /public static function deleteClassroom\(int \$classroomId, \?object \$actor = null\): void/);
  assert.match(tutoringPhp, /public static function listClassroomAuditLogs\(array \$filters = \[\]\): array/);
  assert.match(tutoringPhp, /private static function recordClassroomAuditLog\(/);
  assert.match(tutoringPhp, /LEFT JOIN instructors i ON i\.id = c\.instructor_id/);
  assert.match(tutoringSvcPhp, /public function createClassroom\(array \$data, \?object \$actor = null\): array/);
  assert.match(tutoringSvcPhp, /public function updateClassroom\(int \$classroomId, array \$data, \?object \$actor = null\): array/);
  assert.match(tutoringSvcPhp, /public function deleteClassroom\(int \$classroomId, \?object \$actor = null\): void/);
  assert.match(tutoringSvcPhp, /public function listClassroomAuditLogs\(array \$filters = \[\]\): array/);
  assert.match(tutoringCtrlPhp, /public function createClassroom\(Request \$request, Response \$response\): Response/);
  assert.match(tutoringCtrlPhp, /public function updateClassroom\(Request \$request, Response \$response, array \$args\): Response/);
  assert.match(tutoringCtrlPhp, /public function deleteClassroom\(Request \$request, Response \$response, array \$args\): Response/);
  assert.match(tutoringCtrlPhp, /public function listClassroomAuditLogs\(Request \$request, Response \$response, array \$args = \[\]\): Response/);
  assert.match(routesPhp, /\$app->post\('\/api\/v1\/tutoring\/classrooms',\s*\[\$tutoringController,\s*'createClassroom'\]\)->add\(\$requireStaff\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/);
  assert.match(routesPhp, /\$app->get\('\/api\/v1\/tutoring\/classrooms\/audit-logs',\s*\[\$tutoringController,\s*'listClassroomAuditLogs'\]\)->add\(\$requireStaff\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/);
  assert.match(routesPhp, /\$app->get\('\/api\/v1\/tutoring\/classrooms\/\{id:\[0-9\]\+\}\/audit-logs',\s*\[\$tutoringController,\s*'listClassroomAuditLogs'\]\)->add\(\$requireStaff\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/);
  assert.match(routesPhp, /\$app->delete\('\/api\/v1\/tutoring\/classrooms\/\{id:\[0-9\]\+\}',\s*\[\$tutoringController,\s*'deleteClassroom'\]\)->add\(\$requireStaff\)->add\(\$authRateLimit\)->add\(\$authMiddleware\)/);
  assert.match(rootSpec, /\/api\/v1\/tutoring\/classrooms:/);
  assert.match(rootSpec, /\/api\/v1\/tutoring\/classrooms\/\{id\}:/);
  assert.match(rootSpec, /\/api\/v1\/tutoring\/classrooms\/audit-logs:/);
  assert.match(rootSpec, /\/api\/v1\/tutoring\/classrooms\/\{id\}\/audit-logs:/);
  assert.match(rootSpec, /operationId:\s*tutoringCreateClassroom/);
  assert.match(rootSpec, /operationId:\s*tutoringDeleteClassroom/);
  assert.match(rootSpec, /operationId:\s*tutoringListClassroomAuditLogs/);
  assert.match(rootSpec, /TutoringClassroomAuditLogItem:/);

  // 3. Behavioral verification via simulator
  const ctx = createAppContext();
  const classroomsUrl = new URL('http://localhost:3000/api/v1/tutoring/classrooms');
  const auditLogsUrl = new URL('http://localhost:3000/api/v1/tutoring/classrooms/audit-logs');

  // 3a. Student gets 403 FORBIDDEN
  const studentForbidden = handleTutoringClassroomEndpoint(
    ctx,
    'POST',
    classroomsUrl,
    { name: 'کلاس غیرمجاز' },
    { sub: 3, role: 'student' }
  );
  assert.equal(studentForbidden.status, 403);

  // 3b. Admin creates classroom -> 201 and audit log records timestamp, admin ID, and created configuration
  const createRes = handleTutoringClassroomEndpoint(
    ctx,
    'POST',
    classroomsUrl,
    {
      name: 'کلاس شیمی کنکور',
      teacher_name: 'دکتر حبیبی',
      field: 'تجربی',
      sort_order: 2,
    },
    { sub: 1, role: 'admin' }
  );
  assert.equal(createRes.status, 201);
  const newId = createRes.body.data.id;
  assert.equal(createRes.body.data.name, 'کلاس شیمی کنکور');

  // 3c. Instructor updates classroom -> 200 and audit log records instructor ID and field-level diff
  const itemUrl = new URL(`http://localhost:3000/api/v1/tutoring/classrooms/${newId}`);
  const updateRes = handleTutoringClassroomEndpoint(
    ctx,
    'PUT',
    itemUrl,
    {
      name: 'کلاس شیمی جامع',
      teacher_name: 'استاد نصرالله‌زاده',
      field: 'ریاضی',
    },
    { sub: 4, role: 'teacher', instructor_id: 30 }
  );
  assert.equal(updateRes.status, 200);
  assert.equal(updateRes.body.data.name, 'کلاس شیمی جامع');

  // 3d. Admin deletes classroom -> 200 and audit log records deletion
  const deleteRes = handleTutoringClassroomEndpoint(
    ctx,
    'DELETE',
    itemUrl,
    {},
    { sub: 1, role: 'admin' }
  );
  assert.equal(deleteRes.status, 200);

  // 3e. Query audit logs for this classroom -> returns deleted, updated, created in reverse chronological order
  const itemAuditUrl = new URL(
    `http://localhost:3000/api/v1/tutoring/classrooms/${newId}/audit-logs`
  );
  const logsRes = handleTutoringClassroomEndpoint(
    ctx,
    'GET',
    itemAuditUrl,
    {},
    { sub: 1, role: 'admin' }
  );
  assert.equal(logsRes.status, 200);
  assert.equal(logsRes.body.data.length, 3);

  const [delLog, updLog, crtLog] = logsRes.body.data;
  assert.equal(delLog.action, 'deleted');
  assert.equal(delLog.actor_user_id, 1);
  assert.equal(delLog.admin_or_instructor_id, 1);
  assert.equal(delLog.changes.before.name, 'کلاس شیمی جامع');
  assert.equal(delLog.changes.after, null);

  assert.equal(updLog.action, 'updated');
  assert.equal(updLog.actor_user_id, 4);
  assert.equal(updLog.actor_instructor_id, 30);
  assert.equal(updLog.admin_or_instructor_id, 30);
  assert.deepEqual(updLog.changes.diff.name, {
    from: 'کلاس شیمی کنکور',
    to: 'کلاس شیمی جامع',
  });
  assert.deepEqual(updLog.changes.diff.teacher_name, {
    from: 'دکتر حبیبی',
    to: 'استاد نصرالله‌زاده',
  });
  assert.deepEqual(updLog.changes.diff.field, {
    from: 'تجربی',
    to: 'ریاضی',
  });

  assert.equal(crtLog.action, 'created');
  assert.equal(crtLog.actor_user_id, 1);
  assert.equal(crtLog.changes.before, null);
  assert.equal(crtLog.changes.after.name, 'کلاس شیمی کنکور');
  assert.match(crtLog.timestamp, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

  // 3f. Filter audit logs by action=updated
  const filteredUrl = new URL(
    'http://localhost:3000/api/v1/tutoring/classrooms/audit-logs?action=updated'
  );
  const filteredRes = handleTutoringClassroomEndpoint(
    ctx,
    'GET',
    filteredUrl,
    {},
    { sub: 1, role: 'admin' }
  );
  assert.equal(filteredRes.status, 200);
  assert.equal(filteredRes.body.data.length, 1);
  assert.equal(filteredRes.body.data[0].action, 'updated');
});

test('Admin Staff Activity Monitoring (/api/v1/admin/activity-logs & /stats) — tracks all actions by admins, supporters, and teachers', () => {
  const migrationSql = fs.readFileSync(
    new URL('./database/migrations/009_staff_activity_logs.sql', import.meta.url),
    'utf8'
  );
  const pgSchemaSql = fs.readFileSync(
    new URL('./database/schema.postgres.sql', import.meta.url),
    'utf8'
  );
  const mysqlUpgradeSql = fs.readFileSync(
    new URL('./database/mysql_upgrade_from_dump.sql', import.meta.url),
    'utf8'
  );
  const activityLogPhp = fs.readFileSync(
    new URL('./app/Modules/ActivityLogs/ActivityLog.php', import.meta.url),
    'utf8'
  );
  const activityCtrlPhp = fs.readFileSync(
    new URL('./app/Modules/ActivityLogs/ActivityLogController.php', import.meta.url),
    'utf8'
  );
  const authMiddlewarePhp = fs.readFileSync(
    new URL('./app/Modules/Auth/AuthMiddleware.php', import.meta.url),
    'utf8'
  );
  const authCtrlPhp = fs.readFileSync(
    new URL('./app/Modules/Auth/AuthController.php', import.meta.url),
    'utf8'
  );
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const pubSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');

  // 1. Verify schema, PHP implementation, AuthMiddleware hook, routes, and OpenAPI spec
  assert.match(migrationSql, /CREATE TABLE IF NOT EXISTS `staff_activity_logs`/);
  assert.match(pgSchemaSql, /009_staff_activity_logs\.sql/);
  assert.match(mysqlUpgradeSql, /009_staff_activity_logs\.sql/);

  assert.match(activityLogPhp, /public static function recordFromRequest\(/);
  assert.match(activityLogPhp, /public static function describeEndpoint\(/);
  assert.match(activityLogPhp, /public static function list\(array \$filters = \[\]\): array/);
  assert.match(activityLogPhp, /public static function stats\(array \$filters = \[\]\): array/);
  assert.match(activityCtrlPhp, /public function list\(/);
  assert.match(activityCtrlPhp, /public function stats\(/);

  assert.match(authMiddlewarePhp, /ActivityLog::recordFromRequest\(\$request,\s*\$response,\s*\$decoded\)/);
  assert.match(authCtrlPhp, /ActivityLog::record\(/);

  assert.match(routesPhp, /\/api\/v1\/admin\/activity-logs/);
  assert.match(routesPhp, /\/api\/v1\/admin\/activity-logs\/stats/);

  assert.equal(rootSpec, pubSpec);
  assert.match(rootSpec, /\/api\/v1\/admin\/activity-logs:/);
  assert.match(rootSpec, /\/api\/v1\/admin\/activity-logs\/stats:/);
  assert.match(rootSpec, /StaffActivityLogItem:/);
  assert.match(rootSpec, /StaffActivityLogStats:/);

  // 2. Behavioral verification via simulator
  const ctx = createAppContext();
  const listUrl = new URL('http://localhost:3000/api/v1/admin/activity-logs');
  const statsUrl = new URL('http://localhost:3000/api/v1/admin/activity-logs/stats');
  const recordUrl = new URL('http://localhost:3000/api/v1/admin/activity-logs/record');

  // 2a. Auth & RBAC enforcement: 401 when unauthenticated, 403 for non-admin on GET list/stats
  assert.equal(handleAdminActivityLogEndpoint(ctx, 'GET', listUrl, {}, null).status, 401);
  assert.equal(
    handleAdminActivityLogEndpoint(ctx, 'GET', listUrl, {}, { sub: 2, role: 'supporter' }).status,
    403
  );
  assert.equal(
    handleAdminActivityLogEndpoint(ctx, 'GET', statsUrl, {}, { sub: 4, role: 'teacher' }).status,
    403
  );

  // 2b. Record Supporter action (appointment update) and Teacher action (start session)
  const supRec = handleAdminActivityLogEndpoint(
    ctx,
    'POST',
    recordUrl,
    {
      module: 'appointments',
      action: 'update',
      target_type: 'appointment',
      target_id: '29',
      summary: 'ویرایش نوبت مشاوره #29',
      http_method: 'PUT',
      endpoint: '/api/v1/appointments/29',
      status_code: 200,
    },
    { sub: 2, role: 'supporter' }
  );
  assert.equal(supRec.status, 201);
  assert.equal(supRec.body.data.actor_role, 'supporter');
  assert.equal(supRec.body.data.actor_role_label, 'پشتیبان');
  assert.equal(supRec.body.data.actor_name, 'پشتیبان اول');

  const teacherRec = recordStaffActivity(
    ctx,
    { sub: 4, role: 'teacher', instructor_id: 30 },
    {
      module: 'tutoring',
      action: 'start_session',
      target_type: 'tutoring_session',
      target_id: '55',
      summary: 'ثبت ورود دانش‌آموز #20 به جلسه رفع اشکال',
      http_method: 'POST',
      endpoint: '/api/v1/tutoring/sessions',
      status_code: 201,
    }
  );
  assert.equal(teacherRec.actor_role, 'teacher');
  assert.equal(teacherRec.actor_role_label, 'استاد / مدرس');

  // 2c. Filter by role=supporter
  const supFilterUrl = new URL('http://localhost:3000/api/v1/admin/activity-logs?role=supporter');
  const supListRes = handleAdminActivityLogEndpoint(
    ctx,
    'GET',
    supFilterUrl,
    {},
    { sub: 1, role: 'admin' }
  );
  assert.equal(supListRes.status, 200);
  assert.equal(supListRes.body.data.length, 2);
  assert.ok(supListRes.body.data.every((item) => item.actor_role === 'supporter'));

  // 2d. Filter by module=tutoring
  const modFilterUrl = new URL('http://localhost:3000/api/v1/admin/activity-logs?module=tutoring');
  const modListRes = handleAdminActivityLogEndpoint(
    ctx,
    'GET',
    modFilterUrl,
    {},
    { sub: 1, role: 'admin' }
  );
  assert.equal(modListRes.status, 200);
  assert.equal(modListRes.body.data.length, 2);
  assert.ok(modListRes.body.data.every((item) => item.module === 'tutoring'));

  // 2e. Check stats endpoint
  const statsRes = handleAdminActivityLogEndpoint(
    ctx,
    'GET',
    statsUrl,
    {},
    { sub: 1, role: 'admin' }
  );
  assert.equal(statsRes.status, 200);
  assert.equal(statsRes.body.data.total_logs, 5);
  assert.deepEqual(statsRes.body.data.by_role, {
    admin: 1,
    supporter: 2,
    teacher: 2,
  });
  assert.ok(Array.isArray(statsRes.body.data.active_actors));
  assert.equal(statsRes.body.data.active_actors.length, 3);
});

test('Supporter Profile Photo & Description (Admin upload + Public landing page /api/v1/public/supporters)', () => {
  const migration001 = fs.readFileSync(
    new URL('./database/migrations/001_core_identity_and_users.sql', import.meta.url),
    'utf8'
  );
  const migration010 = fs.readFileSync(
    new URL('./database/migrations/010_supporter_description.sql', import.meta.url),
    'utf8'
  );
  const supporterModel = fs.readFileSync(
    new URL('./app/Modules/Supporters/Supporter.php', import.meta.url),
    'utf8'
  );
  const supporterService = fs.readFileSync(
    new URL('./app/Modules/Supporters/SupporterService.php', import.meta.url),
    'utf8'
  );
  const supporterController = fs.readFileSync(
    new URL('./app/Modules/Supporters/SupporterController.php', import.meta.url),
    'utf8'
  );
  const publicSupporterModel = fs.readFileSync(
    new URL('./app/Modules/Public/Supporter.php', import.meta.url),
    'utf8'
  );
  const publicService = fs.readFileSync(
    new URL('./app/Modules/Public/PublicService.php', import.meta.url),
    'utf8'
  );
  const storagePhp = fs.readFileSync(
    new URL('./app/Core/Storage.php', import.meta.url),
    'utf8'
  );
  const routesPhp = fs.readFileSync(
    new URL('./routes/api.php', import.meta.url),
    'utf8'
  );
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const publicSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');

  // 1. Verify schema, PHP implementation, and OpenAPI synchronization
  assert.equal(rootSpec, publicSpec);
  assert.match(migration001, /description\s+TEXT\s+DEFAULT NULL/);
  assert.match(migration010, /ALTER TABLE supporters/);
  assert.match(migration010, /ADD COLUMN description TEXT/);
  assert.match(supporterModel, /public static function ensureProfileColumns\(\): void/);
  assert.match(supporterModel, /INSERT INTO supporters \(name, grade, field, phone, chat_id, avatar, description, is_active\)/);
  assert.match(supporterModel, /\$allowed = \['name', 'grade', 'field', 'phone', 'chat_id', 'avatar', 'description', 'is_active'\]/);
  assert.match(supporterService, /\$row\['avatar_url'\] = \$resolvedUrl/);
  assert.match(supporterService, /\$row\['image_url'\] = \$resolvedUrl/);
  assert.match(supporterService, /\$row\['description'\] = \$row\['description'\] \?\? null/);
  assert.match(supporterController, /\['avatar', 'image', 'photo', 'profile_image'\]/);
  assert.match(supporterController, /public function uploadAvatar\(/);
  assert.match(supporterController, /public function deleteAvatar\(/);
  assert.match(publicSupporterModel, /SELECT id, name, grade, field, description, avatar FROM supporters/);
  assert.match(publicService, /\$supporter\['avatar_url'\] = \$resolvedUrl/);
  assert.match(publicService, /\$supporter\['image_url'\] = \$resolvedUrl/);
  assert.match(publicService, /\$supporter\['description'\] = \$supporter\['description'\] \?\? null/);
  assert.match(storagePhp, /'instructors',\s*'supporters'\s*=>\s*\['max_width'\s*=>\s*800/);
  assert.match(routesPhp, /\/api\/v1\/supporters\/\{id:\[0-9\]\+\}\/avatar/);

  // 2. Behavioral verification via simulator
  const ctx = createAppContext();

  // 2a. Create supporter in Admin Panel with description, JSON-encoded FormData scopes, and uploaded image file
  const createRes = handleSupporterEndpoint(
    ctx,
    'POST',
    new URL('http://localhost:3000/api/v1/supporters'),
    {
      name: 'نگار کریمی',
      phone: '09128887766',
      scopes: JSON.stringify([{ field: 'تجربی', grade: 12 }]),
      description: 'رتبه ۱۲ کنکور تجربی و مشاور تخصصی برنامه‌ریزی و آزمون‌های آزمایشی',
      image: { filename: 'negar-profile.jpg' },
    },
    { sub: 1, role: 'admin' }
  );
  assert.equal(createRes.status, 201);
  assert.equal(createRes.body.data.name, 'نگار کریمی');
  assert.equal(
    createRes.body.data.description,
    'رتبه ۱۲ کنکور تجربی و مشاور تخصصی برنامه‌ریزی و آزمون‌های آزمایشی'
  );
  assert.equal(
    createRes.body.data.avatar_url,
    `https://api.example.com/uploads/supporters/${createRes.body.data.id}/negar-profile.webp`
  );
  assert.equal(createRes.body.data.image_url, createRes.body.data.avatar_url);

  // 2b. Update supporter description and avatar via PUT /api/v1/supporters/{id}
  const supporterId = createRes.body.data.id;
  const updateRes = handleSupporterEndpoint(
    ctx,
    'PUT',
    new URL(`http://localhost:3000/api/v1/supporters/${supporterId}`),
    {
      description: 'مشاور ارشد کنکور تجربی با ۴ سال سابقه همراهی رتبه‌های برتر',
      avatar: { filename: 'negar-updated.png' },
    },
    { sub: 1, role: 'admin' }
  );
  assert.equal(updateRes.status, 200);
  assert.equal(
    updateRes.body.data.description,
    'مشاور ارشد کنکور تجربی با ۴ سال سابقه همراهی رتبه‌های برتر'
  );
  assert.equal(
    updateRes.body.data.avatar_url,
    `https://api.example.com/uploads/supporters/${supporterId}/negar-updated.webp`
  );
  assert.equal(updateRes.body.data.image_url, updateRes.body.data.avatar_url);

  // 2c. Fetch landing page GET /api/v1/public/supporters and verify description + avatar_url + image_url are returned
  const publicRes = handlePublicEndpoint(
    ctx,
    'GET',
    new URL('http://localhost:3000/api/v1/public/supporters'),
    '127.0.0.1'
  );
  assert.equal(publicRes.status, 200);
  assert.equal(publicRes.body.success, true);
  const publicItem = publicRes.body.data.find((s) => s.id === supporterId);
  assert.ok(publicItem, 'Newly created supporter must appear in GET /api/v1/public/supporters');
  assert.equal(
    publicItem.description,
    'مشاور ارشد کنکور تجربی با ۴ سال سابقه همراهی رتبه‌های برتر'
  );
  assert.equal(
    publicItem.avatar_url,
    `https://api.example.com/uploads/supporters/${supporterId}/negar-updated.webp`
  );
  assert.equal(publicItem.image_url, publicItem.avatar_url);
});

test('Course Enrollment & Monthly Tuition Status (POST /courses/{id}/enroll, GET /subscriptions/me, PATCH /subscriptions/{id}/tuition, Migration 011)', () => {
  // 1. Verify schema migration 011 and PHP files
  const migration011 = fs.readFileSync(
    new URL('./database/migrations/011_subscription_tuition.sql', import.meta.url),
    'utf8'
  );
  assert.match(migration011, /ALTER TABLE subscriptions/);
  assert.match(migration011, /ADD COLUMN monthly_fee INT/);
  assert.match(migration011, /ADD COLUMN tuition_status VARCHAR\(30\)/);
  assert.match(migration011, /ADD COLUMN tuition_due_date DATE/);
  assert.match(migration011, /ADD COLUMN last_payment_date DATE/);

  const subModel = fs.readFileSync(
    new URL('./app/Modules/Subscription/Subscription.php', import.meta.url),
    'utf8'
  );
  assert.match(subModel, /public static function ensureTuitionColumns\(\)/);
  assert.match(subModel, /public static function updateTuition\(int \$id, array \$tuitionData\)/);
  assert.match(subModel, /sub\.monthly_fee/);
  assert.match(subModel, /sub\.tuition_status/);

  const subService = fs.readFileSync(
    new URL('./app/Modules/Subscription/SubscriptionService.php', import.meta.url),
    'utf8'
  );
  assert.match(subService, /public const VALID_TUITION_STATUSES/);
  assert.match(subService, /'unpaid'/);
  assert.match(subService, /'paid'/);
  assert.match(subService, /public function updateTuition\(int \$id, array \$data\)/);

  const subController = fs.readFileSync(
    new URL('./app/Modules/Subscription/SubscriptionController.php', import.meta.url),
    'utf8'
  );
  assert.match(subController, /public function enrollCourse\(/);
  assert.match(subController, /public function updateTuition\(/);

  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  assert.match(routesPhp, /\/courses\/\{id:\[0-9\]\+\}\/enroll/);
  assert.match(routesPhp, /\/subscriptions\/\{id:\[0-9\]\+\}\/tuition/);

  // 2. Behavioral test using dev-server context
  const ctx = createAppContext();
  const studentActor = { sub: 3, role: 'student', linked_id: 20 };
  const adminActor = { sub: 1, role: 'admin', linked_id: 1 };

  // 2a. Student enrolls in Course #102 (Physics, monthly fee 420,000)
  const enrollRes = handleSubscriptionEndpoint(
    ctx,
    'POST',
    new URL('http://localhost:3000/api/v1/courses/102/enroll'),
    {},
    studentActor
  );
  assert.equal(enrollRes.status, 201);
  assert.equal(enrollRes.body.success, true);
  assert.equal(enrollRes.body.data.course_id, 102);
  assert.equal(enrollRes.body.data.student_id, 20);
  assert.equal(enrollRes.body.data.status, 'pending');
  assert.equal(enrollRes.body.data.monthly_fee, 420000);
  assert.equal(enrollRes.body.data.tuition_status, 'unpaid');
  assert.equal(enrollRes.body.data.tuition_status_label, 'پرداخت نشده');
  assert.ok(enrollRes.body.data.tuition_due_date, 'Due date must be set');

  const newSubId = enrollRes.body.data.id;

  // 2b. Attempting duplicate enrollment in Course #102 returns 409 ALREADY_SUBSCRIBED
  const dupEnrollRes = handleSubscriptionEndpoint(
    ctx,
    'POST',
    new URL('http://localhost:3000/api/v1/courses/102/enroll'),
    {},
    studentActor
  );
  assert.equal(dupEnrollRes.status, 409);
  assert.equal(dupEnrollRes.body.error.code, 'ALREADY_SUBSCRIBED');

  // 2c. Student fetches their own subscriptions via GET /api/v1/subscriptions/me
  const mySubsRes = handleSubscriptionEndpoint(
    ctx,
    'GET',
    new URL('http://localhost:3000/api/v1/subscriptions/me'),
    {},
    studentActor
  );
  assert.equal(mySubsRes.status, 200);
  assert.equal(mySubsRes.body.success, true);
  assert.ok(Array.isArray(mySubsRes.body.data));
  const myEnrolled = mySubsRes.body.data.find((s) => s.id === newSubId);
  assert.ok(myEnrolled, 'Newly enrolled subscription must appear in /subscriptions/me');
  assert.equal(myEnrolled.course_id, 102);
  assert.equal(myEnrolled.monthly_fee, 420000);
  assert.equal(myEnrolled.tuition_status, 'unpaid');
  assert.equal(myEnrolled.tuition_status_label, 'پرداخت نشده');

  // 2d. Admin/Staff updates tuition payment status to 'paid' via PATCH /api/v1/subscriptions/{id}/tuition
  const tuitionPatchRes = handleSubscriptionEndpoint(
    ctx,
    'PATCH',
    new URL(`http://localhost:3000/api/v1/subscriptions/${newSubId}/tuition`),
    {
      tuition_status: 'paid',
      tuition_notes: 'واریز از طریق درگاه پرداخت بانکی',
    },
    adminActor
  );
  assert.equal(tuitionPatchRes.status, 200);
  assert.equal(tuitionPatchRes.body.success, true);
  assert.equal(tuitionPatchRes.body.data.tuition_status, 'paid');
  assert.equal(tuitionPatchRes.body.data.tuition_status_label, 'پرداخت شده');
  assert.ok(tuitionPatchRes.body.data.last_payment_date, 'Last payment date must be recorded');
  assert.equal(tuitionPatchRes.body.data.tuition_notes, 'واریز از طریق درگاه پرداخت بانکی');

  // 2e. Invalid tuition status returns 422 VALIDATION_ERROR
  const invalidStatusRes = handleSubscriptionEndpoint(
    ctx,
    'PATCH',
    new URL(`http://localhost:3000/api/v1/subscriptions/${newSubId}/tuition`),
    { tuition_status: 'invalid_status_xyz' },
    adminActor
  );
  assert.equal(invalidStatusRes.status, 422);
  assert.equal(invalidStatusRes.body.error.code, 'VALIDATION_ERROR');

  // 2f. Filter subscriptions by tuition_status
  const paidFilterRes = handleSubscriptionEndpoint(
    ctx,
    'GET',
    new URL('http://localhost:3000/api/v1/subscriptions?tuition_status=paid'),
    {},
    adminActor
  );
  assert.equal(paidFilterRes.status, 200);
  assert.ok(paidFilterRes.body.data.every((s) => s.tuition_status === 'paid'));

  // 2g. Non-admin student forbidden from updating tuition
  const forbiddenPatchRes = handleSubscriptionEndpoint(
    ctx,
    'PATCH',
    new URL(`http://localhost:3000/api/v1/subscriptions/${newSubId}/tuition`),
    { tuition_status: 'paid' },
    studentActor
  );
  assert.equal(forbiddenPatchRes.status, 403);
  assert.equal(forbiddenPatchRes.body.error.code, 'FORBIDDEN');
});

test('Melipayamak SMS Pattern Isolation (/api/v1/admin/sms/patterns, no 2FA fallback for appointments, Migration 012)', () => {
  // 1. Verify Migration 012, Core SmsPatternSettings, SmsService non-fallback, Controller, Service, Routes, and OpenAPI sync
  const mig012 = fs.readFileSync(
    new URL('./database/migrations/012_sms_pattern_settings.sql', import.meta.url),
    'utf8'
  );
  const patternSettingsPhp = fs.readFileSync(
    new URL('./app/Core/SmsPatternSettings.php', import.meta.url),
    'utf8'
  );
  const smsServicePhp = fs.readFileSync(
    new URL('./app/Core/SmsService.php', import.meta.url),
    'utf8'
  );
  const smsCtrlPhp = fs.readFileSync(
    new URL('./app/Modules/Settings/SmsSettingsController.php', import.meta.url),
    'utf8'
  );
  const apptSvcPhp = fs.readFileSync(
    new URL('./app/Modules/Appointments/AppointmentService.php', import.meta.url),
    'utf8'
  );
  const apptCtrlPhp = fs.readFileSync(
    new URL('./app/Modules/Appointments/AppointmentController.php', import.meta.url),
    'utf8'
  );
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const publicSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');
  const readmeMd = fs.readFileSync(new URL('./README.md', import.meta.url), 'utf8');

  assert.match(mig012, /CREATE TABLE IF NOT EXISTS `?sms_pattern_settings`?/);
  assert.match(
    mig012,
    /موسسه آموزشی فامو \| دکتر موسوی والدین گرامی \{0\} ، نوبت فرزند شما روز \{1\} ساعت \{2\} میباشد\. در صورت تایید به شماره موسسه پیامک دهید\./
  );

  assert.match(patternSettingsPhp, /KEY_AUTH_2FA = 'auth_2fa'/);
  assert.match(patternSettingsPhp, /KEY_APPOINTMENT_REMINDER = 'appointment_reminder'/);
  assert.match(patternSettingsPhp, /KEY_EXAM_RESULT = 'exam_result'/);
  assert.match(
    patternSettingsPhp,
    /موسسه آموزشی فامو \| دکتر موسوی والدین گرامی \{0\} ، نوبت فرزند شما روز \{1\} ساعت \{2\} میباشد\. در صورت تایید به شماره موسسه پیامک دهید\./
  );

  // Critical bugfix invariant: Appointment and Exam patterns must NEVER fall back to MELIPAYAMAK_PATTERN_BODY_ID
  assert.doesNotMatch(
    smsServicePhp,
    /\$this->melipayamakExamBodyId\s*=\s*self::env\('MELIPAYAMAK_EXAM_BODY_ID'\)\s*\?:/
  );
  assert.doesNotMatch(
    smsServicePhp,
    /\$this->melipayamakAppointmentBodyId\s*=\s*self::env\('MELIPAYAMAK_APPOINTMENT_BODY_ID'\)\s*\?:/
  );
  assert.match(smsServicePhp, /SmsPatternSettings::resolveBodyId\(SmsPatternSettings::KEY_APPOINTMENT_REMINDER\)/);
  assert.match(smsServicePhp, /public function setAppointmentBodyId\(\?int \$bodyId\): void/);
  assert.match(smsServicePhp, /public function getLastError\(\): \?string/);

  assert.match(smsCtrlPhp, /public function getPatterns\(/);
  assert.match(smsCtrlPhp, /public function updatePatterns\(/);
  assert.match(apptSvcPhp, /APPOINTMENT_PATTERN_NOT_CONFIGURED/);
  assert.match(apptSvcPhp, /sms_preview_text/);
  assert.match(apptCtrlPhp, /\$body\['body_id'\]/);

  assert.match(routesPhp, /\/api\/v1\/admin\/sms\/patterns/);
  assert.equal(rootSpec, publicSpec);
  assert.match(rootSpec, /\/api\/v1\/admin\/sms\/patterns:/);
  assert.match(rootSpec, /SmsPatternsResponse:/);
  assert.match(readmeMd, /\/api\/v1\/admin\/sms\/patterns/);

  // 2. Behavioral verification: simulate production where ONLY 2FA login pattern (500001) is set in env,
  //    and appointment_reminder has neither env nor DB pattern ID configured yet.
  const ctx = createAppContext();
  ctx.smsPatterns.appointment_reminder.env_body_id = null;
  ctx.smsPatterns.appointment_reminder.db_body_id = null;
  ctx.smsPatterns.exam_result.env_body_id = null;
  ctx.smsPatterns.exam_result.db_body_id = null;

  const adminActor = { sub: 1, role: 'admin' };
  const supporterActor = { sub: 2, role: 'supporter' };
  const patternsUrl = new URL('http://localhost:3000/api/v1/admin/sms/patterns');
  const previewUrl = new URL('http://localhost:3000/api/v1/appointments/sms/preview?week_date=2026-10-24');
  const sendUrl = new URL('http://localhost:3000/api/v1/appointments/sms/send');

  // 2a. Supporter forbidden from /api/v1/admin/sms/patterns (403)
  const forbRes = handleSmsPatternEndpoint(ctx, 'GET', patternsUrl, {}, supporterActor);
  assert.equal(forbRes.status, 403);
  assert.equal(forbRes.body.error.code, 'FORBIDDEN');

  // 2b. Admin reads initial pattern status: auth_2fa is configured (500001), appointment_reminder is unset (no fallback!)
  const getInitial = handleSmsPatternEndpoint(ctx, 'GET', patternsUrl, {}, adminActor);
  assert.equal(getInitial.status, 200);
  assert.equal(getInitial.body.data.auth_body_id, 500001);
  assert.equal(getInitial.body.data.appointment_body_id, null);
  assert.equal(getInitial.body.data.patterns.appointment_reminder.is_configured, false);
  assert.equal(getInitial.body.data.patterns.appointment_reminder.source, 'unset');

  // 2c. Trying to send appointment SMS before setting appointment pattern MUST fail fast with 422
  //     and NEVER dispatch using the 2FA login pattern (500001)
  const blockedSend = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'unsent' },
    adminActor
  );
  assert.equal(blockedSend.status, 422);
  assert.equal(blockedSend.body.error.code, 'APPOINTMENT_PATTERN_NOT_CONFIGURED');
  assert.equal(ctx.smsDispatches.length, 0);

  // 2d. Preview still works and exposes pattern.is_configured=false + exact Persian sms_preview_text
  const previewUnset = handleAppointmentSmsEndpoint(ctx, 'GET', previewUrl, {}, adminActor);
  assert.equal(previewUnset.status, 200);
  assert.equal(previewUnset.body.data.pattern.is_configured, false);
  assert.equal(previewUnset.body.data.pattern.body_id, null);
  const st20Prev = previewUnset.body.data.items.find((i) => i.student_id === 20);
  assert.equal(
    st20Prev.sms_preview_text,
    'موسسه آموزشی فامو | دکتر موسوی والدین گرامی دانش‌آموز نمونه ، نوبت فرزند شما روز شنبه 1405/08/02 ساعت 16:30 میباشد. در صورت تایید به شماره موسسه پیامک دهید.'
  );

  // 2e. Validation rejects invalid/non-positive body_id on PUT /api/v1/admin/sms/patterns
  const badUpdate = handleSmsPatternEndpoint(
    ctx,
    'PUT',
    patternsUrl,
    { appointment_body_id: 'abc' },
    adminActor
  );
  assert.equal(badUpdate.status, 422);
  assert.equal(badUpdate.body.error.code, 'VALIDATION_ERROR');

  // 2f. Admin configures dedicated Melipayamak appointment pattern bodyId (345678)
  const updateRes = handleSmsPatternEndpoint(
    ctx,
    'PUT',
    patternsUrl,
    { appointment_body_id: 345678 },
    adminActor
  );
  assert.equal(updateRes.status, 200);
  assert.equal(updateRes.body.data.appointment_body_id, 345678);
  assert.equal(updateRes.body.data.auth_body_id, 500001);
  assert.equal(updateRes.body.data.patterns.appointment_reminder.source, 'database');
  assert.equal(updateRes.body.data.patterns.appointment_reminder.is_configured, true);

  // 2g. Appointment SMS now sends with dedicated body_id=345678 (NOT 500001)
  const sentRes = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'unsent' },
    adminActor
  );
  assert.equal(sentRes.status, 200);
  assert.equal(sentRes.body.data.body_id, 345678);
  assert.equal(sentRes.body.data.pattern_source, 'database');
  assert.equal(ctx.smsDispatches.length, 1);
  assert.equal(ctx.smsDispatches[0].body_id, 345678);
  assert.notEqual(ctx.smsDispatches[0].body_id, ctx.smsPatterns.auth_2fa.env_body_id);
  assert.equal(ctx.smsDispatches[0].text, 'دانش‌آموز نمونه;شنبه 1405/08/02;16:30');

  // 2h. Inline override body_id + save_pattern=true in POST /appointments/sms/send persists for future sends
  ctx.appointments.find((a) => a.id === 1).start_time = '17:00:00';
  const inlineSend = handleAppointmentSmsEndpoint(
    ctx,
    'POST',
    sendUrl,
    { week_date: '2026-10-24', mode: 'changed', body_id: 778899, save_pattern: true },
    adminActor
  );
  assert.equal(inlineSend.status, 200);
  assert.equal(inlineSend.body.data.body_id, 778899);
  assert.equal(inlineSend.body.data.pattern_source, 'request');
  assert.equal(ctx.smsDispatches[1].body_id, 778899);

  const afterSave = handleSmsPatternEndpoint(ctx, 'GET', patternsUrl, {}, adminActor);
  assert.equal(afterSave.body.data.appointment_body_id, 778899);
  assert.equal(afterSave.body.data.patterns.appointment_reminder.source, 'database');
});

test('Redis JWT revocation, configurable rate limiting, ClientIp right-to-left proxy check, and RedisMonitorMiddleware', () => {
  const redisClientPhp = fs.readFileSync(new URL('./app/Core/RedisClient.php', import.meta.url), 'utf8');
  const redisMonitorPhp = fs.readFileSync(new URL('./app/Core/RedisMonitorMiddleware.php', import.meta.url), 'utf8');
  const clientIpPhp = fs.readFileSync(new URL('./app/Core/ClientIp.php', import.meta.url), 'utf8');
  const authPhp = fs.readFileSync(new URL('./app/Core/Auth.php', import.meta.url), 'utf8');
  const jwtRevPhp = fs.readFileSync(new URL('./app/Core/JwtRevocation.php', import.meta.url), 'utf8');
  const rateLimitsCfg = fs.readFileSync(new URL('./config/rate_limits.php', import.meta.url), 'utf8');
  const rateLimiterPhp = fs.readFileSync(new URL('./app/Core/RateLimiter.php', import.meta.url), 'utf8');
  const rateLimitMwPhp = fs.readFileSync(new URL('./app/Core/RateLimitMiddleware.php', import.meta.url), 'utf8');
  const bootstrapPhp = fs.readFileSync(new URL('./bootstrap/app.php', import.meta.url), 'utf8');
  const routesPhp = fs.readFileSync(new URL('./routes/api.php', import.meta.url), 'utf8');
  const envExample = fs.readFileSync(new URL('./.env.example', import.meta.url), 'utf8');
  const envValidatorPhp = fs.readFileSync(new URL('./app/Core/EnvValidator.php', import.meta.url), 'utf8');
  const rootSpec = fs.readFileSync(new URL('./openapi.yaml', import.meta.url), 'utf8');
  const publicSpec = fs.readFileSync(new URL('./public/openapi.yaml', import.meta.url), 'utf8');

  // 1. Verify RedisClient::eval, downtime monitoring & critical logs
  assert.match(redisClientPhp, /public static function eval\(/);
  assert.match(redisClientPhp, /private static function evalInMemory\(/);
  assert.match(redisClientPhp, /\[CRITICAL\] Redis unreachable/);
  assert.match(redisClientPhp, /downtime_seconds=/);
  assert.match(redisMonitorPhp, /class RedisMonitorMiddleware implements MiddlewareInterface/);
  assert.match(bootstrapPhp, /RedisMonitorMiddleware/);

  // 2. Verify ClientIp walks X-Forwarded-For right-to-left skipping trusted proxies
  assert.match(clientIpPhp, /for \(\$i = count\(\$parts\) - 1; \$i >= 0; \$i--\)/);

  // 3. Verify JWT jti/typ claims, blacklist, user_epoch, fail modes & same-second docs
  assert.match(authPhp, /'jti'\s*=>\s*\$user\['jti'\]\s*\?\?\s*bin2hex\(random_bytes\(16\)\)/);
  assert.match(authPhp, /'typ'\s*=>\s*\$user\['typ'\]\s*\?\?\s*'access'/);
  assert.match(jwtRevPhp, /jwt:bl:/);
  assert.match(jwtRevPhp, /jwt:user_epoch:/);
  assert.match(jwtRevPhp, /\$iat < \$epoch/);
  assert.match(jwtRevPhp, /AUTH_REVOCATION_FAIL_MODE/);
  assert.match(jwtRevPhp, /AUTH_REVOCATION_ADMIN_FAIL_MODE/);

  // 4. Verify centralized config & rate limits
  assert.match(rateLimitsCfg, /'auth_user_ip_max'\s*=>\s*\$envInt\('RATE_LIMIT_AUTH_USER_IP_MAX', 5\)/);
  assert.match(rateLimitsCfg, /'auth_ip_max'\s*=>\s*\$envInt\('RATE_LIMIT_AUTH_IP_MAX', 100\)/);
  assert.match(rateLimitsCfg, /'public_ip_max'\s*=>\s*\$envInt\('RATE_LIMIT_PUBLIC_IP_MAX'/);
  assert.match(rateLimitsCfg, /'api_student_max'\s*=>\s*\$envInt\('RATE_LIMIT_API_STUDENT_MAX', 120\)/);
  assert.match(rateLimitsCfg, /'api_staff_max'\s*=>\s*\$envInt\('RATE_LIMIT_API_STAFF_MAX', 300\)/);
  assert.match(rateLimitsCfg, /'sms_phone_max'\s*=>\s*\$envInt\('RATE_LIMIT_SMS_PHONE_MAX', 3\)/);
  assert.match(rateLimitsCfg, /'sms_ip_max'\s*=>\s*\$envInt\('RATE_LIMIT_SMS_IP_MAX', 10\)/);
  assert.match(rateLimiterPhp, /FIXED_WINDOW_LUA/);
  assert.match(rateLimiterPhp, /attemptFixedWindow\(/);
  assert.match(rateLimitMwPhp, /MODE_AUTH_USER/);
  assert.match(rateLimitMwPhp, /MODE_AUTH_ATTEMPT/);
  assert.match(rateLimitMwPhp, /MODE_SMS_SEND/);
  assert.match(routesPhp, /\$authAttemptLimit/);
  assert.match(routesPhp, /\$authRateLimit/);
  assert.match(routesPhp, /\$smsIpLimit/);

  // 5. Verify env + OpenAPI sync
  assert.match(envExample, /AUTH_REVOCATION_ADMIN_FAIL_MODE=closed/);
  assert.match(envExample, /RATE_LIMIT_API_STAFF_MAX=300/);
  assert.match(envValidatorPhp, /RATE_LIMIT_API_STAFF_MAX/);
  assert.match(envValidatorPhp, /AUTH_REVOCATION_FAIL_MODE/);
  assert.equal(rootSpec, publicSpec);
  assert.match(rootSpec, /temporary_password:/);
  assert.match(rootSpec, /jwt:user_epoch/);

  // 6. Behavioral verification of RedisStore::eval() & simulator JWT revocation
  const ctx = createAppContext();
  const eval1 = ctx.redis.eval("redis.call('INCR', KEYS[1])", ['rl:fw:test1'], ['60']);
  assert.equal(eval1[0], 1);
  assert.ok(eval1[1] > 0 && eval1[1] <= 60);
  const eval2 = ctx.redis.eval("redis.call('INCR', KEYS[1])", ['rl:fw:test1'], ['60']);
  assert.equal(eval2[0], 2);

  const fw1 = ctx.rateLimiter.attemptFixedWindow('sim:user:1', 2, 60);
  const fw2 = ctx.rateLimiter.attemptFixedWindow('sim:user:1', 2, 60);
  const fw3 = ctx.rateLimiter.attemptFixedWindow('sim:user:1', 2, 60);
  assert.equal(fw1.allowed, true);
  assert.equal(fw2.allowed, true);
  assert.equal(fw3.allowed, false);
  assert.ok(fw3.retry_after > 0);

  // Logout blacklists jti
  const studentActor = { sub: 3, role: 'student', jti: 'jti-abc-123', iat: Math.floor(Date.now() / 1000) - 30 };
  const logoutRes = handleAuthEndpoint(ctx, 'POST', '/api/v1/auth/logout', {}, studentActor);
  assert.equal(logoutRes.status, 200);
  assert.equal(ctx.redis.get('jwt:bl:jti-abc-123'), '1');

  const afterLogout = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', { full_name: 'تست', phone: '09120000003' }, studentActor);
  assert.equal(afterLogout.status, 401);

  // Change-password revokes prior iat and issues fresh active token
  const activeActor = { sub: 3, role: 'student', jti: 'jti-fresh-1', iat: Math.floor(Date.now() / 1000) - 20 };
  const chgRes = handleAuthEndpoint(
    ctx,
    'POST',
    '/api/v1/auth/change-password',
    { current_password: 'stud123', new_password: 'newSecret99' },
    activeActor
  );
  assert.equal(chgRes.status, 200);
  assert.ok(chgRes.body.data.token, 'change-password must return a fresh JWT');
  assert.ok(chgRes.body.data.user);

  const oldActorAttempt = handleAuthEndpoint(ctx, 'PUT', '/api/v1/auth/profile', { full_name: 'تست', phone: '09120000003' }, activeActor);
  assert.equal(oldActorAttempt.status, 401);
});

test('Dockerfiles pin Composer vendor stage to php:8.4-apache matching runtime PHP version', () => {
  const dockerfile = fs.readFileSync(new URL('./Dockerfile', import.meta.url), 'utf8');
  const apiDockerfile = fs.readFileSync(new URL('./api.Dockerfile', import.meta.url), 'utf8');

  for (const [name, content] of [
    ['Dockerfile', dockerfile],
    ['api.Dockerfile', apiDockerfile],
  ]) {
    assert.doesNotMatch(
      content,
      /^FROM composer:2 AS vendor/m,
      `${name} must not use unpinned composer:2 as the vendor base image (drifts to unsupported PHP 8.6+)`
    );
    assert.match(content, /^FROM php:8\.4-apache AS vendor/m, `${name} vendor stage must pin php:8.4-apache`);
    assert.match(content, /^COPY --from=composer:2 \/usr\/bin\/composer \/usr\/bin\/composer/m);
    assert.match(content, /^FROM php:8\.4-apache AS runtime/m, `${name} runtime stage must pin php:8.4-apache`);
    assert.match(
      content,
      /composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader/
    );
  }
});
