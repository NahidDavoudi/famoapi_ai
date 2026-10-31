import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/**
 * Pure Redis-compatible store mirroring App\Core\RedisClient
 */
export class RedisStore {
  constructor(prefix = 'famo:') {
    this.prefix = prefix;
    this.kv = new Map();
    this.sets = new Map();
  }

  _k(key) {
    return `${this.prefix}${key}`;
  }

  _purgeExpired(fullKey) {
    const entry = this.kv.get(fullKey);
    if (!entry) return;
    if (entry.expiresAt !== null && entry.expiresAt <= Date.now()) {
      this.kv.delete(fullKey);
    }
  }

  get(key) {
    const fullKey = this._k(key);
    this._purgeExpired(fullKey);
    const entry = this.kv.get(fullKey);
    return entry ? String(entry.value) : null;
  }

  setex(key, ttlSeconds, value) {
    const ttl = Math.max(1, Number(ttlSeconds) || 1);
    const fullKey = this._k(key);
    this.kv.set(fullKey, {
      value: String(value),
      expiresAt: Date.now() + ttl * 1000,
    });
    return true;
  }

  del(...keys) {
    let removed = 0;
    for (const key of keys) {
      const fullKey = this._k(key);
      if (this.kv.delete(fullKey)) removed++;
      if (this.sets.delete(fullKey)) removed++;
    }
    return removed;
  }

  incr(key) {
    const fullKey = this._k(key);
    this._purgeExpired(fullKey);
    const current = this.kv.get(fullKey);
    const nextVal = (current ? parseInt(current.value, 10) || 0 : 0) + 1;
    this.kv.set(fullKey, {
      value: String(nextVal),
      expiresAt: current ? current.expiresAt : null,
    });
    return nextVal;
  }

  expire(key, ttlSeconds) {
    const fullKey = this._k(key);
    this._purgeExpired(fullKey);
    const current = this.kv.get(fullKey);
    if (!current) return false;
    current.expiresAt = Date.now() + Math.max(1, Number(ttlSeconds) || 1) * 1000;
    return true;
  }

  ttl(key) {
    const fullKey = this._k(key);
    this._purgeExpired(fullKey);
    const current = this.kv.get(fullKey);
    if (!current) return -2;
    if (current.expiresAt === null) return -1;
    return Math.max(0, Math.ceil((current.expiresAt - Date.now()) / 1000));
  }

  sAdd(key, member) {
    const fullKey = this._k(key);
    if (!this.sets.has(fullKey)) {
      this.sets.set(fullKey, new Set());
    }
    const set = this.sets.get(fullKey);
    const had = set.has(member);
    set.add(member);
    return had ? 0 : 1;
  }

  sMembers(key) {
    const fullKey = this._k(key);
    const set = this.sets.get(fullKey);
    return set ? Array.from(set) : [];
  }

  inspectAll() {
    const now = Date.now();
    const keys = [];
    for (const [fullKey, entry] of this.kv.entries()) {
      if (entry.expiresAt !== null && entry.expiresAt <= now) {
        this.kv.delete(fullKey);
        continue;
      }
      const shortKey = fullKey.startsWith(this.prefix)
        ? fullKey.slice(this.prefix.length)
        : fullKey;
      const ttl =
        entry.expiresAt === null
          ? -1
          : Math.max(0, Math.ceil((entry.expiresAt - now) / 1000));
      keys.push({
        key: shortKey,
        ttl,
        sizeBytes: Buffer.byteLength(String(entry.value), 'utf8'),
        preview: String(entry.value).slice(0, 140),
      });
    }
    const sets = [];
    for (const [fullKey, set] of this.sets.entries()) {
      const shortKey = fullKey.startsWith(this.prefix)
        ? fullKey.slice(this.prefix.length)
        : fullKey;
      sets.push({
        key: shortKey,
        members: Array.from(set),
      });
    }
    return { keys, sets };
  }
}

/**
 * Cache manager mirroring App\Core\Cache
 */
export class CacheManager {
  constructor(redis) {
    this.redis = redis;
  }

  _redisKey(group, key) {
    const safeGroup = group.replace(/[^a-zA-Z0-9_-]/g, '_') || 'default';
    const hash = crypto.createHash('md5').update(key).digest('hex');
    return `cache:${safeGroup}:${hash}`;
  }

  _redisGroupSetKey(group) {
    const safeGroup = group.replace(/[^a-zA-Z0-9_-]/g, '_') || 'default';
    return `cache:group:${safeGroup}`;
  }

  get(group, key) {
    const rKey = this._redisKey(group, key);
    const raw = this.redis.get(rKey);
    if (raw === null) return undefined;
    try {
      return JSON.parse(raw);
    } catch {
      return undefined;
    }
  }

  set(group, key, ttl, value) {
    if (value === null || value === undefined || ttl <= 0) return;
    const rKey = this._redisKey(group, key);
    const gKey = this._redisGroupSetKey(group);
    this.redis.setex(rKey, ttl, JSON.stringify(value));
    this.redis.sAdd(gKey, rKey);
  }

  remember(group, key, ttl, callback) {
    const cached = this.get(group, key);
    if (cached !== undefined) {
      return { value: cached, hit: true };
    }
    const value = callback();
    if (value !== null && value !== undefined) {
      this.set(group, key, ttl, value);
    }
    return { value, hit: false };
  }

