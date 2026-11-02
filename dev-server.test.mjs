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