  forget(group, key) {
    this.redis.del(this._redisKey(group, key));
  }

  flushGroup(group) {
    const gKey = this._redisGroupSetKey(group);
    const members = this.redis.sMembers(gKey);
    if (members.length > 0) {
      this.redis.del(...members, gKey);
    } else {
      this.redis.del(gKey);
    }
  }
}

/**
 * Redis RateLimiter mirroring App\Core\RateLimiter & App\Core\RateLimitMiddleware
 */
export class RateLimiterEngine {
  constructor(redis, options = {}) {
    this.redis = redis;
    this.maxAttempts = options.maxAttempts ?? 60;
    this.windowSeconds = options.windowSeconds ?? 60;
    this.lockoutSeconds = options.lockoutSeconds ?? 60;
  }

  key(scope, identifier) {
    const hash = crypto
      .createHash('sha256')
      .update(identifier.trim().toLowerCase())
      .digest('hex');
    return `${scope}:${hash}`;
  }

  status(key, maxAttempts = this.maxAttempts) {
    const lockKey = `rl:lock:${key}`;
    const attemptsKey = `rl:attempts:${key}`;

    const lockTtl = this.redis.ttl(lockKey);
    const rawAttempts = this.redis.get(attemptsKey);
    const attempts = rawAttempts !== null ? parseInt(rawAttempts, 10) : 0;

    if (lockTtl > 0) {
      return {
        allowed: false,
        retry_after: lockTtl,
        attempts: Math.max(attempts, maxAttempts),
      };
    }

    return {
      allowed: attempts < maxAttempts,
      retry_after: 0,
      attempts,
    };
  }

  attempt(
    key,
    maxAttempts = this.maxAttempts,
    windowSeconds = this.windowSeconds,
    lockoutSeconds = this.lockoutSeconds
  ) {
    const lockKey = `rl:lock:${key}`;
    const attemptsKey = `rl:attempts:${key}`;

    const lockTtl = this.redis.ttl(lockKey);
    if (lockTtl > 0) {
      const rawAttempts = this.redis.get(attemptsKey);
      return {
        allowed: false,
        retry_after: lockTtl,
        attempts: Math.max(rawAttempts ? parseInt(rawAttempts, 10) : 0, maxAttempts),
      };
    }

    const attempts = this.redis.incr(attemptsKey);
    if (attempts === 1) {
      this.redis.expire(attemptsKey, Math.max(1, windowSeconds));
    }

    if (attempts >= maxAttempts) {
      const lockTtlToSet = Math.max(1, lockoutSeconds);
      this.redis.setex(lockKey, lockTtlToSet, '1');
      this.redis.expire(attemptsKey, Math.max(windowSeconds, lockTtlToSet));
      return {
        allowed: false,
        retry_after: lockTtlToSet,
        attempts,
      };
    }

    return {
      allowed: true,
      retry_after: 0,
      attempts,
    };
  }

  clear(key) {
    this.redis.del(`rl:attempts:${key}`, `rl:lock:${key}`);
  }
}

// Initial dataset mirroring PublicService and BlogService
const INITIAL_COURSES = [
  {
    id: 101,
    title: 'Advanced Konkoor Mathematics & Calculus',
    grade: '12th Grade',
    price: 4500000,
    sessions: 36,
    instructor_name: 'Dr. Aria Rostami',
    image_url: 'https://images.unsplash.com/photo-1635070041078-e363dbe005cb?auto=format&fit=crop&w=600&q=80',
  },
  {
    id: 102,
    title: 'Comprehensive Physics — Mechanics & Electromagnetism',
    grade: '11th & 12th Grade',
    price: 4200000,
    sessions: 32,
    instructor_name: 'Eng. Nima Kaviani',
    image_url: 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?auto=format&fit=crop&w=600&q=80',
  },
  {
    id: 103,
    title: 'Organic & Analytical Chemistry Mastery',
    grade: '12th Grade',
    price: 3900000,
    sessions: 28,
    instructor_name: 'Dr. Saraova',
    image_url: 'https://images.unsplash.com/photo-1603126857599-f6e157fa2fe6?auto=format&fit=crop&w=600&q=80',
  },
];

const INITIAL_INSTRUCTORS = [
  {
    id: 201,
    name: 'Dr. Aria Rostami',
    specialty: 'Mathematics & Geometry',
    experience_years: 14,
    courses_count: 8,
    bio: 'PhD in Applied Mathematics, author of 5 national exam preparation textbooks.',
  },
  {
    id: 202,
    name: 'Eng. Nima Kaviani',
    specialty: 'Olympiad & Konkoor Physics',
    experience_years: 11,
    courses_count: 6,
    bio: 'Senior physics lecturer specializing in conceptual problem-solving techniques.',
  },
];

const INITIAL_SUPPORTERS = [
  {
    id: 301,
    name: 'Parisa Mohammadi',
    specialty: 'Experimental Sciences Academic Advisor',
    students_count: 48,
    experience_years: 6,
  },
  {
    id: 302,
    name: 'Keyvan Rahimi',
    specialty: 'Mathematics & Engineering Study Planner',
    students_count: 42,
    experience_years: 5,
  },
];

const INITIAL_BLOG_POSTS = [
  {
    id: 401,
    title: 'How to Build a High-Retention Weekly Study Plan',
    slug: 'high-retention-weekly-study-plan',
    excerpt: 'Proven spaced-repetition and active recall frameworks for exam preparation.',
    content:
      'Active recall combined with structured weekly review blocks improves long-term retention by over 60%. In this guide we break down how to balance problem sets and conceptual review.',
    category: 'Study Strategies',
    author_name: 'Parisa Mohammadi',
    reading_time: 6,
    views: 128,
    featured: true,
    published_at: '2026-10-15T09:00:00Z',
  },
  {
    id: 402,
    title: 'Top 10 Time-Management Mistakes in Mock Exams',
    slug: 'time-management-mistakes-mock-exams',
    excerpt: 'Learn the two-pass exam technique used by top 1% performers.',
    content:
      'Spending more than 3 minutes on an unfamiliar question early in the exam drains cognitive stamina. Use the two-pass triage technique to lock in guaranteed points first.',
    category: 'Exam Techniques',
    author_name: 'Dr. Aria Rostami',
    reading_time: 5,
    views: 94,
    featured: false,
    published_at: '2026-10-20T12:30:00Z',
  },
];

export function createAppContext() {
  const redis = new RedisStore('famo:');
  const cache = new CacheManager(redis);
  const rateLimiter = new RateLimiterEngine(redis, {
    maxAttempts: parseInt(process.env.PUBLIC_RATE_LIMIT_MAX_ATTEMPTS || '60', 10),
    windowSeconds: parseInt(process.env.PUBLIC_RATE_LIMIT_WINDOW_SECONDS || '60', 10),
    lockoutSeconds: parseInt(process.env.PUBLIC_RATE_LIMIT_LOCKOUT_SECONDS || '60', 10),
  });
  const blogPosts = structuredClone(INITIAL_BLOG_POSTS);

  return { redis, cache, rateLimiter, blogPosts };
}

export function handlePublicEndpoint(ctx, method, urlObj, clientIp = '127.0.0.1') {
  const pathname = urlObj.pathname;
  const { redis, cache, rateLimiter, blogPosts } = ctx;

  // Step 1: RateLimitMiddleware('public:ip')
  const rlKey = rateLimiter.key('public:ip', clientIp);
  const rlStatus = rateLimiter.attempt(
    rlKey,
    rateLimiter.maxAttempts,
    rateLimiter.windowSeconds,
    rateLimiter.lockoutSeconds
  );

  const remaining = Math.max(0, rateLimiter.maxAttempts - rlStatus.attempts);
  const baseHeaders = {
    'Content-Type': 'application/json',
    'X-RateLimit-Limit': String(rateLimiter.maxAttempts),
    'X-RateLimit-Remaining': String(remaining),
    Vary: 'Origin',
  };

  if (!rlStatus.allowed) {
    return {
      status: 429,
      headers: {
        ...baseHeaders,
        'Retry-After': String(Math.max(1, rlStatus.retry_after)),
      },
      body: {
        success: false,
        error: {
          code: 'RATE_LIMITED',
          message: 'Too many requests. Please try again later.',
        },
        retry_after: Math.max(1, rlStatus.retry_after),
      },
    };
  }

  // Step 2: PublicCacheMiddleware (300s TTL for GET /api/v1/public/* except /blog/posts/{slug})
  const isPostDetail = /^\/api\/v1\/public\/blog\/posts\/[^/]+$/.test(pathname);
  const httpCacheKey = `http:${pathname}?${urlObj.searchParams.toString()}`;

  if (method === 'GET' && !isPostDetail) {
    const cachedHttp = cache.get('public', httpCacheKey);
    if (cachedHttp && typeof cachedHttp === 'object' && cachedHttp.body) {
      return {
        status: cachedHttp.status || 200,
        headers: {
          ...baseHeaders,
          'Cache-Control': 'public, max-age=300',
          'X-Cache': 'HIT',
        },
        body: JSON.parse(cachedHttp.body),
      };
    }
  }

  let responsePayload = null;
  let status = 200;
  let cacheHeader = 'MISS';

  if (pathname === '/api/v1/public/courses') {
    const { value } = cache.remember('public', 'courses', 300, () => INITIAL_COURSES);
    responsePayload = { success: true, data: value };
  } else if (pathname === '/api/v1/public/instructors') {
    const { value } = cache.remember('public', 'instructors', 300, () => INITIAL_INSTRUCTORS);
    responsePayload = { success: true, data: value };
  } else if (pathname === '/api/v1/public/supporters') {
    const { value } = cache.remember('public', 'supporters', 300, () => INITIAL_SUPPORTERS);
    responsePayload = { success: true, data: value };
  } else if (pathname === '/api/v1/public/blog/categories') {
    const { value } = cache.remember('public', 'blog_categories', 300, () => [
      { category: 'Study Strategies', count: 1 },
      { category: 'Exam Techniques', count: 1 },
    ]);
    responsePayload = { success: true, data: value };
  } else if (pathname === '/api/v1/public/blog/posts') {
    const rawPage = urlObj.searchParams.get('page');
    const rawPerPage = urlObj.searchParams.get('per_page') ?? urlObj.searchParams.get('limit');

    if (rawPage !== null && rawPage !== '' && (!/^\d+$/.test(rawPage) || parseInt(rawPage, 10) < 1)) {
      return {
        status: 422,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'شماره صفحه باید یک عدد صحیح مثبت باشد' },
        },
      };
    }

    if (
      rawPerPage !== null &&
      rawPerPage !== '' &&
      (!/^\d+$/.test(rawPerPage) || parseInt(rawPerPage, 10) < 1 || parseInt(rawPerPage, 10) > 50)
    ) {
      return {
        status: 422,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'تعداد در هر صفحه باید بین 1 تا 50 باشد' },
        },
      };
    }

    const page = Math.max(1, parseInt(rawPage || '1', 10));
    const perPage = Math.max(1, Math.min(50, parseInt(rawPerPage || '20', 10)));
    const search = urlObj.searchParams.get('search') || '';
    const cacheKey = `blog_posts:0:${page}:${perPage}:${search}`;
    const { value } = cache.remember('public', cacheKey, 300, () => {
      const filtered = search
        ? blogPosts.filter((p) => p.title.toLowerCase().includes(search.toLowerCase()))
        : blogPosts;
      return {
        items: filtered,
        pagination: { page, per_page: perPage, total: filtered.length, total_pages: 1 },
      };
    });
    responsePayload = {
      success: true,
      data: { posts: value.items },
      pagination: value.pagination,
      error: null,
    };
  } else if (isPostDetail) {
    const slug = decodeURIComponent(pathname.split('/').pop() || '').trim();
    if (!slug || slug.length > 255) {
      return {
        status: 422,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'نامک پست نامعتبر است' },
        },
      };
    }

    const { value: cachedPost, hit } = cache.remember(
      'public',
      `blog_post:${slug}`,
      300,
      () => {
        const found = blogPosts.find((p) => p.slug === slug);
        return found ? structuredClone(found) : null;
      }
    );

    if (!cachedPost) {
      return {
        status: 404,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'Post not found' },
        },
      };
    }

    cacheHeader = hit ? 'HIT' : 'MISS';
    const post = structuredClone(cachedPost);
    // Mirror BlogService::getPost live view counter in Redis
    const liveViews = redis.incr(`blog_post_views:${post.id}`);
    const livePost = blogPosts.find((p) => p.id === post.id);
    if (livePost) livePost.views += 1;
    post.views = Number(cachedPost.views) + liveViews;

    responsePayload = { success: true, data: { post }, pagination: null, error: null };
  } else if (/^\/api\/v1\/public\/blog\/categories\/[^/]+\/posts$/.test(pathname)) {
    const parts = pathname.split('/');
    const category = decodeURIComponent(parts[parts.length - 2] || '').trim();
    const rawPage = urlObj.searchParams.get('page');
    const rawPerPage = urlObj.searchParams.get('per_page');

    if (!category || category.length > 100) {
      return {
        status: 422,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'دسته‌بندی نامعتبر است' },
        },
      };
    }
    if (rawPage !== null && rawPage !== '' && (!/^\d+$/.test(rawPage) || parseInt(rawPage, 10) < 1)) {
      return {
        status: 422,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'شماره صفحه باید یک عدد صحیح مثبت باشد' },
        },
      };
    }
    if (
      rawPerPage !== null &&
      rawPerPage !== '' &&
      (!/^\d+$/.test(rawPerPage) || parseInt(rawPerPage, 10) < 1 || parseInt(rawPerPage, 10) > 50)
    ) {
      return {
        status: 422,
        headers: baseHeaders,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'تعداد در هر صفحه باید بین 1 تا 50 باشد' },
        },
      };
    }

    const page = Math.max(1, parseInt(rawPage || '1', 10));
    const perPage = Math.max(1, Math.min(50, parseInt(rawPerPage || '20', 10)));
    const cacheKey = `blog_category:${category}:${page}:${perPage}`;
    const { value } = cache.remember('public', cacheKey, 300, () => {
      const filtered = blogPosts.filter(
        (p) => p.category.toLowerCase() === category.toLowerCase()
      );
      return {
        items: filtered,
        pagination: { page, per_page: perPage, total: filtered.length, total_pages: 1 },
      };
    });
    responsePayload = {
      success: true,
      data: { posts: value.items },
      pagination: value.pagination,
      error: null,
    };
  } else {
    status = 404;
    responsePayload = {
      success: false,
      error: { code: 'NOT_FOUND', message: `Unknown public endpoint: ${pathname}` },
    };
  }

  if (status === 200 && method === 'GET' && !isPostDetail) {
    cache.set('public', httpCacheKey, 300, {
      status: 200,
      content_type: 'application/json',
      body: JSON.stringify(responsePayload),
    });
  }

  return {
    status,
    headers: {
      ...baseHeaders,
      'Cache-Control': 'public, max-age=300',
      'X-Cache': cacheHeader,
    },
    body: responsePayload,
  };
}

export function handleHealthEndpoint(ctx) {
  const dbConnected = ctx?.databaseConnected !== false;
  const redisEnabled = ctx?.redisEnabled !== false;
  const redisConnected = redisEnabled && ctx?.redisConnected !== false && Boolean(ctx?.redis);

  const dbStatus = {
    status: dbConnected ? 'connected' : 'disconnected',
    connected: dbConnected,
    latency_ms: 0.42,
  };
  const redisStatus = {
    status: redisConnected ? 'connected' : redisEnabled ? 'disconnected' : 'disabled',
    connected: redisConnected,
    latency_ms: 0.18,
  };

  const overallStatus = !dbConnected
    ? 'unhealthy'
    : redisStatus.status === 'disconnected'
      ? 'degraded'
      : 'healthy';

  return {
    status: dbConnected ? 200 : 503,
    headers: {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-store, no-cache, must-revalidate',
    },
    body: {
      success: dbConnected,
      data: {
        status: overallStatus,
        database: dbStatus,
        redis: redisStatus,
        version: '1.0.0',
        time: new Date().toISOString().replace('T', ' ').slice(0, 19),
      },
      pagination: null,
      error: null,
    },
  };
}

const REQUIRED_PHP_FILES = [
  'public/openapi.yaml',
  'app/Core/Database.php',
  'app/Core/RedisClient.php',
  'app/Core/Cache.php',
  'app/Core/PublicCacheMiddleware.php',
  'app/Core/RateLimiter.php',
  'app/Core/RateLimitMiddleware.php',
  'app/Modules/Blog/BlogController.php',
  'app/Modules/Blog/BlogService.php',
  'config/redis.php',
  'routes/api.php',
];

function loadPhpSourceSnapshots() {
  const result = {};
  for (const relPath of REQUIRED_PHP_FILES) {
    const absPath = path.join(__dirname, relPath);
    if (fs.existsSync(absPath)) {
      result[relPath] = fs.readFileSync(absPath, 'utf8');
    }
  }
  return result;
}

if (process.argv.includes('--check')) {
  for (const relPath of REQUIRED_PHP_FILES) {
    const absPath = path.join(__dirname, relPath);
    if (!fs.existsSync(absPath)) {
      console.error(`Missing required file: ${relPath}`);
      process.exit(1);
    }
  }
  console.log('All FAMO API Redis & RateLimiter files verified.');
  process.exit(0);
}

function renderDashboardHtml() {
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FAMO API — Public Cache & Redis Rate Limiter</title>
  <meta name="description" content="Slim 4 REST API with Redis caching for public endpoints, Redis-backed rate limiting, and server-side input validation." />
  <meta property="og:title" content="FAMO API — Public Cache & Redis Rate Limiter" />
  <meta property="og:description" content="Slim 4 REST API with Redis caching for public endpoints, Redis-backed rate limiting, and server-side input validation." />
  <style>
    :root {
      --bg: #0b0f17;
      --surface: #111827;
      --surface-elevated: #1e293b;
      --border: #263349;
      --text: #f1f5f9;
      --muted: #94a3b8;
      --accent: #38bdf8;
      --emerald: #10b981;
      --amber: #f59e0b;
      --rose: #f43f5e;
      --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-sans);
      line-height: 1.5;
      min-height: 100vh;
    }
    header {
      border-bottom: 1px solid var(--border);
      background: rgba(17, 24, 39, 0.9);
      padding: 1rem 1.75rem;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 0.85rem;
    }
    .brand-mark {
      width: 2.25rem;
      height: 2.25rem;
      border-radius: 0.5rem;
      background: linear-gradient(135deg, #0284c7, #10b981);
      display: grid;
      place-items: center;
      font-family: var(--font-mono);
      font-weight: 700;
      font-size: 0.9rem;
      color: #fff;
    }
    .brand h1 {
      font-size: 1.1rem;
      font-weight: 600;
      letter-spacing: -0.01em;
    }
    .brand p {
      font-size: 0.8rem;
      color: var(--muted);
    }
    .header-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      align-items: center;
    }
    button {
      background: var(--surface-elevated);
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 0.45rem;
      padding: 0.48rem 0.85rem;
      font-size: 0.8rem;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }
    button:hover {
      background: #283548;
      border-color: var(--accent);
    }
    button.primary {
      background: #0284c7;
      border-color: #0ea5e9;
      color: #fff;
    }
    button.primary:hover {
      background: #0369a1;
    }
    button.danger {
      background: rgba(244, 63, 94, 0.14);
      border-color: rgba(244, 63, 94, 0.45);
      color: #fda4af;
    }
    button.danger:hover {
      background: rgba(244, 63, 94, 0.25);
    }
    main {
      max-width: 1440px;
      margin: 0 auto;
      padding: 1.5rem 1.75rem 3rem;
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 1.25rem;
    }
    @media (max-width: 1024px) {
      main { grid-template-columns: 1fr; }
    }
    .panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 0.65rem;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .panel-header {
      padding: 0.9rem 1.15rem;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .panel-title {
      font-size: 0.9rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .panel-body {
      padding: 1.1rem 1.15rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .endpoint-list {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .endpoint-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 0.65rem 0.85rem;
      background: rgba(30, 41, 59, 0.55);
      border: 1px solid var(--border);
      border-radius: 0.45rem;
    }
    .endpoint-meta {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      min-width: 0;
    }
    .method-tag {
      font-family: var(--font-mono);
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--emerald);
      background: rgba(16, 185, 129, 0.12);
      padding: 0.18rem 0.45rem;
      border-radius: 0.25rem;
      flex-shrink: 0;
    }
    .endpoint-path {
      font-family: var(--font-mono);
      font-size: 0.8rem;
      color: var(--text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .endpoint-desc {
      font-size: 0.75rem;
      color: var(--muted);
    }
    .metrics-bar {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 0.65rem;
    }
    .metric-box {
      background: rgba(15, 23, 42, 0.75);
      border: 1px solid var(--border);
      border-radius: 0.45rem;
      padding: 0.65rem 0.8rem;
    }
    .metric-label {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
    }
    .metric-val {
      font-family: var(--font-mono);
      font-size: 1.05rem;
      font-weight: 700;
      margin-top: 0.2rem;
    }
    .val-hit { color: var(--emerald); }
    .val-miss { color: var(--amber); }
    .val-rate { color: var(--accent); }
    .val-err { color: var(--rose); }
    pre {
      background: #070a10;
      border: 1px solid var(--border);
      border-radius: 0.45rem;
      padding: 0.85rem;
      font-family: var(--font-mono);
      font-size: 0.76rem;
      color: #e2e8f0;
      overflow: auto;
      max-height: 290px;
    }
    .redis-table {
      width: 100%;
      border-collapse: collapse;
      font-family: var(--font-mono);
      font-size: 0.75rem;
    }
    .redis-table th, .redis-table td {
      text-align: left;
      padding: 0.5rem 0.6rem;
      border-bottom: 1px solid var(--border);
    }
    .redis-table th {
      color: var(--muted);
      font-weight: 600;
      font-size: 0.7rem;
      text-transform: uppercase;
    }
    .file-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
    }
    .file-tab {
      font-family: var(--font-mono);
      font-size: 0.72rem;
      padding: 0.35rem 0.65rem;
    }
    .file-tab.active {
      background: #0284c7;
      border-color: #38bdf8;
      color: #fff;
    }
    .controls-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 0.7rem 0.85rem;
      background: rgba(30, 41, 59, 0.4);
      border: 1px solid var(--border);
      border-radius: 0.45rem;
    }
    .controls-row label {
      font-size: 0.78rem;
      color: var(--muted);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    input[type="number"] {
      background: var(--bg);
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 0.35rem;
      padding: 0.3rem 0.5rem;
      width: 4.5rem;
      font-family: var(--font-mono);
      font-size: 0.8rem;
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      <div class="brand-mark">RD</div>
      <div>
        <h1>FAMO API — Public Endpoints Redis Cache & Rate Limiter</h1>
        <p>Live verification console for <code>PublicCacheMiddleware</code>, <code>Cache</code>, <code>RateLimiter</code>, and <code>RateLimitMiddleware</code></p>
      </div>
    </div>
    <div class="header-actions">
      <button id="btn-burst" class="primary" title="Send 5 rapid requests to test RateLimiter & Cache HITs">Burst 5 Requests</button>
      <button id="btn-flush-cache" class="danger" title="Run Cache::flushGroup('public')">Flush 'public' Cache Group</button>
      <button id="btn-reset-rl" title="Run RateLimiter::clear('public:ip')">Reset Rate Limiter</button>
    </div>
  </header>

  <main>
    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
      <section class="panel">
        <div class="panel-header">
          <div class="panel-title">Public Page Endpoints (<code>/api/v1/public/*</code>)</div>
          <span style="font-size: 0.75rem; color: var(--muted);">TTL: 300s · Group: <code>public</code></span>
        </div>
        <div class="panel-body">
          <div class="controls-row">
            <label>
              Rate Limit Max Attempts (per 60s window):
              <input id="input-max-attempts" type="number" min="1" max="500" value="60" />
            </label>
            <button id="btn-apply-limit">Apply Rate Limit Threshold</button>
          </div>

          <div class="endpoint-list" id="endpoint-list">
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/health</div>
                  <div class="endpoint-desc">Database::checkHealth() + RedisClient::checkHealth() infrastructure monitor</div>
                </div>
              </div>
              <button data-endpoint="/health">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/courses</div>
                  <div class="endpoint-desc">PublicService::getCourses() + PublicCacheMiddleware</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/courses">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/instructors</div>
                  <div class="endpoint-desc">PublicService::getInstructors() + PublicCacheMiddleware</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/instructors">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/supporters</div>
                  <div class="endpoint-desc">PublicService::getSupporters() + PublicCacheMiddleware</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/supporters">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/blog/posts?page=1&limit=10</div>
                  <div class="endpoint-desc">BlogService::getPublishedPosts() + PublicCacheMiddleware</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/blog/posts?page=1&limit=10">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/blog/posts/high-retention-weekly-study-plan</div>
                  <div class="endpoint-desc">BlogService::getPost($slug) — Redis cached post + live Redis view counter</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/blog/posts/high-retention-weekly-study-plan">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/blog/categories</div>
                  <div class="endpoint-desc">BlogService::getCategories() + PublicCacheMiddleware</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/blog/categories">Send Request</button>
            </div>
            <div class="endpoint-row">
              <div class="endpoint-meta">
                <span class="method-tag">GET</span>
                <div>
                  <div class="endpoint-path">/api/v1/public/blog/categories/Study%20Strategies/posts</div>
                  <div class="endpoint-desc">BlogService::getPostsByCategory() + PublicCacheMiddleware</div>
                </div>
              </div>
              <button data-endpoint="/api/v1/public/blog/categories/Study%20Strategies/posts">Send Request</button>
            </div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-header">
          <div class="panel-title">Last HTTP Response & Headers</div>
          <span id="last-endpoint-label" style="font-family: var(--font-mono); font-size: 0.76rem; color: var(--accent);">/api/v1/public/courses</span>
        </div>
        <div class="panel-body">
          <div class="metrics-bar">
            <div class="metric-box">
              <div class="metric-label">HTTP Status</div>
              <div class="metric-val" id="metric-status">200 OK</div>
            </div>
            <div class="metric-box">
              <div class="metric-label">X-Cache Header</div>
              <div class="metric-val" id="metric-xcache">MISS</div>
            </div>
            <div class="metric-box">
              <div class="metric-label">X-RateLimit-Remaining</div>
              <div class="metric-val val-rate" id="metric-remaining">59 / 60</div>
            </div>
            <div class="metric-box">
              <div class="metric-label">Retry-After</div>
              <div class="metric-val" id="metric-retry">—</div>
            </div>
          </div>
          <pre id="response-viewer">Click any public endpoint above to inspect the live response and Redis headers.</pre>
        </div>
      </section>
    </div>

    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
      <section class="panel">
        <div class="panel-header">
          <div class="panel-title">Live Redis Keyspace (<code>famo:*</code>)</div>
          <span id="redis-key-count" style="font-size: 0.75rem; color: var(--muted);">0 active keys</span>
        </div>
        <div class="panel-body">
          <div style="overflow-x: auto;">
            <table class="redis-table">
              <thead>
                <tr>
                  <th>Redis Key</th>
                  <th>TTL</th>
                  <th>Size</th>
                </tr>
              </thead>
              <tbody id="redis-keys-tbody">
                <tr><td colspan="3" style="color: var(--muted);">No keys stored yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-header">
          <div class="panel-title">OpenAPI 3.1 Spec & PHP Source Inspector</div>
          <a href="/public/openapi.yaml" target="_blank" style="font-size: 0.75rem; color: var(--accent); text-decoration: none;">Download public/openapi.yaml ↗</a>
        </div>
        <div class="panel-body">
          <div class="file-tabs" id="file-tabs"></div>
          <pre id="php-source-viewer" style="max-height: 360px;">Loading OpenAPI specification & PHP source files...</pre>
        </div>
      </section>
    </div>
  </main>

  <script>
    let currentEndpoint = '/api/v1/public/courses';
    let phpFiles = {};
    let activePhpFile = 'public/openapi.yaml';

    async function refreshState() {
      const res = await fetch('/api/v1/dev/state');
      const data = await res.json();
      phpFiles = data.phpFiles || {};
      document.getElementById('input-max-attempts').value = data.rateLimit.maxAttempts;

      const tbody = document.getElementById('redis-keys-tbody');
      const keys = data.redis.keys || [];
      document.getElementById('redis-key-count').textContent = keys.length + ' active keys';

      if (keys.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" style="color: var(--muted);">No keys in Redis. Send a public endpoint request to populate cache & rate limiter keys.</td></tr>';
      } else {
        tbody.innerHTML = keys.map(k => \`
          <tr>
            <td style="color: #38bdf8;">famo:\${k.key}</td>
            <td>\${k.ttl >= 0 ? k.ttl + 's' : 'persisted'}</td>
            <td>\${k.sizeBytes} B</td>
          </tr>
        \`).join('');
      }

      renderFileTabs();
    }

    function renderFileTabs() {
      const tabsEl = document.getElementById('file-tabs');
      const filenames = Object.keys(phpFiles);
      tabsEl.innerHTML = filenames.map(f => \`
        <button class="file-tab \${f === activePhpFile ? 'active' : ''}" data-file="\${f}">\${f}</button>
      \`).join('');

      tabsEl.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => {
          activePhpFile = btn.getAttribute('data-file');
          renderFileTabs();
        });
      });

      const sourceEl = document.getElementById('php-source-viewer');
      sourceEl.textContent = phpFiles[activePhpFile] || '';
    }

    async function callPublicEndpoint(endpoint) {
      currentEndpoint = endpoint;
      document.getElementById('last-endpoint-label').textContent = endpoint;

      const res = await fetch(endpoint);
      const status = res.status;
      const xCache = res.headers.get('X-Cache') || 'BYPASS';
      const limit = res.headers.get('X-RateLimit-Limit') || '60';
      const remaining = res.headers.get('X-RateLimit-Remaining') || '0';
      const retryAfter = res.headers.get('Retry-After');
      const body = await res.json();

      const statusEl = document.getElementById('metric-status');
      statusEl.textContent = status === 200 ? '200 OK' : status + (status === 422 ? ' VALIDATION ERROR' : ' RATE LIMITED');
      statusEl.className = 'metric-val ' + (status === 200 ? 'val-hit' : 'val-err');

      const cacheEl = document.getElementById('metric-xcache');
      cacheEl.textContent = xCache;
      cacheEl.className = 'metric-val ' + (xCache === 'HIT' ? 'val-hit' : 'val-miss');

      document.getElementById('metric-remaining').textContent = remaining + ' / ' + limit;
      document.getElementById('metric-retry').textContent = retryAfter ? retryAfter + 's' : '—';

      document.getElementById('response-viewer').textContent = JSON.stringify(
        {
          headers: {
            'HTTP-Status': status,
            'X-Cache': xCache,
            'Cache-Control': res.headers.get('Cache-Control'),
            'X-RateLimit-Limit': limit,
            'X-RateLimit-Remaining': remaining,
            ...(retryAfter ? { 'Retry-After': retryAfter } : {}),
          },
          body,
        },
        null,
        2
      );

      await refreshState();
    }

    document.querySelectorAll('[data-endpoint]').forEach(btn => {
      btn.addEventListener('click', () => callPublicEndpoint(btn.getAttribute('data-endpoint')));
    });

    document.getElementById('btn-flush-cache').addEventListener('click', async () => {
      await fetch('/api/v1/dev/cache/flush', { method: 'POST' });
      await refreshState();
    });

    document.getElementById('btn-reset-rl').addEventListener('click', async () => {
      await fetch('/api/v1/dev/ratelimit/reset', { method: 'POST' });
      await callPublicEndpoint(currentEndpoint);
    });

    document.getElementById('btn-apply-limit').addEventListener('click', async () => {
      const maxAttempts = parseInt(document.getElementById('input-max-attempts').value, 10) || 60;
      await fetch('/api/v1/dev/ratelimit/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ maxAttempts }),
      });
      await refreshState();
    });

    document.getElementById('btn-burst').addEventListener('click', async () => {
      for (let i = 0; i < 5; i++) {
        await callPublicEndpoint(currentEndpoint);
      }
    });

    // Run initial request on load so state is populated immediately
    callPublicEndpoint('/api/v1/public/courses');
  </script>
</body>
</html>`;
}

export function startServer(port = parseInt(process.env.PORT || '3000', 10)) {
  const ctx = createAppContext();
  const phpFiles = loadPhpSourceSnapshots();

  const server = http.createServer((req, res) => {
    const urlObj = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
    const pathname = urlObj.pathname;
    const method = (req.method || 'GET').toUpperCase();

    if (pathname === '/health' || pathname === '/api/v1/health') {
      const healthRes = handleHealthEndpoint(ctx);
      res.writeHead(healthRes.status, healthRes.headers);
      res.end(JSON.stringify(healthRes.body));
      return;
    }

    if (
      (pathname === '/openapi.yaml' || pathname === '/public/openapi.yaml') &&
      (method === 'GET' || method === 'HEAD')
    ) {
      const targetFile =
        pathname === '/public/openapi.yaml'
          ? path.join(__dirname, 'public/openapi.yaml')
          : path.join(__dirname, 'openapi.yaml');
      if (fs.existsSync(targetFile)) {
        res.writeHead(200, { 'Content-Type': 'application/yaml; charset=utf-8' });
        res.end(method === 'HEAD' ? undefined : fs.readFileSync(targetFile, 'utf8'));
        return;
      }
    }

    if (pathname === '/api/v1/dev/state' && method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(
        JSON.stringify({
          redis: ctx.redis.inspectAll(),
          rateLimit: {
            maxAttempts: ctx.rateLimiter.maxAttempts,
            windowSeconds: ctx.rateLimiter.windowSeconds,
            lockoutSeconds: ctx.rateLimiter.lockoutSeconds,
          },
          phpFiles,
        })
      );
      return;
    }

    if (pathname === '/api/v1/dev/cache/flush' && method === 'POST') {
      ctx.cache.flushGroup('public');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true, flushedGroup: 'public' }));
      return;
    }

    if (pathname === '/api/v1/dev/ratelimit/reset' && method === 'POST') {
      const rlKey = ctx.rateLimiter.key('public:ip', '127.0.0.1');
      ctx.rateLimiter.clear(rlKey);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true }));
      return;
    }

    if (pathname === '/api/v1/dev/ratelimit/config' && method === 'POST') {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        try {
          const parsed = JSON.parse(raw || '{}');
          if (parsed.maxAttempts) {
            ctx.rateLimiter.maxAttempts = Math.max(1, parseInt(parsed.maxAttempts, 10));
          }
          const rlKey = ctx.rateLimiter.key('public:ip', '127.0.0.1');
          ctx.rateLimiter.clear(rlKey);
        } catch {
          // ignore malformed JSON
        }
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(
          JSON.stringify({
            success: true,
            maxAttempts: ctx.rateLimiter.maxAttempts,
          })
        );
      });
      return;
    }

    if (pathname.startsWith('/api/v1/public/')) {
      const result = handlePublicEndpoint(ctx, method, urlObj, '127.0.0.1');
      res.writeHead(result.status, result.headers);
      res.end(JSON.stringify(result.body));
      return;
    }

    if (pathname === '/' || pathname === '/index.html') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(renderDashboardHtml());
      return;
    }

    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: false, error: { code: 'NOT_FOUND', message: 'Route not found' } }));
  });

  server.listen(port, '0.0.0.0', () => {
    console.log(`FAMO API dev server listening on http://0.0.0.0:${port}`);
  });

  return server;
}

if (process.argv[1] && path.resolve(process.argv[1]) === __filename) {
  startServer();
}
