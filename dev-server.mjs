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

  eval(script, keys = [], args = []) {
    const strScript = String(script || '');
    if (strScript.includes("redis.call('INCR'") || strScript.includes('redis.call("INCR"')) {
      const key = keys[0];
      if (!key) return null;
      const windowSeconds = Math.max(1, parseInt(args[0] ?? '60', 10) || 60);
      const next = this.incr(key);
      let remTtl = this.ttl(key);
      if (next === 1 || remTtl < 0) {
        this.expire(key, windowSeconds);
        remTtl = windowSeconds;
      }
      return [next, Math.max(0, remTtl)];
    }
    if (strScript.includes("redis.call('SETEX'") || strScript.includes('redis.call("SETEX"')) {
      const key = keys[0];
      const ttlSeconds = Math.max(1, parseInt(args[0] ?? '1', 10) || 1);
      const val = String(args[1] ?? '');
      this.setex(key, ttlSeconds, val);
      return 'OK';
    }
    if (strScript.includes("redis.call('GET'") || strScript.includes('redis.call("GET"')) {
      return keys[0] ? this.get(keys[0]) : null;
    }
    if (strScript.includes("redis.call('DEL'") || strScript.includes('redis.call("DEL"')) {
      return keys.length > 0 ? this.del(...keys) : 0;
    }
    return null;
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

  attemptFixedWindow(key, maxAttempts = this.maxAttempts, windowSeconds = this.windowSeconds) {
    const max = Math.max(1, Number(maxAttempts) || 1);
    const win = Math.max(1, Number(windowSeconds) || 60);
    const redisKey = `rl:fw:${key}`;
    const res = this.redis.eval(
      "local current = redis.call('INCR', KEYS[1])",
      [redisKey],
      [String(win)]
    );
    const attempts = Array.isArray(res) ? Number(res[0]) : 1;
    const ttl = Array.isArray(res) ? Math.max(1, Number(res[1])) : win;
    const allowed = attempts <= max;
    return {
      allowed,
      retry_after: allowed ? 0 : ttl,
      attempts,
      remaining: Math.max(0, max - attempts),
      limit: max,
    };
  }

  clear(key) {
    this.redis.del(`rl:attempts:${key}`, `rl:lock:${key}`, `rl:fw:${key}`);
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
    grade: 12,
    field: 'تجربی',
    specialty: 'Experimental Sciences Academic Advisor',
    description: 'رتبه برتر کنکور تجربی و مشاور تخصصی برنامه‌ریزی و پیگیری روزانه دانش‌آموزان پایه دوازدهم.',
    avatar: 'supporters/301/parisa-mohammadi.webp',
    avatar_url: 'https://api.example.com/uploads/supporters/301/parisa-mohammadi.webp',
    image_url: 'https://api.example.com/uploads/supporters/301/parisa-mohammadi.webp',
    students_count: 48,
    experience_years: 6,
  },
  {
    id: 302,
    name: 'Keyvan Rahimi',
    grade: 12,
    field: 'ریاضی',
    specialty: 'Mathematics & Engineering Study Planner',
    description: 'پشتیبان تخصصی رشته ریاضی و فیزیک با ۵ سال سابقه هدایت تحصیلی و تحلیل آزمون.',
    avatar: 'supporters/302/keyvan-rahimi.webp',
    avatar_url: 'https://api.example.com/uploads/supporters/302/keyvan-rahimi.webp',
    image_url: 'https://api.example.com/uploads/supporters/302/keyvan-rahimi.webp',
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

export function normalizeIranianPhone(raw) {
  if (raw === null || raw === undefined || typeof raw === 'boolean') return null;
  let str = String(raw).trim();
  if (!str) return null;
  const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  const arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
  for (let i = 0; i < 10; i++) {
    str = str.replaceAll(persian[i], String(i)).replaceAll(arabic[i], String(i));
  }
  str = str.replace(/[\s\-().]/g, '');
  if (str.startsWith('+98')) str = '0' + str.slice(3);
  else if (str.startsWith('0098')) str = '0' + str.slice(4);
  else if (str.startsWith('98') && str.length === 12) str = '0' + str.slice(2);
  else if (str.startsWith('9') && str.length === 10) str = '0' + str;
  return /^09\d{9}$/.test(str) ? str : null;
}

export function createAppContext() {
  const redis = new RedisStore('famo:');
  const cache = new CacheManager(redis);
  const rateLimiter = new RateLimiterEngine(redis, {
    maxAttempts: parseInt(process.env.PUBLIC_RATE_LIMIT_MAX_ATTEMPTS || '60', 10),
    windowSeconds: parseInt(process.env.PUBLIC_RATE_LIMIT_WINDOW_SECONDS || '60', 10),
    lockoutSeconds: parseInt(process.env.PUBLIC_RATE_LIMIT_LOCKOUT_SECONDS || '60', 10),
  });
  const blogPosts = structuredClone(INITIAL_BLOG_POSTS);

  const admins = [
    { id: 1, name: 'ادمین اصلی', phone: '09120000001', chat_id: '1001', is_active: 1 },
  ];
  const supporters = [
    {
      id: 10,
      name: 'پشتیبان اول',
      phone: '09120000002',
      grade: 12,
      field: 'تجربی',
      description: 'پشتیبان تخصصی رشته تجربی پایه دوازدهم با سابقه مشاوره رتبه‌های برتر کنکور',
      avatar: 'supporters/10/avatar.webp',
      avatar_url: 'https://api.example.com/uploads/supporters/10/avatar.webp',
      image_url: 'https://api.example.com/uploads/supporters/10/avatar.webp',
      is_active: 1,
    },
  ];
  const students = [
    { id: 20, name: 'دانش‌آموز نمونه', phone: '09120000003', national_id: '0012345678', grade: 12, field: 'ریاضی', is_active: 1 },
  ];
  const instructors = [
    { id: 30, name: 'استاد نمونه', title: 'مدرس ریاضی', is_active: 1 },
  ];
  const users = [
    {
      id: 1,
      username: 'admin_root',
      phone: '09120000001',
      password: 'admin123',
      full_name: 'ادمین اصلی',
      role: 'admin',
      linked_id: 1,
      chat_id: '1001',
      is_active: 1,
    },
    {
      id: 2,
      username: '09120000002',
      phone: '09120000002',
      password: 'supp123',
      full_name: 'پشتیبان اول',
      role: 'supporter',
      linked_id: 10,
      chat_id: null,
      is_active: 1,
    },
    {
      id: 3,
      username: '09120000003',
      phone: '09120000003',
      password: 'stud123',
      full_name: 'دانش‌آموز نمونه',
      role: 'student',
      linked_id: 20,
      chat_id: null,
      is_active: 1,
    },
    {
      id: 4,
      username: 'teacher_one',
      phone: '09120000004',
      password: 'teach123',
      full_name: 'استاد نمونه',
      role: 'teacher',
      linked_id: 30,
      chat_id: null,
      is_active: 1,
    },
  ];

  const examResults = [
    {
      id: 1,
      student_id: 20,
      exam_date: '2026-10-25',
      subject: 'ریاضی',
      chapter: 'مشتق',
      total_q: 20,
      correct: 15,
      wrong: 3,
      skipped: 2,
      percentage: 70.0,
    },
    {
      id: 2,
      student_id: 20,
      exam_date: '2026-10-25',
      subject: 'فیزیک',
      chapter: 'دینامیک',
      total_q: 20,
      correct: 12,
      wrong: 0,
      skipped: 8,
      percentage: 60.0,
    },
  ];
  const examComments = [];
  const examResultLinks = [];
  const parentContacts = [
    {
      id: 1,
      student_id: 20,
      parent_name: 'پدر دانش‌آموز نمونه',
      relationship: 'father',
      phone: '09121112233',
      is_primary: 1,
    },
    {
      id: 2,
      student_id: 20,
      parent_name: 'مادر دانش‌آموز نمونه',
      relationship: 'mother',
      phone: '09124445566',
      is_primary: 0,
    },
  ];
  const smsDispatches = [];
  const appointments = [
    {
      id: 1,
      student_id: 20,
      supporter_id: 10,
      appointment_date: '2026-10-24',
      start_time: '16:30:00',
      end_time: '17:00:00',
      status: 'scheduled',
      notes: 'جلسه مشاوره هفتگی',
    },
  ];
  const appointmentSmsLogs = [];
  const tutoringClassrooms = [
    {
      id: 1,
      classroom_id: 1,
      name: 'کلاس ریاضی ۱',
      class_name: 'کلاس ریاضی ۱',
      classroom_name: 'کلاس ریاضی ۱',
      instructor_id: 30,
      teacher_name: 'استاد نمونه',
      field: 'ریاضی',
      sort_order: 1,
      is_active: true,
    },
  ];
  const tutoringClassroomAuditLogs = [
    {
      id: 1,
      classroom_id: 1,
      instructor_id: 30,
      actor_user_id: 1,
      actor_role: 'admin',
      actor_instructor_id: null,
      admin_or_instructor_id: 1,
      action: 'created',
      summary: 'کلاس «کلاس ریاضی ۱» ایجاد شد',
      changes: {
        before: null,
        after: { ...tutoringClassrooms[0] },
        diff: {
          name: { from: null, to: 'کلاس ریاضی ۱' },
          teacher_name: { from: null, to: 'استاد نمونه' },
          field: { from: null, to: 'ریاضی' },
          instructor_id: { from: null, to: 30 },
          sort_order: { from: null, to: 1 },
          is_active: { from: null, to: true },
        },
      },
      timestamp: '2026-11-15 08:00:00',
      created_at: '2026-11-15 08:00:00',
    },
  ];
  const staffActivityLogs = [
    {
      id: 1,
      actor_user_id: 1,
      actor_role: 'admin',
      actor_role_label: 'ادمین',
      actor_linked_id: 1,
      actor_name: 'مدیر ارشد',
      module: 'tutoring',
      action: 'create_classroom',
      target_type: 'tutoring_classroom',
      target_id: '1',
      summary: 'ایجاد ستون کلاس رفع اشکال جدید «کلاس ریاضی ۱»',
      http_method: 'POST',
      endpoint: '/api/v1/tutoring/classrooms',
      status_code: 201,
      ip_address: '127.0.0.1',
      details: { request: { name: 'کلاس ریاضی ۱', teacher_name: 'استاد نمونه', field: 'ریاضی' } },
      timestamp: '2026-11-15 08:00:00',
      created_at: '2026-11-15 08:00:00',
    },
    {
      id: 2,
      actor_user_id: 2,
      actor_role: 'supporter',
      actor_role_label: 'پشتیبان',
      actor_linked_id: 10,
      actor_name: 'پشتیبان اول',
      module: 'appointments',
      action: 'create',
      target_type: 'appointment',
      target_id: '1',
      summary: 'ثبت نوبت مشاوره جدید (#1) برای دانش‌آموز #20',
      http_method: 'POST',
      endpoint: '/api/v1/appointments',
      status_code: 201,
      ip_address: '127.0.0.1',
      details: { request: { student_id: 20, date: '2026-10-25', start_time: '16:30' } },
      timestamp: '2026-11-15 08:15:00',
      created_at: '2026-11-15 08:15:00',
    },
    {
      id: 3,
      actor_user_id: 4,
      actor_role: 'teacher',
      actor_role_label: 'استاد / مدرس',
      actor_linked_id: 30,
      actor_name: 'استاد نمونه',
      module: 'exams',
      action: 'upsert_comment',
      target_type: 'exam_comment',
      target_id: '20',
      summary: 'ثبت/ویرایش نظر مشاور در کارنامه آزمون دانش‌آموز #20',
      http_method: 'PUT',
      endpoint: '/api/v1/exams/comments',
      status_code: 200,
      ip_address: '127.0.0.1',
      details: { request: { student_id: 20, exam_date: '2026-04-10' } },
      timestamp: '2026-11-15 08:30:00',
      created_at: '2026-11-15 08:30:00',
    },
  ];

  const courses = [
    {
      id: 101,
      name: 'Advanced Konkoor Mathematics & Calculus',
      price: 4500000,
      monthly_fee: 450000,
      icon: '📐',
      gradient_color_from: '#0284c7',
      gradient_color_to: '#10b981',
      description: 'دوره جامع حسابان و ریاضیات کنکور با حل تست‌های پیشرفته',
      is_active: 1,
    },
    {
      id: 102,
      name: 'Comprehensive Physics — Mechanics & Electromagnetism',
      price: 4200000,
      monthly_fee: 420000,
      icon: '⚡',
      gradient_color_from: '#8b5cf6',
      gradient_color_to: '#ec4899',
      description: 'دوره فیزیک جامع کنکور شامل مفاهیم فیزیک پایه و دوازدهم',
      is_active: 1,
    },
    {
      id: 103,
      name: 'Organic & Analytical Chemistry Mastery',
      price: 3900000,
      monthly_fee: 390000,
      icon: '🧪',
      gradient_color_from: '#f59e0b',
      gradient_color_to: '#ef4444',
      description: 'دوره جامع شیمی کنکور با روش‌های تست‌زنی سریع و مفهومی',
      is_active: 1,
    },
  ];

  const subscriptions = [
    {
      id: 1,
      course_id: 101,
      student_id: 20,
      status: 'active',
      is_active: 1,
      monthly_fee: 450000,
      tuition_status: 'paid',
      tuition_due_date: '2026-12-01',
      last_payment_date: '2026-11-01',
      tuition_notes: 'شهریه آبان‌ماه واریز شد',
      note: 'ثبت‌نام سال تحصیلی ۱۴۰۵',
      started_at: '2026-11-01 10:00:00',
      end_at: null,
      created_at: '2026-11-01 10:00:00',
      updated_at: '2026-11-01 10:00:00',
    },
  ];

  const smsPatterns = {
    auth_2fa: {
      pattern_key: 'auth_2fa',
      label: 'کد تایید ورود دو مرحله‌ای (لاگین ادمین و کادر)',
      env_key: 'MELIPAYAMAK_PATTERN_BODY_ID',
      alias_field: 'auth_body_id',
      env_body_id: 500001,
      db_body_id: null,
      pattern_text: 'کد تایید ورود به پنل فامو: {0}',
      placeholders: ['کد تایید ۶ رقمی'],
      updated_by: null,
      updated_at: null,
    },
    appointment_reminder: {
      pattern_key: 'appointment_reminder',
      label: 'یادآوری نوبت هفتگی شاگردان به والدین',
      env_key: 'MELIPAYAMAK_APPOINTMENT_BODY_ID',
      alias_field: 'appointment_body_id',
      env_body_id: 500002,
      db_body_id: null,
      pattern_text:
        'موسسه آموزشی فامو | دکتر موسوی والدین گرامی {0} ، نوبت فرزند شما روز {1} ساعت {2} میباشد. در صورت تایید به شماره موسسه پیامک دهید.',
      placeholders: ['نام و نام خانوادگی دانش‌آموز', 'روز و تاریخ شمسی', 'ساعت نوبت'],
      updated_by: null,
      updated_at: null,
    },
    exam_result: {
      pattern_key: 'exam_result',
      label: 'ارسال لینک کارنامه آزمون به والدین',
      env_key: 'MELIPAYAMAK_EXAM_BODY_ID',
      alias_field: 'exam_body_id',
      env_body_id: 500003,
      db_body_id: null,
      pattern_text: 'کارنامه آزمون {0} در تاریخ {1}: {2}',
      placeholders: ['نام و نام خانوادگی دانش‌آموز', 'تاریخ آزمون', 'لینک کارنامه'],
      updated_by: null,
      updated_at: null,
    },
  };

  return {
    redis,
    cache,
    rateLimiter,
    blogPosts,
    users,
    admins,
    supporters,
    students,
    instructors,
    courses,
    subscriptions,
    examResults,
    examComments,
    examResultLinks,
    parentContacts,
    smsDispatches,
    smsPatterns,
    appointments,
    appointmentSmsLogs,
    tutoringClassrooms,
    tutoringClassroomAuditLogs,
    staffActivityLogs,
    smsGatewayAvailable: true,
    smsFailPhones: [],
    smsPhoneHourlyMax: 5,
    smsIpHourlyMax: 20,
    appointmentSmsPhoneHourlyMax: 3,
    appointmentSmsUserHourlyMax: 200,
  };
}

export function handleAuthEndpoint(ctx, method, pathname, body = {}, authUser = null) {
  if (method === 'POST' && pathname === '/api/v1/auth/logout') {
    if (authUser && authUser.jti) {
      const nowSec = Math.floor(Date.now() / 1000);
      const exp = typeof authUser.exp === 'number' ? authUser.exp : nowSec + 86400;
      const ttl = Math.max(1, exp - nowSec);
      ctx.redis.setex(`jwt:bl:${authUser.jti}`, ttl, '1');
    }
    return {
      status: 200,
      headers: {
        'Set-Cookie': 'famo_jwt=; Max-Age=0; Path=/; HttpOnly',
      },
      body: {
        success: true,
        data: null,
        pagination: null,
        error: null,
      },
    };
  }

  if (!authUser || !authUser.sub) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'AUTH_ERROR', message: 'توکن احراز هویت ارسال نشده است' },
      },
    };
  }

  if (authUser.jti && ctx.redis.get(`jwt:bl:${authUser.jti}`) !== null) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'UNAUTHORIZED', message: 'توکن نامعتبر یا منقضی شده است' },
      },
    };
  }

  const epochRaw = ctx.redis.get(`jwt:user_epoch:${Number(authUser.sub)}`);
  if (epochRaw !== null && typeof authUser.iat === 'number' && authUser.iat < Number(epochRaw)) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'UNAUTHORIZED', message: 'توکن نامعتبر یا منقضی شده است' },
      },
    };
  }

  const user = ctx.users.find((u) => u.id === Number(authUser.sub));
  if (!user) {
    return {
      status: 404,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'NOT_FOUND', message: 'کاربر یافت نشد' },
      },
    };
  }

  if (Number(user.is_active ?? 1) === 0) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'UNAUTHORIZED', message: 'حساب کاربری غیرفعال است' },
      },
    };
  }

  if (method === 'POST' && pathname === '/api/v1/auth/change-password') {
    const currentPassword = body?.current_password;
    const newPassword = body?.new_password;

    if (typeof currentPassword !== 'string' || currentPassword.trim() === '') {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'رمز عبور فعلی الزامی است' },
        },
      };
    }

    if (typeof newPassword !== 'string' || newPassword.length < 4) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'رمز عبور جدید باید حداقل 4 کاراکتر باشد' },
        },
      };
    }

    if (user.password !== currentPassword) {
      return {
        status: 401,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'INVALID_PASSWORD', message: 'رمز عبور فعلی اشتباه است' },
        },
      };
    }

    user.password = newPassword;
    const epoch = Math.floor(Date.now() / 1000);
    ctx.redis.setex(`jwt:user_epoch:${user.id}`, 86400, String(epoch));
    const freshJti = crypto.randomBytes(16).toString('hex');
    const freshPayload = {
      sub: user.id,
      role: user.role,
      jti: freshJti,
      typ: 'access',
      iat: epoch,
      exp: epoch + 86400,
    };
    const freshToken = Buffer.from(JSON.stringify(freshPayload)).toString('base64url');
    return {
      status: 200,
      headers: {
        'Set-Cookie': `famo_jwt=${freshToken}; Path=/; HttpOnly`,
      },
      body: {
        success: true,
        data: {
          message: 'رمز عبور با موفقیت تغییر یافت',
          token: freshToken,
          user: {
            id: user.id,
            username: user.username,
            role: user.role,
            student_id: user.role === 'student' && user.linked_id > 0 ? user.linked_id : null,
            instructor_id: user.role === 'teacher' && user.linked_id > 0 ? user.linked_id : null,
          },
        },
        pagination: null,
        error: null,
      },
    };
  }

  if (method === 'PUT' && pathname === '/api/v1/auth/profile') {
    const rawName = body?.full_name ?? body?.name;
    if (typeof rawName !== 'string' || rawName.trim().length < 2 || rawName.trim().length > 150) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'نام و نام خانوادگی الزامی است' },
        },
      };
    }

    const normalizedPhone = normalizeIranianPhone(body?.phone);
    if (!normalizedPhone) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'شماره تلفن معتبر نیست (نمونه صحیح: 09123456789)' },
        },
      };
    }

    const oldPhone = normalizeIranianPhone(user.phone) || normalizeIranianPhone(user.username) || '';
    const phoneChanged = normalizedPhone !== oldPhone;
    const twoFaRoles = ['admin', 'supporter', 'teacher'];

    if (phoneChanged && twoFaRoles.includes(user.role)) {
      const currentPassword = typeof body?.current_password === 'string' ? body.current_password : '';
      if (!currentPassword) {
        return {
          status: 422,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: {
              code: 'VALIDATION_ERROR',
              message: 'برای تغییر شماره تلفن، وارد کردن رمز عبور فعلی الزامی است',
            },
          },
        };
      }
      if (user.password !== currentPassword) {
        return {
          status: 401,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'INVALID_PASSWORD', message: 'رمز عبور فعلی اشتباه است' },
          },
        };
      }
    }

    const syncUsername =
      user.username === user.phone || (oldPhone && normalizeIranianPhone(user.username) === oldPhone);

    const duplicateUser = ctx.users.find(
      (u) =>
        u.id !== user.id &&
        (u.phone === normalizedPhone || (syncUsername && u.username === normalizedPhone))
    );
    if (duplicateUser) {
      return {
        status: 409,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: {
            code: 'DUPLICATE_PHONE',
            message: 'این شماره تلفن قبلاً برای کاربر دیگری ثبت شده است',
          },
        },
      };
    }

    const fullName = rawName.trim();
    user.full_name = fullName;
    user.phone = normalizedPhone;
    if (syncUsername) {
      user.username = normalizedPhone;
    }

    if (user.linked_id > 0) {
      if (user.role === 'admin') {
        const rec = ctx.admins.find((a) => a.id === user.linked_id);
        if (rec) {
          rec.name = fullName;
          rec.phone = normalizedPhone;
        }
      } else if (user.role === 'supporter') {
        const rec = ctx.supporters.find((s) => s.id === user.linked_id);
        if (rec) {
          rec.name = fullName;
          rec.phone = normalizedPhone;
        }
      } else if (user.role === 'student') {
        const rec = ctx.students.find((s) => s.id === user.linked_id);
        if (rec) {
          rec.name = fullName;
          rec.phone = normalizedPhone;
        }
      } else if (user.role === 'teacher') {
        const rec = ctx.instructors.find((i) => i.id === user.linked_id);
        if (rec) {
          rec.name = fullName;
        }
      }
    }

    return {
      status: 200,
      body: {
        success: true,
        data: {
          id: user.id,
          username: user.username,
          phone: user.phone,
          role: user.role,
          full_name: user.full_name,
          student_id: user.role === 'student' && user.linked_id > 0 ? user.linked_id : null,
          instructor_id: user.role === 'teacher' && user.linked_id > 0 ? user.linked_id : null,
        },
        pagination: null,
        error: null,
      },
    };
  }

  return {
    status: 404,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' },
    },
  };
}

export function handleExamEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  if (!authUser || !authUser.sub) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'AUTH_ERROR', message: 'توکن احراز هویت ارسال نشده است' },
      },
    };
  }

  const pathname = urlObj.pathname;
  const user = ctx.users.find((u) => u.id === Number(authUser.sub));
  if (!user) {
    return {
      status: 404,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'NOT_FOUND', message: 'کاربر یافت نشد' },
      },
    };
  }

  const formatCommentsFor = (studentId, examDate) => {
    return ctx.examComments
      .filter((c) => c.student_id === studentId && c.exam_date === examDate)
      .sort((a, b) => b.id - a.id)
      .map((c) => {
        const author = ctx.users.find((u) => u.id === c.teacher_user_id);
        let authorName = author?.full_name || author?.username || '';
        if (author?.role === 'teacher' && author?.linked_id > 0) {
          const inst = ctx.instructors.find((i) => i.id === author.linked_id);
          if (inst?.name) authorName = inst.name;
        }
        return {
          id: c.id,
          student_id: c.student_id,
          exam_date: c.exam_date,
          teacher_user_id: c.teacher_user_id,
          author_name: authorName,
          author_role: author?.role || 'teacher',
          body: c.body,
          created_at: c.created_at,
          updated_at: c.updated_at,
        };
      });
  };

  if (method === 'GET' && pathname === '/api/v1/exams/details') {
    const examDate = urlObj.searchParams.get('exam_date') || '';
    const requestedStudentId = Number(urlObj.searchParams.get('student_id') || 0);

    let studentId = requestedStudentId;
    if (user.role === 'student') {
      const selfId = Number(user.linked_id || 0);
      if (requestedStudentId > 0 && requestedStudentId !== selfId) {
        return {
          status: 403,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'FORBIDDEN', message: 'دسترسی غیرمجاز' },
          },
        };
      }
      studentId = selfId;
    }

    if (!examDate || studentId <= 0) {
      return {
        status: 400,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'پارامترها ناقص است' },
        },
      };
    }

    const student = ctx.students.find((s) => s.id === studentId);
    if (!student) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'دانش‌آموز یافت نشد' },
        },
      };
    }

    const subjects = ctx.examResults
      .filter((r) => r.student_id === studentId && r.exam_date === examDate)
      .map((r) => ({
        subject: r.subject,
        chapter: r.chapter,
        total_q: r.total_q,
        correct: r.correct,
        wrong: r.wrong,
        skipped: r.skipped,
        percentage: r.percentage,
      }));

    const avg =
      subjects.length > 0
        ? Number((subjects.reduce((acc, s) => acc + s.percentage, 0) / subjects.length).toFixed(1))
        : 0;

    return {
      status: 200,
      body: {
        success: true,
        data: {
          student: {
            id: student.id,
            name: student.name,
            grade: student.grade,
            field: student.field,
          },
          subjects,
          avg_percentage: avg,
          comments: formatCommentsFor(studentId, examDate),
        },
        pagination: null,
        error: null,
      },
    };
  }

  if (method === 'PUT' && pathname === '/api/v1/exams/comments') {
    if (!['admin', 'supporter', 'teacher'].includes(user.role)) {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'دسترسی محدود به اساتید، پشتیبانان و مدیران' },
        },
      };
    }

    const studentId = Number(body?.student_id);
    const examDate = typeof body?.exam_date === 'string' ? body.exam_date.trim() : '';
    const commentText = typeof (body?.body ?? body?.comment) === 'string' ? (body.body ?? body.comment).trim() : '';

    if (
      !Number.isInteger(studentId) ||
      studentId <= 0 ||
      !/^\d{4}-\d{2}-\d{2}$/.test(examDate) ||
      !commentText ||
      commentText.length > 2000
    ) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'داده‌های ناقص یا نامعتبر است' },
        },
      };
    }

    const student = ctx.students.find((s) => s.id === studentId);
    if (!student) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'دانش‌آموز یافت نشد' },
        },
      };
    }

    const hasExam = ctx.examResults.some((r) => r.student_id === studentId && r.exam_date === examDate);
    if (!hasExam) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'نتیجه آزمونی برای این دانش‌آموز در این تاریخ ثبت نشده است' },
        },
      };
    }

    const now = new Date().toISOString().replace('T', ' ').slice(0, 19);
    let existing = ctx.examComments.find(
      (c) => c.student_id === studentId && c.exam_date === examDate && c.teacher_user_id === user.id
    );

    if (existing) {
      existing.body = commentText;
      existing.updated_at = now;
    } else {
      existing = {
        id: ctx.examComments.length + 1,
        student_id: studentId,
        exam_date: examDate,
        teacher_user_id: user.id,
        body: commentText,
        created_at: now,
        updated_at: now,
      };
      ctx.examComments.push(existing);
    }

    const allComments = formatCommentsFor(studentId, examDate);
    const savedComment = allComments.find((c) => c.id === existing.id);

    return {
      status: 200,
      body: {
        success: true,
        data: {
          comment: savedComment,
          comments: allComments,
        },
        pagination: null,
        error: null,
      },
    };
  }

  const maskPhone = (phone) => {
    if (!phone || phone.length < 7) return '***';
    return `${phone.slice(0, 4)}***${phone.slice(-2)}`;
  };

  if (method === 'GET' && pathname === '/api/v1/exams/share-links') {
    if (!['admin', 'supporter'].includes(user.role)) {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'دسترسی محدود به پشتیبانان و مدیران' },
        },
      };
    }

    const filterStudentId = urlObj.searchParams.get('student_id')
      ? Number(urlObj.searchParams.get('student_id'))
      : null;
    const filterExamDate = urlObj.searchParams.get('exam_date') || null;

    if (filterExamDate && !/^\d{4}-\d{2}-\d{2}$/.test(filterExamDate)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'فرمت تاریخ آزمون نامعتبر است' },
        },
      };
    }

    const nowMs = Date.now();
    const links = ctx.examResultLinks
      .filter((l) => {
        if (filterStudentId && l.student_id !== filterStudentId) return false;
        if (filterExamDate && l.exam_date !== filterExamDate) return false;
        return true;
      })
      .sort((a, b) => b.id - a.id)
      .map((l) => {
        const student = ctx.students.find((s) => s.id === l.student_id);
        const expMs = Date.parse(l.expires_at.replace(' ', 'T') + 'Z');
        let status = 'active';
        if (l.revoked_at !== null) {
          status = 'revoked';
        } else if (!Number.isNaN(expMs) && expMs <= nowMs) {
          status = 'expired';
        }
        return {
          id: l.id,
          student_id: l.student_id,
          student_name: student?.name || '',
          exam_date: l.exam_date,
          status,
          expires_at: l.expires_at,
          revoked_at: l.revoked_at,
          sms_sent_at: l.sms_sent_at,
          last_viewed_at: l.last_viewed_at,
          created_by: l.created_by,
          created_at: l.created_at,
        };
      });

    return {
      status: 200,
      body: {
        success: true,
        data: { links },
        pagination: null,
        error: null,
      },
    };
  }

  const revokeMatch = pathname.match(/^\/api\/v1\/exams\/share-links\/(\d+)(?:\/revoke)?$/);
  if (revokeMatch && (method === 'DELETE' || (method === 'POST' && pathname.endsWith('/revoke')))) {
    if (!['admin', 'supporter'].includes(user.role)) {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'دسترسی محدود به پشتیبانان و مدیران' },
        },
      };
    }

    const linkId = Number(revokeMatch[1]);
    const link = ctx.examResultLinks.find((l) => l.id === linkId);
    if (!link) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'لینک نتیجه آزمون یافت نشد' },
        },
      };
    }

    if (!link.revoked_at) {
      link.revoked_at = new Date().toISOString().replace('T', ' ').slice(0, 19);
    }

    const student = ctx.students.find((s) => s.id === link.student_id);
    return {
      status: 200,
      body: {
        success: true,
        data: {
          link: {
            id: link.id,
            student_id: link.student_id,
            student_name: student?.name || '',
            exam_date: link.exam_date,
            status: 'revoked',
            expires_at: link.expires_at,
            revoked_at: link.revoked_at,
            sms_sent_at: link.sms_sent_at,
            last_viewed_at: link.last_viewed_at,
            created_by: link.created_by,
            created_at: link.created_at,
          },
        },
        pagination: null,
        error: null,
      },
    };
  }

  if (
    method === 'POST' &&
    (pathname === '/api/v1/exams/share-links' || pathname === '/api/v1/exams/share-links/send-sms')
  ) {
    if (!['admin', 'supporter'].includes(user.role)) {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'دسترسی محدود به پشتیبانان و مدیران' },
        },
      };
    }

    const studentId = Number(body?.student_id);
    const examDate = typeof body?.exam_date === 'string' ? body.exam_date.trim() : '';

    if (!Number.isInteger(studentId) || studentId <= 0 || !/^\d{4}-\d{2}-\d{2}$/.test(examDate)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'داده‌های ناقص یا نامعتبر است' },
        },
      };
    }

    const student = ctx.students.find((s) => s.id === studentId);
    if (!student) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'دانش‌آموز یافت نشد' },
        },
      };
    }

    const hasExam = ctx.examResults.some((r) => r.student_id === studentId && r.exam_date === examDate);
    if (!hasExam) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'نتیجه آزمونی برای این دانش‌آموز در این تاریخ ثبت نشده است' },
        },
      };
    }

    const sendSms = pathname === '/api/v1/exams/share-links/send-sms' || Boolean(body?.send_sms);
    let recipientMobile = null;

    if (sendSms) {
      if (typeof body?.phone === 'string' && body.phone.trim() !== '') {
        recipientMobile = normalizeIranianPhone(body.phone);
        if (!recipientMobile) {
          return {
            status: 422,
            body: {
              success: false,
              data: null,
              pagination: null,
              error: { code: 'VALIDATION_ERROR', message: 'شماره تماس والد معتبر نیست' },
            },
          };
        }
      } else if (body?.parent_contact_id) {
        const contact = (ctx.parentContacts || []).find(
          (c) => c.student_id === studentId && c.id === Number(body.parent_contact_id)
        );
        if (!contact) {
          return {
            status: 404,
            body: {
              success: false,
              data: null,
              pagination: null,
              error: { code: 'NOT_FOUND', message: 'مخاطب والد یافت نشد' },
            },
          };
        }
        recipientMobile = normalizeIranianPhone(contact.phone);
      } else {
        const contacts = (ctx.parentContacts || [])
          .filter((c) => c.student_id === studentId)
          .sort((a, b) => b.is_primary - a.is_primary || a.id - b.id);
        if (contacts.length === 0) {
          return {
            status: 422,
            body: {
              success: false,
              data: null,
              pagination: null,
              error: { code: 'VALIDATION_ERROR', message: 'شماره تماس والد برای این دانش‌آموز ثبت نشده است' },
            },
          };
        }
        recipientMobile = normalizeIranianPhone(contacts[0].phone);
      }

      const clientIp = body?.ip || '127.0.0.1';
      const cooldownKey = ctx.rateLimiter.key('exam_sms:cooldown', `${studentId}:${examDate}:${recipientMobile}`);
      const phoneKey = ctx.rateLimiter.key('exam_sms:phone', recipientMobile);
      const ipKey = ctx.rateLimiter.key('exam_sms:ip', clientIp);

      const phoneMax = ctx.smsPhoneHourlyMax || 5;
      const ipMax = ctx.smsIpHourlyMax || 20;

      const cooldownStatus = ctx.rateLimiter.status(cooldownKey, 1);
      if (!cooldownStatus.allowed) {
        return {
          status: 429,
          headers: { 'Retry-After': String(Math.max(1, cooldownStatus.retry_after || 120)) },
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'RATE_LIMITED', message: 'تعداد درخواست‌ها بیش از حد مجاز است' },
            retry_after: Math.max(1, cooldownStatus.retry_after || 120),
          },
        };
      }

      const phoneStatus = ctx.rateLimiter.status(phoneKey, phoneMax);
      if (!phoneStatus.allowed) {
        return {
          status: 429,
          headers: { 'Retry-After': String(Math.max(1, phoneStatus.retry_after || 3600)) },
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'RATE_LIMITED', message: 'تعداد درخواست‌ها بیش از حد مجاز است' },
            retry_after: Math.max(1, phoneStatus.retry_after || 3600),
          },
        };
      }

      const ipStatus = ctx.rateLimiter.status(ipKey, ipMax);
      if (!ipStatus.allowed) {
        return {
          status: 429,
          headers: { 'Retry-After': String(Math.max(1, ipStatus.retry_after || 3600)) },
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'RATE_LIMITED', message: 'تعداد درخواست‌ها بیش از حد مجاز است' },
            retry_after: Math.max(1, ipStatus.retry_after || 3600),
          },
        };
      }

      ctx.rateLimiter.attempt(cooldownKey, 1, 120, 120);
      ctx.rateLimiter.attempt(phoneKey, phoneMax, 3600, 3600);
      ctx.rateLimiter.attempt(ipKey, ipMax, 3600, 3600);
    }

    const rawToken = crypto
      .randomBytes(32)
      .toString('base64')
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/g, '');
    const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');

    const nowMs = Date.now();
    const createdAt = new Date(nowMs).toISOString().replace('T', ' ').slice(0, 19);
    const expiresAt = new Date(nowMs + 14 * 86400 * 1000).toISOString().replace('T', ' ').slice(0, 19);

    const record = {
      id: ctx.examResultLinks.length + 1,
      token_hash: tokenHash,
      student_id: studentId,
      exam_date: examDate,
      expires_at: expiresAt,
      revoked_at: null,
      sms_sent_at: null,
      created_by: user.id,
      last_viewed_at: null,
      created_at: createdAt,
    };
    ctx.examResultLinks.push(record);

    const sharePath = `/api/v1/public/exam-results/${rawToken}`;
    const shareUrl = `http://localhost:3000${sharePath}`;

    let smsSent = false;
    let smsSentAt = null;
    let recipientPhoneMask = null;

    if (sendSms && recipientMobile) {
      if (ctx.smsGatewayAvailable === false) {
        return {
          status: 502,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: {
              code: 'SMS_SEND_FAILED',
              message: 'ارسال پیامک نتیجه آزمون با مشکل مواجه شد. لطفاً دقایقی بعد تلاش کنید.',
            },
          },
        };
      }

      smsSent = true;
      smsSentAt = createdAt;
      record.sms_sent_at = smsSentAt;
      recipientPhoneMask = maskPhone(recipientMobile);

      const safeName = student.name.replace(/[;\r\n]/g, ' ').trim();
      ctx.smsDispatches.push({
        provider: 'melipayamak',
        endpoint: 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
        to: recipientMobile,
        text: `${safeName};${examDate};${shareUrl}`,
        sent_at: smsSentAt,
      });
    }

    return {
      status: 201,
      body: {
        success: true,
        data: {
          id: record.id,
          student_id: studentId,
          exam_date: examDate,
          token: rawToken,
          url: shareUrl,
          share_path: sharePath,
          share_url: shareUrl,
          expires_at: expiresAt,
          created_at: createdAt,
          sms_sent: smsSent,
          sms_sent_at: smsSentAt,
          recipient_phone_mask: recipientPhoneMask,
        },
        pagination: null,
        error: null,
      },
    };
  }

  return {
    status: 404,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' },
    },
  };
}

export function handlePublicExamResultEndpoint(ctx, token, clientIp = '127.0.0.1') {
  const { rateLimiter } = ctx;
  const maxAttempts = 20;
  const windowSeconds = 60;
  const rlKey = rateLimiter.key('public:exam_result:ip', clientIp);
  const rlStatus = rateLimiter.attempt(rlKey, maxAttempts, windowSeconds, 60);
  const remaining = Math.max(0, maxAttempts - rlStatus.attempts);

  const securityHeaders = {
    'Content-Type': 'application/json',
    'Cache-Control': 'no-store, no-cache, must-revalidate',
    'X-Robots-Tag': 'noindex, nofollow',
    'X-RateLimit-Limit': String(maxAttempts),
    'X-RateLimit-Remaining': String(remaining),
    Vary: 'Origin',
  };

  if (!rlStatus.allowed) {
    return {
      status: 429,
      headers: {
        ...securityHeaders,
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

  const uniformNotFound = {
    status: 404,
    headers: securityHeaders,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: {
        code: 'NOT_FOUND',
        message: 'لینک نتیجه آزمون معتبر نیست یا منقضی شده است',
      },
    },
  };

  const rawToken = typeof token === 'string' ? token.trim() : '';
  if (!/^[A-Za-z0-9_-]{40,64}$/.test(rawToken)) {
    return uniformNotFound;
  }

  const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
  const link = ctx.examResultLinks.find((l) => l.token_hash === tokenHash);
  if (!link || link.revoked_at !== null) {
    return uniformNotFound;
  }

  const expiresMs = Date.parse(link.expires_at.replace(' ', 'T') + 'Z');
  if (Number.isNaN(expiresMs) || expiresMs <= Date.now()) {
    return uniformNotFound;
  }

  const student = ctx.students.find((s) => s.id === link.student_id);
  const subjects = ctx.examResults
    .filter((r) => r.student_id === link.student_id && r.exam_date === link.exam_date)
    .map((r) => ({
      subject: r.subject,
      chapter: r.chapter,
      total_q: r.total_q,
      correct: r.correct,
      wrong: r.wrong,
      skipped: r.skipped,
      percentage: r.percentage,
    }));

  if (!student || subjects.length === 0) {
    return uniformNotFound;
  }

  link.last_viewed_at = new Date().toISOString().replace('T', ' ').slice(0, 19);

  const avg = Number(
    (subjects.reduce((acc, s) => acc + s.percentage, 0) / subjects.length).toFixed(1)
  );

  const comments = ctx.examComments
    .filter((c) => c.student_id === link.student_id && c.exam_date === link.exam_date)
    .sort((a, b) => b.id - a.id)
    .map((c) => {
      const author = ctx.users.find((u) => u.id === c.teacher_user_id);
      let authorName = author?.full_name || author?.username || '';
      if (author?.role === 'teacher' && author?.linked_id > 0) {
        const inst = ctx.instructors.find((i) => i.id === author.linked_id);
        if (inst?.name) authorName = inst.name;
      }
      return {
        id: c.id,
        student_id: c.student_id,
        exam_date: c.exam_date,
        teacher_user_id: c.teacher_user_id,
        author_name: authorName,
        author_role: author?.role || 'teacher',
        body: c.body,
        created_at: c.created_at,
        updated_at: c.updated_at,
      };
    });

  return {
    status: 200,
    headers: securityHeaders,
    body: {
      success: true,
      data: {
        student_name: student.name,
        student: {
          name: student.name,
          grade: student.grade,
          field: student.field,
        },
        exam_date: link.exam_date,
        subjects,
        avg_percentage: avg,
        comments,
      },
      pagination: null,
      error: null,
    },
  };
}

function getIranWeekStart(dateYmd) {
  const [y, m, d] = dateYmd.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  const dow = dt.getUTCDay(); // 0=Sun..6=Sat
  const offset = (dow + 1) % 7; // Sat=0, Sun=1, ..., Fri=6
  dt.setUTCDate(dt.getUTCDate() - offset);
  return dt.toISOString().slice(0, 10);
}

function addDaysYmd(dateYmd, days) {
  const [y, m, d] = dateYmd.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  dt.setUTCDate(dt.getUTCDate() + days);
  return dt.toISOString().slice(0, 10);
}

function gregorianToJalaliStr(dateYmd) {
  const [gy, gm, gd] = dateYmd.split('-').map(Number);
  const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
  const gy2 = gm > 2 ? gy + 1 : gy;
  let days =
    355666 +
    365 * gy +
    Math.floor((gy2 + 3) / 4) -
    Math.floor((gy2 + 99) / 100) +
    Math.floor((gy2 + 399) / 400) +
    gd +
    g_d_m[gm - 1];
  let jy = -1595 + 33 * Math.floor(days / 12053);
  days %= 12053;
  jy += 4 * Math.floor(days / 1461);
  days %= 1461;
  if (days > 365) {
    jy += Math.floor((days - 1) / 365);
    days = (days - 1) % 365;
  }
  const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
  const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
  return `${String(jy).padStart(4, '0')}/${String(jm).padStart(2, '0')}/${String(jd).padStart(2, '0')}`;
}

function formatPersianDayAndDate(dateYmd) {
  const [y, m, d] = dateYmd.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  const names = {
    6: 'شنبه',
    0: 'یکشنبه',
    1: 'دوشنبه',
    2: 'سه‌شنبه',
    3: 'چهارشنبه',
    4: 'پنجشنبه',
    5: 'جمعه',
  };
  const dayName = names[dt.getUTCDay()] || '';
  const jalali = gregorianToJalaliStr(dateYmd);
  return `${dayName} ${jalali}`.trim();
}

function parsePositivePatternBodyId(raw) {
  if (raw === null || raw === undefined || raw === '' || typeof raw === 'boolean') {
    return { empty: true, value: null };
  }
  const str = String(raw).trim();
  if (!/^\d+$/.test(str)) {
    return { empty: false, invalid: true, value: null };
  }
  const num = Number(str);
  if (!Number.isSafeInteger(num) || num <= 0) {
    return { empty: false, invalid: true, value: null };
  }
  return { empty: false, invalid: false, value: num };
}

function resolveSmsPattern(ctx, key, overrideBodyId = null) {
  const row = ctx.smsPatterns?.[key];
  if (!row) return null;

  const dbId = parsePositivePatternBodyId(row.db_body_id).value;
  const envId = parsePositivePatternBodyId(row.env_body_id).value;
  const reqId = parsePositivePatternBodyId(overrideBodyId).value;

  let body_id = null;
  let source = 'unset';
  if (reqId !== null) {
    body_id = reqId;
    source = 'request';
  } else if (dbId !== null) {
    body_id = dbId;
    source = 'database';
  } else if (envId !== null) {
    body_id = envId;
    source = 'env';
  }

  return {
    pattern_key: row.pattern_key,
    label: row.label,
    env_key: row.env_key,
    alias_field: row.alias_field,
    body_id,
    db_body_id: dbId,
    env_body_id: envId,
    source,
    is_configured: body_id !== null && body_id > 0,
    pattern_text: row.pattern_text,
    placeholders: row.placeholders,
    updated_by: row.updated_by ?? null,
    updated_at: row.updated_at ?? null,
  };
}

function getAllSmsPatterns(ctx) {
  const keys = ['auth_2fa', 'appointment_reminder', 'exam_result'];
  const patterns = {};
  const items = [];
  for (const key of keys) {
    const resolved = resolveSmsPattern(ctx, key);
    patterns[key] = resolved;
    items.push(resolved);
  }
  return {
    patterns,
    items,
    auth_body_id: patterns.auth_2fa.body_id,
    appointment_body_id: patterns.appointment_reminder.body_id,
    exam_body_id: patterns.exam_result.body_id,
  };
}

function renderAppointmentSmsMessage(studentName, dayAndDateFa, timeFa, template) {
  const tpl =
    template && String(template).trim() !== ''
      ? String(template)
      : 'موسسه آموزشی فامو | دکتر موسوی والدین گرامی {0} ، نوبت فرزند شما روز {1} ساعت {2} میباشد. در صورت تایید به شماره موسسه پیامک دهید.';
  return tpl
    .replaceAll('{0}', String(studentName || '').trim())
    .replaceAll('{1}', String(dayAndDateFa || '').trim())
    .replaceAll('{2}', String(timeFa || '').trim());
}

export function handleSmsPatternEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  if (!authUser || !authUser.sub) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'AUTH_ERROR', message: 'توکن احراز هویت ارسال نشده است' },
      },
    };
  }

  const user = ctx.users.find((u) => u.id === Number(authUser.sub));
  if (!user) {
    return {
      status: 404,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'NOT_FOUND', message: 'کاربر یافت نشد' },
      },
    };
  }

  if (user.role !== 'admin') {
    return {
      status: 403,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'FORBIDDEN', message: 'دسترسی محدود به مدیران سیستم' },
      },
    };
  }

  if (method === 'GET') {
    return {
      status: 200,
      body: {
        success: true,
        data: getAllSmsPatterns(ctx),
        pagination: null,
        error: null,
      },
    };
  }

  if (method !== 'PUT' && method !== 'PATCH') {
    return {
      status: 405,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'METHOD_NOT_ALLOWED', message: 'متد HTTP پشتیبانی نمی‌شود' },
      },
    };
  }

  const aliasToKey = {
    auth_body_id: 'auth_2fa',
    login_body_id: 'auth_2fa',
    pattern_body_id: 'auth_2fa',
    MELIPAYAMAK_PATTERN_BODY_ID: 'auth_2fa',
    appointment_body_id: 'appointment_reminder',
    MELIPAYAMAK_APPOINTMENT_BODY_ID: 'appointment_reminder',
    exam_body_id: 'exam_result',
    MELIPAYAMAK_EXAM_BODY_ID: 'exam_result',
  };
  const validKeys = ['auth_2fa', 'appointment_reminder', 'exam_result'];
  const updates = {};

  const failValidation = (message) => ({
    status: 422,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'VALIDATION_ERROR', message },
    },
  });

  if (typeof body?.pattern_key === 'string') {
    const key = body.pattern_key.trim();
    if (!validKeys.includes(key)) {
      return failValidation(`کلید پترن «${key}» معتبر نیست`);
    }
    const entry = {};
    if (Object.prototype.hasOwnProperty.call(body, 'body_id')) {
      const parsed = parsePositivePatternBodyId(body.body_id);
      if (parsed.invalid) {
        return failValidation(`کد پترن (${key}) باید یک عدد صحیح مثبت باشد`);
      }
      entry.body_id = parsed.value;
    }
    if (Object.prototype.hasOwnProperty.call(body, 'pattern_text')) {
      entry.pattern_text = typeof body.pattern_text === 'string' ? body.pattern_text : null;
    }
    if (Object.keys(entry).length > 0) {
      updates[key] = entry;
    }
  }

  if (body?.patterns && typeof body.patterns === 'object' && !Array.isArray(body.patterns)) {
    for (const [rawKey, cfg] of Object.entries(body.patterns)) {
      const key = rawKey.trim();
      if (!validKeys.includes(key)) {
        return failValidation(`کلید پترن «${key}» معتبر نیست`);
      }
      if (!cfg || typeof cfg !== 'object' || Array.isArray(cfg)) {
        const parsed = parsePositivePatternBodyId(cfg);
        if (parsed.invalid) {
          return failValidation(`کد پترن (${key}) باید یک عدد صحیح مثبت باشد`);
        }
        updates[key] = { body_id: parsed.value };
        continue;
      }
      const entry = {};
      if (Object.prototype.hasOwnProperty.call(cfg, 'body_id')) {
        const parsed = parsePositivePatternBodyId(cfg.body_id);
        if (parsed.invalid) {
          return failValidation(`کد پترن (${key}) باید یک عدد صحیح مثبت باشد`);
        }
        entry.body_id = parsed.value;
      }
      if (Object.prototype.hasOwnProperty.call(cfg, 'pattern_text')) {
        entry.pattern_text = typeof cfg.pattern_text === 'string' ? cfg.pattern_text : null;
      }
      if (Object.keys(entry).length > 0) {
        updates[key] = entry;
      }
    }
  }

  for (const key of validKeys) {
    if (body?.[key] && typeof body[key] === 'object' && !Array.isArray(body[key])) {
      const cfg = body[key];
      const entry = updates[key] || {};
      if (Object.prototype.hasOwnProperty.call(cfg, 'body_id')) {
        const parsed = parsePositivePatternBodyId(cfg.body_id);
        if (parsed.invalid) {
          return failValidation(`کد پترن (${key}) باید یک عدد صحیح مثبت باشد`);
        }
        entry.body_id = parsed.value;
      }
      if (Object.prototype.hasOwnProperty.call(cfg, 'pattern_text')) {
        entry.pattern_text = typeof cfg.pattern_text === 'string' ? cfg.pattern_text : null;
      }
      if (Object.keys(entry).length > 0) {
        updates[key] = entry;
      }
    }
  }

  for (const [alias, key] of Object.entries(aliasToKey)) {
    if (Object.prototype.hasOwnProperty.call(body || {}, alias)) {
      const parsed = parsePositivePatternBodyId(body[alias]);
      if (parsed.invalid) {
        return failValidation(`کد پترن (${alias}) باید یک عدد صحیح مثبت باشد`);
      }
      updates[key] = { ...(updates[key] || {}), body_id: parsed.value };
    }
  }

  if (Object.keys(updates).length === 0) {
    return failValidation('حداقل یکی از کدهای پترن (مانند appointment_body_id یا patterns) باید ارسال شود');
  }

  const now = new Date().toISOString().replace('T', ' ').slice(0, 19);
  for (const [key, entry] of Object.entries(updates)) {
    const target = ctx.smsPatterns[key];
    if (Object.prototype.hasOwnProperty.call(entry, 'body_id')) {
      target.db_body_id = entry.body_id;
    }
    if (Object.prototype.hasOwnProperty.call(entry, 'pattern_text')) {
      if (entry.pattern_text && entry.pattern_text.trim() !== '') {
        target.pattern_text = entry.pattern_text.trim();
      }
    }
    target.updated_by = user.id;
    target.updated_at = now;
  }

  return {
    status: 200,
    body: {
      success: true,
      data: getAllSmsPatterns(ctx),
      pagination: null,
      error: null,
    },
  };
}

export function handleAppointmentSmsEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  if (!authUser || !authUser.sub) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'AUTH_ERROR', message: 'توکن احراز هویت ارسال نشده است' },
      },
    };
  }

  const user = ctx.users.find((u) => u.id === Number(authUser.sub));
  if (!user) {
    return {
      status: 404,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'NOT_FOUND', message: 'کاربر یافت نشد' },
      },
    };
  }

  // Strictly admin-only (no secretary role added; supporter/student/teacher forbidden)
  if (user.role !== 'admin') {
    return {
      status: 403,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'FORBIDDEN', message: 'دسترسی محدود به مدیران سیستم' },
      },
    };
  }

  const pathname = urlObj.pathname;
  const activeStatuses = ['pending', 'confirmed', 'scheduled'];

  const normalizeAppointmentAt = (dateStr, startTime) => {
    const t = String(startTime || '').trim();
    const normTime = /^\d{2}:\d{2}$/.test(t) ? `${t}:00` : t;
    return `${dateStr} ${normTime}`;
  };

  const formatTimeHhMm = (startTime) => {
    const t = String(startTime || '').trim();
    return t.length >= 5 ? t.slice(0, 5) : t;
  };

  const findPrimaryParent = (studentId) => {
    const contacts = (ctx.parentContacts || [])
      .filter((c) => c.student_id === studentId)
      .sort((a, b) => b.is_primary - a.is_primary || a.id - b.id);
    if (contacts.length === 0) return null;
    const primary = contacts[0];
    return {
      parent_name: primary.parent_name || '',
      mobile: normalizeIranianPhone(primary.phone),
    };
  };

  const findLatestLogForStudentWeek = (studentId, weekStart, mobile) => {
    const logs = (ctx.appointmentSmsLogs || []).filter(
      (l) => l.student_id === studentId && l.week_date === weekStart
    );
    if (logs.length === 0) return null;
    if (mobile) {
      const exact = logs.find((l) => l.mobile === mobile);
      if (exact) return exact;
    }
    return logs.sort((a, b) => b.id - a.id)[0];
  };

  const upsertLog = (data) => {
    const now = new Date().toISOString().replace('T', ' ').slice(0, 19);
    let existing = ctx.appointmentSmsLogs.find(
      (l) =>
        l.student_id === data.student_id &&
        l.week_date === data.week_date &&
        l.mobile === data.mobile
    );
    if (existing) {
      existing.recipient_type = 'parent';
      existing.appointment_at = data.appointment_at;
      existing.status = data.status;
      existing.error = data.error ?? null;
      if (data.increment_attempts) {
        existing.attempts = (existing.attempts || 0) + 1;
      }
      if (data.sent_at) {
        existing.sent_at = data.sent_at;
      }
      existing.created_by = data.created_by ?? existing.created_by;
      existing.updated_at = now;
      return existing;
    }
    const created = {
      id: ctx.appointmentSmsLogs.length + 1,
      student_id: data.student_id,
      week_date: data.week_date,
      mobile: data.mobile,
      recipient_type: 'parent',
      appointment_at: data.appointment_at,
      status: data.status,
      error: data.error ?? null,
      attempts: data.increment_attempts ? 1 : 0,
      sent_at: data.sent_at ?? null,
      created_by: data.created_by ?? null,
      created_at: now,
      updated_at: now,
    };
    ctx.appointmentSmsLogs.push(created);
    return created;
  };

  const evaluateStudentWeek = (student, weekStart, weekEnd) => {
    const sid = student.id;
    const studentAppts = (ctx.appointments || [])
      .filter(
        (a) =>
          a.student_id === sid &&
          a.appointment_date >= weekStart &&
          a.appointment_date <= weekEnd &&
          activeStatuses.includes(String(a.status || '').toLowerCase())
      )
      .sort(
        (a, b) =>
          a.appointment_date.localeCompare(b.appointment_date) ||
          a.start_time.localeCompare(b.start_time) ||
          a.id - b.id
      );

    const multipleAppointments = studentAppts.length > 1;
    const selectedAppt = studentAppts[0] || null;
    const parentInfo = findPrimaryParent(sid);
    const parentMobile = parentInfo?.mobile || null;
    const parentName = parentInfo?.parent_name || null;

    if (!selectedAppt) {
      return {
        student_id: sid,
        student_name: student.name,
        grade: student.grade,
        field: student.field,
        recipient_type: 'parent',
        parent_name: parentName,
        mobile: parentMobile,
        has_valid_phone: parentMobile !== null,
        appointment_id: null,
        appointment_date: null,
        start_time: null,
        end_time: null,
        appointment_status: null,
        appointment_at: null,
        day_and_date_fa: null,
        time_fa: null,
        sms_preview_text: null,
        multiple_appointments: false,
        active_appointments_count: 0,
        status: 'no_appointment',
        last_sent_appointment_at: null,
        last_sent_at: null,
        attempts: 0,
        last_error: null,
      };
    }

    const appointmentAt = normalizeAppointmentAt(
      selectedAppt.appointment_date,
      selectedAppt.start_time
    );
    const dayAndDateFa = formatPersianDayAndDate(selectedAppt.appointment_date);
    const timeFa = formatTimeHhMm(selectedAppt.start_time);
    const patternMeta = resolveSmsPattern(ctx, 'appointment_reminder');
    const smsPreviewText = renderAppointmentSmsMessage(
      student.name,
      dayAndDateFa,
      timeFa,
      patternMeta?.pattern_text
    );
    const existingLog = findLatestLogForStudentWeek(sid, weekStart, parentMobile);

    let status = 'will_send';
    if (!parentMobile) {
      status = 'no_phone';
    } else if (existingLog) {
      if (existingLog.status === 'sent') {
        status =
          existingLog.appointment_at === appointmentAt ? 'already_sent' : 'changed_since_sent';
      } else if (existingLog.status === 'failed') {
        status = 'failed';
      }
    }

    return {
      student_id: sid,
      student_name: student.name,
      grade: student.grade,
      field: student.field,
      recipient_type: 'parent',
      parent_name: parentName,
      mobile: parentMobile,
      has_valid_phone: parentMobile !== null,
      appointment_id: selectedAppt.id,
      appointment_date: selectedAppt.appointment_date,
      start_time: selectedAppt.start_time,
      end_time: selectedAppt.end_time,
      appointment_status: selectedAppt.status,
      appointment_at: appointmentAt,
      day_and_date_fa: dayAndDateFa,
      time_fa: timeFa,
      sms_preview_text: smsPreviewText,
      multiple_appointments: multipleAppointments,
      active_appointments_count: studentAppts.length,
      status,
      last_sent_appointment_at: existingLog?.appointment_at ?? null,
      last_sent_at: existingLog?.sent_at ?? null,
      attempts: existingLog?.attempts ?? 0,
      last_error: existingLog?.error ?? null,
    };
  };

  if (method === 'GET' && pathname === '/api/v1/appointments/sms/preview') {
    const rawWeekDate = urlObj.searchParams.get('week_date') || '2026-10-24';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(rawWeekDate)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'فرمت تاریخ هفته نامعتبر است (YYYY-MM-DD)' },
        },
      };
    }

    const weekStart = getIranWeekStart(rawWeekDate);
    const weekEnd = addDaysYmd(weekStart, 6);
    const activeStudents = ctx.students.filter((s) => s.is_active === 1);
    const patternMeta = resolveSmsPattern(ctx, 'appointment_reminder');

    const summary = {
      total_students: activeStudents.length,
      will_send: 0,
      already_sent: 0,
      changed_since_sent: 0,
      failed: 0,
      no_phone: 0,
      no_appointment: 0,
      multiple_appointments: 0,
    };

    const items = activeStudents.map((st) => {
      const item = evaluateStudentWeek(st, weekStart, weekEnd);
      if (summary[item.status] !== undefined) {
        summary[item.status]++;
      }
      if (item.multiple_appointments) {
        summary.multiple_appointments++;
      }
      return item;
    });

    return {
      status: 200,
      body: {
        success: true,
        data: {
          week_date: weekStart,
          week_end: weekEnd,
          pattern: {
            key: patternMeta.pattern_key,
            body_id: patternMeta.body_id,
            source: patternMeta.source,
            is_configured: patternMeta.is_configured,
            template: patternMeta.pattern_text,
          },
          recipient_type: 'parent',
          summary,
          items,
        },
        pagination: null,
        error: null,
      },
    };
  }

  if (method === 'POST' && pathname === '/api/v1/appointments/sms/send') {
    const rawWeekDate = typeof body?.week_date === 'string' ? body.week_date.trim() : '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(rawWeekDate)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'فیلد week_date با فرمت YYYY-MM-DD الزامی است' },
        },
      };
    }

    const mode = typeof body?.mode === 'string' ? body.mode.trim().toLowerCase() : 'unsent';
    if (!['unsent', 'failed', 'changed'].includes(mode)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'فیلد mode باید یکی از مقادیر unsent، failed یا changed باشد' },
        },
      };
    }

    let studentIdsFilter = null;
    if (body?.student_ids !== undefined && body?.student_ids !== null) {
      if (!Array.isArray(body.student_ids)) {
        return {
          status: 422,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'VALIDATION_ERROR', message: 'فیلد student_ids باید آرایه‌ای از شناسه‌های دانش‌آموز باشد' },
          },
        };
      }
      studentIdsFilter = [...new Set(body.student_ids.map(Number).filter((n) => n > 0))];
    }

    const rawBodyId = body?.body_id ?? body?.pattern_body_id ?? body?.pattern_id ?? null;
    const parsedOverride = parsePositivePatternBodyId(rawBodyId);
    if (parsedOverride.invalid) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'کد پترن ملی‌پیامک (body_id) باید یک عدد صحیح مثبت باشد' },
        },
      };
    }

    const patternMeta = resolveSmsPattern(ctx, 'appointment_reminder', parsedOverride.value);
    if (!patternMeta || !patternMeta.is_configured) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: {
            code: 'APPOINTMENT_PATTERN_NOT_CONFIGURED',
            message:
              'کد پترن پیامک نوبت تنظیم نشده است. لطفاً کد پترن یادآوری نوبت را در تنظیمات پیامک پنل ادمین یا متغیر MELIPAYAMAK_APPOINTMENT_BODY_ID ثبت کنید.',
          },
        },
      };
    }

    if (body?.save_pattern && parsedOverride.value !== null) {
      ctx.smsPatterns.appointment_reminder.db_body_id = parsedOverride.value;
      ctx.smsPatterns.appointment_reminder.updated_by = user.id;
      ctx.smsPatterns.appointment_reminder.updated_at = new Date()
        .toISOString()
        .replace('T', ' ')
        .slice(0, 19);
    }

    const weekStart = getIranWeekStart(rawWeekDate);
    const weekEnd = addDaysYmd(weekStart, 6);
    const operationId = typeof body?.operation_id === 'string' ? body.operation_id.trim() : '';

    const lockKey = `lock:appointment_sms:${weekStart}`;
    if (ctx.redis.get(lockKey) !== null) {
      return {
        status: 409,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: {
            code: 'CONCURRENT_SEND_LOCKED',
            message: 'عملیات ارسال پیامک نوبت برای این هفته در حال انجام است. لطفاً چند لحظه صبر کنید.',
          },
        },
      };
    }

    if (operationId) {
      const opKey = `lock:appointment_sms:op:${crypto.createHash('sha256').update(operationId).digest('hex')}`;
      if (ctx.redis.get(opKey) !== null) {
        return {
          status: 409,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: {
              code: 'CONCURRENT_SEND_LOCKED',
              message: 'این درخواست ارسال قبلاً ثبت شده و در حال پردازش است.',
            },
          },
        };
      }
      ctx.redis.setex(opKey, 60, '1');
    }

    ctx.redis.setex(lockKey, 120, String(user.id));

    try {
      const targetStudents = ctx.students.filter(
        (s) =>
          s.is_active === 1 &&
          (studentIdsFilter === null || studentIdsFilter.includes(s.id))
      );

      const summary = {
        sent: 0,
        failed: 0,
        skipped: 0,
        skipped_no_phone: 0,
      };
      const details = [];

      const userMax = ctx.appointmentSmsUserHourlyMax || 200;
      const phoneMax = ctx.appointmentSmsPhoneHourlyMax || 3;
      const userRlKey = ctx.rateLimiter.key('appointment_sms:user', String(user.id));

      for (const st of targetStudents) {
        const evalItem = evaluateStudentWeek(st, weekStart, weekEnd);
        const sid = evalItem.student_id;

        if (evalItem.status === 'no_appointment') {
          if (studentIdsFilter !== null) {
            summary.skipped++;
            details.push({
              student_id: sid,
              student_name: evalItem.student_name,
              mobile: evalItem.mobile,
              appointment_at: null,
              multiple_appointments: false,
              action: 'skipped_no_appointment',
              status: 'no_appointment',
              error: null,
            });
          }
          continue;
        }

        const appointmentAt = evalItem.appointment_at;
        const parentMobile = evalItem.mobile;
        const existingLog = findLatestLogForStudentWeek(sid, weekStart, parentMobile);

        // No primary parent phone -> skipped_no_phone (never fallback to student phone)
        if (!parentMobile) {
          if (mode === 'unsent' || studentIdsFilter !== null) {
            upsertLog({
              student_id: sid,
              week_date: weekStart,
              mobile: '',
              appointment_at: appointmentAt,
              status: 'skipped_no_phone',
              error: 'شماره تماس والد اصلی ثبت نشده یا نامعتبر است',
              increment_attempts: false,
              sent_at: null,
              created_by: user.id,
            });
            summary.skipped_no_phone++;
            summary.skipped++;
            details.push({
              student_id: sid,
              student_name: evalItem.student_name,
              mobile: null,
              appointment_at: appointmentAt,
              multiple_appointments: evalItem.multiple_appointments,
              action: 'skipped_no_phone',
              status: 'skipped_no_phone',
              error: 'شماره تماس والد اصلی ثبت نشده یا نامعتبر است',
            });
          }
          continue;
        }

        // Deduplication: if already sent and appointment_at unchanged -> skip
        if (
          existingLog &&
          existingLog.status === 'sent' &&
          existingLog.appointment_at === appointmentAt
        ) {
          summary.skipped++;
          details.push({
            student_id: sid,
            student_name: evalItem.student_name,
            mobile: parentMobile,
            appointment_at: appointmentAt,
            multiple_appointments: evalItem.multiple_appointments,
            action: 'skipped_already_sent',
            status: 'sent',
            error: null,
          });
          continue;
        }

        let eligible = false;
        let skipReason = 'skipped_mode_mismatch';

        if (mode === 'unsent') {
          if (!existingLog || existingLog.status === 'skipped_no_phone') {
            eligible = true;
          } else if (
            existingLog.status === 'sent' &&
            existingLog.appointment_at !== appointmentAt
          ) {
            skipReason = 'skipped_changed_requires_changed_mode';
          } else if (existingLog.status === 'failed') {
            skipReason = 'skipped_failed_requires_failed_mode';
          }
        } else if (mode === 'failed') {
          if (existingLog && existingLog.status === 'failed') {
            eligible = true;
          }
        } else if (mode === 'changed') {
          if (
            existingLog &&
            existingLog.status === 'sent' &&
            existingLog.appointment_at !== appointmentAt
          ) {
            eligible = true;
          }
        }

        if (!eligible) {
          summary.skipped++;
          details.push({
            student_id: sid,
            student_name: evalItem.student_name,
            mobile: parentMobile,
            appointment_at: appointmentAt,
            multiple_appointments: evalItem.multiple_appointments,
            action: skipReason,
            status: evalItem.status,
            error: null,
          });
          continue;
        }

        // Send wrapped in per-student try/catch so one error never aborts others
        try {
          const userRlStatus = ctx.rateLimiter.status(userRlKey, userMax);
          if (!userRlStatus.allowed) {
            throw new Error('سقف ارسال ساعتی کاربر تکمیل شده است');
          }

          const phoneRlKey = ctx.rateLimiter.key('appointment_sms:phone', parentMobile);
          const phoneRlStatus = ctx.rateLimiter.status(phoneRlKey, phoneMax);
          if (!phoneRlStatus.allowed) {
            throw new Error('سقف ارسال ساعتی برای این شماره تلفن تکمیل شده است');
          }

          ctx.rateLimiter.attempt(userRlKey, userMax, 3600, 3600);
          ctx.rateLimiter.attempt(phoneRlKey, phoneMax, 3600, 3600);

          if (
            ctx.smsGatewayAvailable === false ||
            (Array.isArray(ctx.smsFailPhones) && ctx.smsFailPhones.includes(parentMobile))
          ) {
            throw new Error('خطا در ارسال پیامک از درگاه ملی‌پیامک');
          }

          const sentAt = new Date().toISOString().replace('T', ' ').slice(0, 19);
          const safeName = evalItem.student_name.replace(/[;\r\n]/g, ' ').trim();
          const safeDayAndDate = evalItem.day_and_date_fa.replace(/[;\r\n]/g, ' ').trim();
          const safeTime = evalItem.time_fa.replace(/[;\r\n]/g, ' ').trim();

          ctx.smsDispatches.push({
            provider: 'melipayamak',
            endpoint: 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
            body_id: patternMeta.body_id,
            pattern_source: patternMeta.source,
            body_id_env: 'MELIPAYAMAK_APPOINTMENT_BODY_ID',
            to: parentMobile,
            text: `${safeName};${safeDayAndDate};${safeTime}`,
            rendered_text: renderAppointmentSmsMessage(
              safeName,
              safeDayAndDate,
              safeTime,
              patternMeta.pattern_text
            ),
            sent_at: sentAt,
          });

          upsertLog({
            student_id: sid,
            week_date: weekStart,
            mobile: parentMobile,
            appointment_at: appointmentAt,
            status: 'sent',
            error: null,
            increment_attempts: true,
            sent_at: sentAt,
            created_by: user.id,
          });

          summary.sent++;
          details.push({
            student_id: sid,
            student_name: evalItem.student_name,
            mobile: parentMobile,
            appointment_at: appointmentAt,
            multiple_appointments: evalItem.multiple_appointments,
            action: 'sent',
            status: 'sent',
            error: null,
          });
        } catch (err) {
          const errMsg = err?.message || 'خطای ناشناخته در ارسال پیامک';
          upsertLog({
            student_id: sid,
            week_date: weekStart,
            mobile: parentMobile,
            appointment_at: appointmentAt,
            status: 'failed',
            error: errMsg,
            increment_attempts: true,
            sent_at: null,
            created_by: user.id,
          });

          summary.failed++;
          details.push({
            student_id: sid,
            student_name: evalItem.student_name,
            mobile: parentMobile,
            appointment_at: appointmentAt,
            multiple_appointments: evalItem.multiple_appointments,
            action: 'failed',
            status: 'failed',
            error: errMsg,
          });
        }
      }

      return {
        status: 200,
        body: {
          success: true,
          data: {
            week_date: weekStart,
            week_end: weekEnd,
            mode,
            body_id: patternMeta.body_id,
            pattern_source: patternMeta.source,
            recipient_type: 'parent',
            summary,
            details,
          },
          pagination: null,
          error: null,
        },
      };
    } finally {
      ctx.redis.del(lockKey);
    }
  }

  if (method === 'GET' && pathname === '/api/v1/appointments/sms/log') {
    const rawWeekDate = urlObj.searchParams.get('week_date') || '';
    const filterStudentId = urlObj.searchParams.get('student_id')
      ? Number(urlObj.searchParams.get('student_id'))
      : null;
    const filterStatus = urlObj.searchParams.get('status') || '';

    let filterWeekStart = null;
    if (rawWeekDate) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(rawWeekDate)) {
        return {
          status: 422,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'VALIDATION_ERROR', message: 'فرمت تاریخ هفته نامعتبر است (YYYY-MM-DD)' },
          },
        };
      }
      filterWeekStart = getIranWeekStart(rawWeekDate);
    }

    if (filterStatus && !['sent', 'failed', 'skipped_no_phone'].includes(filterStatus)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: {
            code: 'VALIDATION_ERROR',
            message: 'وضعیت فیلتر نامعتبر است (مجاز: sent, failed, skipped_no_phone)',
          },
        },
      };
    }

    const logs = (ctx.appointmentSmsLogs || [])
      .filter((l) => {
        if (filterWeekStart && l.week_date !== filterWeekStart) return false;
        if (filterStudentId && l.student_id !== filterStudentId) return false;
        if (filterStatus && l.status !== filterStatus) return false;
        return true;
      })
      .sort((a, b) => b.id - a.id)
      .map((l) => {
        const st = ctx.students.find((s) => s.id === l.student_id);
        const cr = ctx.users.find((u) => u.id === l.created_by);
        return {
          ...l,
          student_name: st?.name || '',
          created_by_name: cr?.full_name || null,
        };
      });

    return {
      status: 200,
      body: {
        success: true,
        data: { logs },
        pagination: {
          page: 1,
          per_page: 20,
          total: logs.length,
          total_pages: Math.max(1, Math.ceil(logs.length / 20)),
        },
        error: null,
      },
    };
  }

  return {
    status: 404,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' },
    },
  };
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
    const { value } = cache.remember('public', 'supporters', 300, () => {
      const customSupporters = (ctx.supporters || []).map((s) => {
        const resolvedUrl = s.avatar
          ? /^https?:\/\//i.test(s.avatar)
            ? s.avatar
            : `https://api.example.com/uploads/${String(s.avatar).replace(/^\/?uploads\/?/, '')}`
          : null;
        return {
          id: s.id,
          name: s.name,
          grade: s.grade ?? 12,
          field: s.field ?? '',
          description: s.description ?? null,
          avatar: s.avatar ?? null,
          avatar_url: resolvedUrl,
          image_url: resolvedUrl,
        };
      });
      return [...INITIAL_SUPPORTERS, ...customSupporters];
    });
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

export function handleTutoringClassroomEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  if (!authUser || !authUser.sub) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'AUTH_ERROR', message: 'توکن احراز هویت ارسال نشده است' },
      },
    };
  }

  const allowedRoles = ['admin', 'supporter', 'teacher'];
  if (!allowedRoles.includes(authUser.role)) {
    return {
      status: 403,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'FORBIDDEN', message: 'شما مجوز دسترسی به این بخش را ندارید' },
      },
    };
  }

  const pathname = urlObj.pathname;
  const nowUtc = new Date().toISOString().replace('T', ' ').slice(0, 19);

  const resolveActor = () => {
    const actorUserId = Number(authUser.sub) > 0 ? Number(authUser.sub) : null;
    const actorRole = authUser.role || null;
    let actorInstructorId =
      Number(authUser.instructor_id) > 0 ? Number(authUser.instructor_id) : null;
    if (!actorInstructorId && actorUserId && actorRole === 'teacher') {
      const userRec = ctx.users.find((u) => u.id === actorUserId);
      if (userRec && Number(userRec.linked_id) > 0) {
        actorInstructorId = Number(userRec.linked_id);
      }
    }
    return {
      actor_user_id: actorUserId,
      actor_role: actorRole,
      actor_instructor_id: actorInstructorId,
      admin_or_instructor_id: actorInstructorId ?? actorUserId,
    };
  };

  const recordAudit = (classroomId, instructorId, action, before, after) => {
    const actor = resolveActor();
    const trackedFields = ['name', 'teacher_name', 'field', 'instructor_id', 'sort_order', 'is_active'];
    const diff = {};

    if (!before && after) {
      for (const f of trackedFields) {
        diff[f] = { from: null, to: after[f] ?? null };
      }
    } else if (before && !after) {
      for (const f of trackedFields) {
        diff[f] = { from: before[f] ?? null, to: null };
      }
    } else if (before && after) {
      for (const f of trackedFields) {
        if ((before[f] ?? null) !== (after[f] ?? null)) {
          diff[f] = { from: before[f] ?? null, to: after[f] ?? null };
        }
      }
    }

    const targetName = after?.name || before?.name || `#${classroomId}`;
    const changedKeys = Object.keys(diff);
    let summary = `تغییر وضعیت کلاس «${targetName}» (${action})`;
    if (action === 'created') summary = `کلاس «${targetName}» ایجاد شد`;
    else if (action === 'reactivated') summary = `کلاس «${targetName}» مجدداً فعال شد`;
    else if (action === 'updated') {
      summary =
        changedKeys.length > 0
          ? `پیکربندی کلاس «${targetName}» ویرایش شد (${changedKeys.join(', ')})`
          : `پیکربندی کلاس «${targetName}» بررسی/به‌روزرسانی شد`;
    } else if (action === 'deleted') summary = `کلاس «${targetName}» حذف شد`;
    else if (action === 'deactivated') summary = `کلاس «${targetName}» غیرفعال شد`;

    const nextId =
      ctx.tutoringClassroomAuditLogs.reduce((m, item) => Math.max(m, item.id), 0) + 1;
    const entry = {
      id: nextId,
      classroom_id: classroomId,
      instructor_id: instructorId ?? null,
      ...actor,
      action,
      summary,
      changes: {
        before: before ? { ...before } : null,
        after: after ? { ...after } : null,
        diff,
      },
      timestamp: nowUtc,
      created_at: nowUtc,
    };
    ctx.tutoringClassroomAuditLogs.unshift(entry);
    return entry;
  };

  if (method === 'GET' && pathname === '/api/v1/tutoring/classrooms') {
    const active = ctx.tutoringClassrooms
      .filter((c) => c.is_active)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    return {
      status: 200,
      body: { success: true, data: active, pagination: null, error: null },
    };
  }

  const auditByIdMatch = pathname.match(/^\/api\/v1\/tutoring\/classrooms\/(\d+)\/audit-logs$/);
  if (
    method === 'GET' &&
    (pathname === '/api/v1/tutoring/classrooms/audit-logs' || auditByIdMatch)
  ) {
    const classroomIdParam = auditByIdMatch
      ? Number(auditByIdMatch[1])
      : urlObj.searchParams.get('classroom_id');
    const instructorIdParam = urlObj.searchParams.get('instructor_id');
    const actorUserIdParam = urlObj.searchParams.get('actor_user_id');
    const actionParam = urlObj.searchParams.get('action');
    const limitParam = urlObj.searchParams.get('limit');

    const allowedActions = ['created', 'reactivated', 'updated', 'deleted', 'deactivated'];
    if (actionParam && !allowedActions.includes(actionParam)) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'عملیات نامعتبر است' },
        },
      };
    }

    let logs = [...ctx.tutoringClassroomAuditLogs];
    if (classroomIdParam) {
      logs = logs.filter((l) => l.classroom_id === Number(classroomIdParam));
    }
    if (instructorIdParam) {
      const instId = Number(instructorIdParam);
      logs = logs.filter(
        (l) => l.instructor_id === instId || l.actor_instructor_id === instId
      );
    }
    if (actorUserIdParam) {
      logs = logs.filter((l) => l.actor_user_id === Number(actorUserIdParam));
    }
    if (actionParam) {
      logs = logs.filter((l) => l.action === actionParam);
    }

    const limit = limitParam ? Math.min(200, Math.max(1, Number(limitParam))) : 50;
    return {
      status: 200,
      body: {
        success: true,
        data: logs.slice(0, limit),
        pagination: null,
        error: null,
      },
    };
  }

  if (method === 'POST' && pathname === '/api/v1/tutoring/classrooms') {
    const rawName = String(
      body?.name ?? body?.class_name ?? body?.classroom_name ?? body?.teacher_name ?? ''
    ).trim();
    if (!rawName) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'نام کلاس الزامی است' },
        },
      };
    }

    const teacherName = String(body?.teacher_name ?? '').trim();
    const field = String(body?.field ?? '').trim() || null;
    const sortOrder =
      body?.sort_order !== undefined && body?.sort_order !== null && body?.sort_order !== ''
        ? Number(body.sort_order)
        : ctx.tutoringClassrooms.reduce((m, c) => Math.max(m, c.sort_order), 0) + 1;

    let instructorId = Number(body?.instructor_id) > 0 ? Number(body.instructor_id) : 0;
    if (!instructorId) {
      const nextInstId =
        ctx.instructors.reduce((m, i) => Math.max(m, i.id), 0) + 1;
      ctx.instructors.push({
        id: nextInstId,
        name: teacherName,
        title: field || 'مدرس',
      });
      instructorId = nextInstId;
    }

    const nextId =
      ctx.tutoringClassrooms.reduce((m, c) => Math.max(m, c.id), 0) + 1;
    const created = {
      id: nextId,
      classroom_id: nextId,
      name: rawName,
      class_name: rawName,
      classroom_name: rawName,
      instructor_id: instructorId,
      teacher_name: teacherName,
      field,
      sort_order: sortOrder,
      is_active: true,
    };
    ctx.tutoringClassrooms.push(created);
    recordAudit(nextId, instructorId, 'created', null, created);
    recordStaffActivity(ctx, authUser, {
      module: 'tutoring',
      action: 'create_classroom',
      target_type: 'tutoring_classroom',
      target_id: String(nextId),
      summary: `ایجاد ستون کلاس رفع اشکال جدید «${created.name}»`,
      http_method: 'POST',
      endpoint: '/api/v1/tutoring/classrooms',
      status_code: 201,
      details: { request: { name: created.name, teacher_name: created.teacher_name, field: created.field } },
    });

    return {
      status: 201,
      body: { success: true, data: created, pagination: null, error: null },
    };
  }

  const itemMatch = pathname.match(/^\/api\/v1\/tutoring\/classrooms\/(\d+)$/);
  if (itemMatch && (method === 'PUT' || method === 'PATCH')) {
    const classroomId = Number(itemMatch[1]);
    const idx = ctx.tutoringClassrooms.findIndex((c) => c.id === classroomId);
    if (idx === -1) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'کلاس درس یافت نشد' },
        },
      };
    }

    if (!body || typeof body !== 'object' || Object.keys(body).length === 0) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: {
            code: 'VALIDATION_ERROR',
            message: 'بدنه درخواست الزامی است و باید یک شیء معتبر باشد',
          },
        },
      };
    }

    const before = { ...ctx.tutoringClassrooms[idx] };
    const nextName =
      body.name !== undefined ||
      body.class_name !== undefined ||
      body.classroom_name !== undefined
        ? String(body.name ?? body.class_name ?? body.classroom_name ?? '').trim()
        : before.name;

    if (!nextName) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'نام کلاس الزامی است' },
        },
      };
    }

    const updated = {
      ...before,
      name: nextName,
      class_name: nextName,
      classroom_name: nextName,
      teacher_name:
        body.teacher_name !== undefined ? String(body.teacher_name).trim() : before.teacher_name,
      field:
        body.field !== undefined
          ? String(body.field).trim() || null
          : before.field,
      sort_order:
        body.sort_order !== undefined && body.sort_order !== null && body.sort_order !== ''
          ? Number(body.sort_order)
          : before.sort_order,
    };

    ctx.tutoringClassrooms[idx] = updated;
    recordAudit(classroomId, updated.instructor_id, 'updated', before, updated);
    recordStaffActivity(ctx, authUser, {
      module: 'tutoring',
      action: 'update_classroom',
      target_type: 'tutoring_classroom',
      target_id: String(classroomId),
      summary: `ویرایش پیکربندی کلاس رفع اشکال #${classroomId} «${updated.name}»`,
      http_method: method,
      endpoint: pathname,
      status_code: 200,
      details: { request: body },
    });

    return {
      status: 200,
      body: { success: true, data: updated, pagination: null, error: null },
    };
  }

  if (itemMatch && method === 'DELETE') {
    const classroomId = Number(itemMatch[1]);
    const idx = ctx.tutoringClassrooms.findIndex((c) => c.id === classroomId);
    if (idx === -1) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'کلاس درس یافت نشد' },
        },
      };
    }

    const before = { ...ctx.tutoringClassrooms[idx] };
    ctx.tutoringClassrooms.splice(idx, 1);
    recordAudit(classroomId, before.instructor_id, 'deleted', before, null);
    recordStaffActivity(ctx, authUser, {
      module: 'tutoring',
      action: 'delete_classroom',
      target_type: 'tutoring_classroom',
      target_id: String(classroomId),
      summary: `حذف ستون کلاس رفع اشکال #${classroomId} «${before.name}»`,
      http_method: 'DELETE',
      endpoint: pathname,
      status_code: 200,
    });

    return {
      status: 200,
      body: {
        success: true,
        data: { id: classroomId, deleted: true },
        pagination: null,
        error: null,
      },
    };
  }

  return {
    status: 404,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' },
    },
  };
}

export function recordStaffActivity(ctx, authUser, entry = {}) {
  if (!ctx.staffActivityLogs) {
    ctx.staffActivityLogs = [];
  }
  const actorUserId = Number(entry.actor_user_id ?? authUser?.sub ?? 1);
  const userRec = ctx.users?.find((u) => u.id === actorUserId) || null;
  const actorRole = String(entry.actor_role ?? authUser?.role ?? userRec?.role ?? 'admin').toLowerCase();
  if (!['admin', 'supporter', 'teacher'].includes(actorRole)) {
    return null;
  }

  let actorLinkedId =
    entry.actor_linked_id !== undefined
      ? entry.actor_linked_id
      : authUser?.instructor_id ?? userRec?.linked_id ?? null;
  if (actorLinkedId !== null) {
    actorLinkedId = Number(actorLinkedId) > 0 ? Number(actorLinkedId) : null;
  }

  let actorName = entry.actor_name || userRec?.full_name || userRec?.username || null;
  if (!actorName && actorLinkedId) {
    if (actorRole === 'admin') {
      actorName = ctx.admins?.find((a) => a.id === actorLinkedId)?.name || 'مدیر ارشد';
    } else if (actorRole === 'supporter') {
      actorName = ctx.supporters?.find((s) => s.id === actorLinkedId)?.name || 'پشتیبان';
    } else if (actorRole === 'teacher') {
      actorName = ctx.instructors?.find((i) => i.id === actorLinkedId)?.name || 'استاد';
    }
  }
  if (!actorName) {
    actorName =
      actorRole === 'admin'
        ? 'مدیر ارشد'
        : actorRole === 'supporter'
          ? 'پشتیبان نمونه'
          : 'استاد نمونه';
  }

  const roleLabels = {
    admin: 'ادمین',
    supporter: 'پشتیبان',
    teacher: 'استاد / مدرس',
  };

  const nowUtc = new Date().toISOString().replace('T', ' ').slice(0, 19);
  const nextId = ctx.staffActivityLogs.reduce((m, item) => Math.max(m, item.id), 0) + 1;
  const record = {
    id: nextId,
    actor_user_id: actorUserId,
    actor_role: actorRole,
    actor_role_label: roleLabels[actorRole] || actorRole,
    actor_linked_id: actorLinkedId,
    actor_name: actorName,
    module: String(entry.module || 'system').toLowerCase(),
    action: String(entry.action || 'action').toLowerCase(),
    target_type: entry.target_type ?? null,
    target_id: entry.target_id !== undefined && entry.target_id !== null ? String(entry.target_id) : null,
    summary: String(entry.summary || 'ثبت فعالیت در سیستم'),
    http_method: String(entry.http_method || 'POST').toUpperCase(),
    endpoint: String(entry.endpoint || '/api/v1'),
    status_code: Number(entry.status_code || 200),
    ip_address: entry.ip_address || '127.0.0.1',
    details: entry.details ?? null,
    timestamp: nowUtc,
    created_at: nowUtc,
  };

  ctx.staffActivityLogs.unshift(record);
  return record;
}

export function handleAdminActivityLogEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  if (!authUser || !authUser.sub) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'AUTH_ERROR', message: 'توکن احراز هویت ارسال نشده است' },
      },
    };
  }

  const pathname = urlObj.pathname;

  // Allow simulator to record a staff activity from any staff role (admin/supporter/teacher)
  if (method === 'POST' && pathname === '/api/v1/admin/activity-logs/record') {
    if (!['admin', 'supporter', 'teacher'].includes(String(authUser.role || '').toLowerCase())) {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'شما مجوز ثبت فعالیت کادر آموزشی را ندارید' },
        },
      };
    }
    const created = recordStaffActivity(ctx, authUser, body);
    return {
      status: 201,
      body: {
        success: true,
        data: created,
        pagination: null,
        error: null,
      },
    };
  }

  if (authUser.role !== 'admin') {
    return {
      status: 403,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'FORBIDDEN', message: 'فقط مدیران سیستم مجاز به مشاهده لاگ فعالیت‌ها هستند' },
      },
    };
  }

  const allLogs = [...(ctx.staffActivityLogs || [])].sort((a, b) => b.id - a.id);

  if (
    method === 'GET' &&
    (pathname === '/api/v1/admin/activity-logs/stats' ||
      pathname === '/api/v1/admin/stats/activity-logs/stats')
  ) {
    const todayPrefix = new Date().toISOString().slice(0, 10);
    const byRole = { admin: 0, supporter: 0, teacher: 0 };
    const moduleMap = new Map();
    const actorMap = new Map();
    let todayLogs = 0;

    for (const l of allLogs) {
      if (String(l.created_at || '').startsWith(todayPrefix)) {
        todayLogs++;
      }
      if (byRole[l.actor_role] !== undefined) {
        byRole[l.actor_role]++;
      } else {
        byRole[l.actor_role] = 1;
      }
      moduleMap.set(l.module, (moduleMap.get(l.module) || 0) + 1);

      const actorKey = `${l.actor_user_id}:${l.actor_role}`;
      if (!actorMap.has(actorKey)) {
        actorMap.set(actorKey, {
          actor_user_id: l.actor_user_id,
          actor_role: l.actor_role,
          actor_linked_id: l.actor_linked_id,
          actor_name: l.actor_name,
          actions_count: 0,
          last_activity_at: l.created_at,
        });
      }
      const actorStat = actorMap.get(actorKey);
      actorStat.actions_count++;
      if (l.created_at > actorStat.last_activity_at) {
        actorStat.last_activity_at = l.created_at;
      }
    }

    const byModule = [...moduleMap.entries()]
      .map(([module, count]) => ({ module, count }))
      .sort((a, b) => b.count - a.count);

    const activeActors = [...actorMap.values()].sort(
      (a, b) => b.actions_count - a.actions_count || b.last_activity_at.localeCompare(a.last_activity_at)
    );

    return {
      status: 200,
      body: {
        success: true,
        data: {
          total_logs: allLogs.length,
          today_logs: todayLogs,
          by_role: byRole,
          by_module: byModule,
          active_actors: activeActors,
        },
        pagination: null,
        error: null,
      },
    };
  }

  if (
    method === 'GET' &&
    (pathname === '/api/v1/admin/activity-logs' || pathname === '/api/v1/admin/stats/activity-logs')
  ) {
    const roleFilter = (urlObj.searchParams.get('role') || '').trim().toLowerCase();
    const actorUserIdFilter = urlObj.searchParams.get('actor_user_id');
    const actorLinkedIdFilter = urlObj.searchParams.get('actor_linked_id');
    const moduleFilter = (urlObj.searchParams.get('module') || '').trim().toLowerCase();
    const actionFilter = (urlObj.searchParams.get('action') || '').trim().toLowerCase();
    const qFilter = (urlObj.searchParams.get('q') || '').trim().toLowerCase();
    const fromFilter = (urlObj.searchParams.get('from') || urlObj.searchParams.get('date_from') || '').trim();
    const toFilter = (urlObj.searchParams.get('to') || urlObj.searchParams.get('date_to') || '').trim();

    let filtered = allLogs.filter((l) => {
      if (roleFilter && l.actor_role !== roleFilter) return false;
      if (actorUserIdFilter && l.actor_user_id !== Number(actorUserIdFilter)) return false;
      if (actorLinkedIdFilter && l.actor_linked_id !== Number(actorLinkedIdFilter)) return false;
      if (moduleFilter && l.module !== moduleFilter) return false;
      if (actionFilter && l.action !== actionFilter) return false;
      if (fromFilter) {
        const minStr = /^\d{4}-\d{2}-\d{2}$/.test(fromFilter) ? `${fromFilter} 00:00:00` : fromFilter;
        if (l.created_at < minStr) return false;
      }
      if (toFilter) {
        const maxStr = /^\d{4}-\d{2}-\d{2}$/.test(toFilter) ? `${toFilter} 23:59:59` : toFilter;
        if (l.created_at > maxStr) return false;
      }
      if (qFilter) {
        const hay = `${l.summary || ''} ${l.actor_name || ''} ${l.endpoint || ''} ${l.target_id || ''}`.toLowerCase();
        if (!hay.includes(qFilter)) return false;
      }
      return true;
    });

    const page = Math.max(1, parseInt(urlObj.searchParams.get('page') || '1', 10) || 1);
    const perPage = Math.min(
      200,
      Math.max(
        1,
        parseInt(urlObj.searchParams.get('per_page') || urlObj.searchParams.get('limit') || '50', 10) || 50
      )
    );
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const items = filtered.slice((page - 1) * perPage, page * perPage);

    return {
      status: 200,
      body: {
        success: true,
        data: items,
        pagination: {
          page,
          per_page: perPage,
          total,
          total_pages: totalPages,
        },
        error: null,
      },
    };
  }

  return {
    status: 404,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' },
    },
  };
}

/**
 * Supporter CRUD + Avatar upload & Description simulator mirroring SupporterController / SupporterService
 */
export function handleSupporterEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  const pathname = urlObj instanceof URL ? urlObj.pathname : String(urlObj);
  const parsedUrl = urlObj instanceof URL ? urlObj : new URL(pathname, 'http://localhost');

  if (!authUser || !authUser.role) {
    return {
      status: 401,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' },
      },
    };
  }

  const role = String(authUser.role).toLowerCase();
  const resolveAvatarUrl = (avatarPath) => {
    if (!avatarPath) return null;
    if (/^https?:\/\//i.test(avatarPath)) return avatarPath;
    const clean = String(avatarPath).replace(/^\/+/, '').replace(/^uploads\//, '');
    return `https://api.example.com/uploads/${clean}`;
  };

  const hydrateSupporter = (row) => {
    const resolved = resolveAvatarUrl(row.avatar ?? null);
    return {
      ...row,
      description: row.description ?? null,
      avatar: row.avatar ?? null,
      avatar_url: resolved,
      image_url: resolved,
    };
  };

  // GET /api/v1/supporters
  if (method === 'GET' && pathname === '/api/v1/supporters') {
    if (role !== 'admin' && role !== 'supporter') {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'شما مجوز دسترسی به این بخش را ندارید' },
        },
      };
    }
    const items = (ctx.supporters || []).map(hydrateSupporter);
    return {
      status: 200,
      body: {
        success: true,
        data: items,
        pagination: { page: 1, per_page: 20, total: items.length, total_pages: 1 },
        error: null,
      },
    };
  }

  // GET /api/v1/supporters/{id}
  const idMatch = pathname.match(/^\/api\/v1\/supporters\/(\d+)$/);
  if (method === 'GET' && idMatch) {
    if (role !== 'admin' && role !== 'supporter') {
      return {
        status: 403,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'FORBIDDEN', message: 'شما مجوز دسترسی به این بخش را ندارید' },
        },
      };
    }
    const id = parseInt(idMatch[1], 10);
    const found = (ctx.supporters || []).find((s) => s.id === id);
    if (!found) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'پشتیبان یافت نشد' },
        },
      };
    }
    return {
      status: 200,
      body: {
        success: true,
        data: hydrateSupporter(found),
        pagination: null,
        error: null,
      },
    };
  }

  // Admin-only mutations below
  if (role !== 'admin') {
    return {
      status: 403,
      body: {
        success: false,
        data: null,
        pagination: null,
        error: { code: 'FORBIDDEN', message: 'فقط مدیر سیستم مجاز به انجام این عملیات است' },
      },
    };
  }

  const normalizeInputBody = (raw) => {
    const out = { ...(raw || {}) };
    if (typeof out.scopes === 'string' && out.scopes.trim() !== '') {
      try {
        const parsed = JSON.parse(out.scopes);
        if (Array.isArray(parsed)) out.scopes = parsed;
      } catch {
        // leave as-is for validation
      }
    }
    if (out.description === undefined && out.bio !== undefined) {
      out.description = out.bio;
    }
    return out;
  };

  // POST /api/v1/supporters
  if (method === 'POST' && pathname === '/api/v1/supporters') {
    const input = normalizeInputBody(body);
    const name = String(input.name || '').trim();
    const phone = normalizeIranianPhone(input.phone);
    if (!name || !phone) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'نام و شماره تلفن معتبر پشتیبان الزامی است' },
        },
      };
    }
    const nextId =
      (ctx.supporters || []).reduce((max, s) => Math.max(max, s.id), 10) + 1;
    const uploadedFile = input.avatar_file || input.image_file || input.avatar || input.image || null;
    let avatarPath = null;
    if (uploadedFile && typeof uploadedFile === 'object' && uploadedFile.filename) {
      const baseName = String(uploadedFile.filename).replace(/\.[^.]+$/, '');
      avatarPath = `supporters/${nextId}/${baseName}.webp`;
    } else if (typeof uploadedFile === 'string' && uploadedFile.trim() !== '') {
      avatarPath = uploadedFile.trim().startsWith('supporters/')
        ? uploadedFile.trim()
        : `supporters/${nextId}/${uploadedFile.trim()}`;
    }

    const grade = input.grade ? Number(input.grade) : (Array.isArray(input.scopes) && input.scopes[0]?.grade ? Number(input.scopes[0].grade) : 12);
    const field = input.field || (Array.isArray(input.scopes) && input.scopes[0]?.field ? String(input.scopes[0].field) : 'تجربی');

    const newSupporter = {
      id: nextId,
      name,
      grade,
      field,
      phone,
      chat_id: input.chat_id ?? null,
      description: input.description !== undefined ? input.description : null,
      avatar: avatarPath,
      scopes: Array.isArray(input.scopes) ? input.scopes : [{ field, grade }],
      is_active: input.is_active !== undefined ? Number(input.is_active) : 1,
    };
    ctx.supporters.push(newSupporter);
    ctx.cache.flushGroup('public');

    return {
      status: 201,
      body: {
        success: true,
        data: hydrateSupporter(newSupporter),
        pagination: null,
        error: null,
      },
    };
  }

  // PUT / PATCH / POST /api/v1/supporters/{id} or /api/v1/supporters/{id}/avatar
  const avatarRouteMatch = pathname.match(/^\/api\/v1\/supporters\/(\d+)\/avatar$/);
  if (avatarRouteMatch && (method === 'POST' || method === 'DELETE')) {
    const id = parseInt(avatarRouteMatch[1], 10);
    const supporter = (ctx.supporters || []).find((s) => s.id === id);
    if (!supporter) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'پشتیبان یافت نشد' },
        },
      };
    }
    if (method === 'DELETE') {
      supporter.avatar = null;
      ctx.cache.flushGroup('public');
      return {
        status: 200,
        body: {
          success: true,
          data: hydrateSupporter(supporter),
          pagination: null,
          error: null,
        },
      };
    }
    const uploaded = body.avatar_file || body.image_file || body.avatar || body.image || null;
    if (!uploaded) {
      return {
        status: 422,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'VALIDATION_ERROR', message: 'فایل عکس پروفایل (avatar یا image) ارسال نشده است' },
        },
      };
    }
    const fileLabel = typeof uploaded === 'object' && uploaded.filename ? uploaded.filename : String(uploaded);
    supporter.avatar = `supporters/${id}/${fileLabel.replace(/\.[^.]+$/, '')}.webp`;
    ctx.cache.flushGroup('public');
    return {
      status: 200,
      body: {
        success: true,
        data: hydrateSupporter(supporter),
        pagination: null,
        error: null,
      },
    };
  }

  if (idMatch && (method === 'PUT' || method === 'PATCH' || method === 'POST')) {
    const id = parseInt(idMatch[1], 10);
    const supporter = (ctx.supporters || []).find((s) => s.id === id);
    if (!supporter) {
      return {
        status: 404,
        body: {
          success: false,
          data: null,
          pagination: null,
          error: { code: 'NOT_FOUND', message: 'پشتیبان یافت نشد' },
        },
      };
    }

    const input = normalizeInputBody(body);
    if (input.name !== undefined) supporter.name = String(input.name).trim();
    if (input.grade !== undefined && input.grade !== null && input.grade !== '') supporter.grade = Number(input.grade);
    if (input.field !== undefined && input.field !== null && input.field !== '') supporter.field = String(input.field);
    if (input.description !== undefined) supporter.description = input.description;
    if (input.phone !== undefined && input.phone !== null && input.phone !== '') {
      const norm = normalizeIranianPhone(input.phone);
      if (!norm) {
        return {
          status: 422,
          body: {
            success: false,
            data: null,
            pagination: null,
            error: { code: 'VALIDATION_ERROR', message: 'شماره تلفن پشتیبان معتبر نیست' },
          },
        };
      }
      supporter.phone = norm;
    }
    if (Array.isArray(input.scopes)) {
      supporter.scopes = input.scopes;
    }
    const uploaded = input.avatar_file || input.image_file || input.avatar || input.image;
    if (uploaded !== undefined) {
      if (uploaded === null || uploaded === '') {
        supporter.avatar = null;
      } else if (typeof uploaded === 'object' && uploaded.filename) {
        supporter.avatar = `supporters/${id}/${String(uploaded.filename).replace(/\.[^.]+$/, '')}.webp`;
      } else if (typeof uploaded === 'string') {
        supporter.avatar = uploaded.startsWith('supporters/') ? uploaded : `supporters/${id}/${uploaded}`;
      }
    }

    ctx.cache.flushGroup('public');
    return {
      status: 200,
      body: {
        success: true,
        data: hydrateSupporter(supporter),
        pagination: null,
        error: null,
      },
    };
  }

  return {
    status: 404,
    body: {
      success: false,
      data: null,
      pagination: null,
      error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' },
    },
  };
}

export function formatSubscription(sub, ctx) {
  const course = (ctx.courses || []).find((c) => c.id === sub.course_id) || {
    id: sub.course_id,
    name: 'دوره جامع کنکور',
    price: sub.monthly_fee || 0,
    icon: '📚',
    description: null,
  };
  const student = (ctx.students || []).find((s) => s.id === sub.student_id) || {
    id: sub.student_id,
    name: 'دانش‌آموز',
    phone: null,
    grade: 12,
    field: '',
  };
  const tuitionLabels = {
    unpaid: 'پرداخت نشده',
    paid: 'پرداخت شده',
    pending: 'در انتظار بررسی',
    exempt: 'معاف از شهریه',
    partial: 'پرداخت ناقص / بیعانه',
  };
  const tStatus = sub.tuition_status || 'unpaid';

  return {
    id: sub.id,
    course_id: sub.course_id,
    student_id: sub.student_id,
    status: sub.status || 'pending',
    is_active: sub.is_active !== undefined ? Number(sub.is_active) : 1,
    monthly_fee: Number(sub.monthly_fee ?? course.monthly_fee ?? course.price ?? 0),
    tuition_status: tStatus,
    tuition_status_label: tuitionLabels[tStatus] || tStatus,
    tuition_due_date: sub.tuition_due_date || null,
    last_payment_date: sub.last_payment_date || null,
    tuition_notes: sub.tuition_notes || null,
    note: sub.note || null,
    started_at: sub.started_at || null,
    end_at: sub.end_at || null,
    created_at: sub.created_at || '2026-11-01 10:00:00',
    updated_at: sub.updated_at || '2026-11-01 10:00:00',
    course: {
      id: course.id,
      name: course.name || course.title,
      price: course.price || 0,
      icon: course.icon || '📚',
      description: course.description || null,
    },
    student: {
      id: student.id,
      name: student.name,
      phone: student.phone,
      grade: student.grade,
      field: student.field,
    },
  };
}

export function handleSubscriptionEndpoint(ctx, method, urlObj, body = {}, authUser = null) {
  const pathname = urlObj.pathname;
  const isStudent = authUser && authUser.role === 'student';
  const currentStudentId = isStudent ? (authUser.linked_id || authUser.sub) : null;

  // 1. GET /api/v1/subscriptions/me
  if (pathname === '/api/v1/subscriptions/me' && method === 'GET') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    const studentId = isStudent ? currentStudentId : (urlObj.searchParams.get('student_id') ? parseInt(urlObj.searchParams.get('student_id'), 10) : 20);
    const onlyActive = urlObj.searchParams.get('only_active') === '1' || urlObj.searchParams.get('only_active') === 'true';

    let subs = (ctx.subscriptions || []).filter((s) => s.student_id === studentId);
    if (onlyActive) {
      subs = subs.filter((s) => s.is_active === 1);
    }
    const formatted = subs.map((s) => formatSubscription(s, ctx));
    return {
      status: 200,
      body: { success: true, data: formatted, pagination: null, error: null },
    };
  }

  // 2. GET /api/v1/students/{id}/subscriptions
  const studentSubsMatch = pathname.match(/^\/api\/v1\/students\/(\d+)\/subscriptions$/);
  if (studentSubsMatch && method === 'GET') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    const targetStudentId = parseInt(studentSubsMatch[1], 10);
    if (isStudent && currentStudentId !== targetStudentId) {
      return { status: 403, body: { success: false, data: null, error: { code: 'FORBIDDEN', message: 'دسترسی غیرمجاز' } } };
    }
    const onlyActive = urlObj.searchParams.get('only_active') === '1' || urlObj.searchParams.get('only_active') === 'true';
    let subs = (ctx.subscriptions || []).filter((s) => s.student_id === targetStudentId);
    if (onlyActive) {
      subs = subs.filter((s) => s.is_active === 1);
    }
    const formatted = subs.map((s) => formatSubscription(s, ctx));
    return {
      status: 200,
      body: { success: true, data: formatted, pagination: null, error: null },
    };
  }

  // 3. POST /api/v1/courses/{id}/enroll
  const enrollMatch = pathname.match(/^\/api\/v1\/courses\/(\d+)\/enroll$/);
  if (enrollMatch && method === 'POST') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    const courseId = parseInt(enrollMatch[1], 10);
    const course = (ctx.courses || []).find((c) => c.id === courseId);
    if (!course) {
      return { status: 404, body: { success: false, data: null, error: { code: 'COURSE_NOT_FOUND', message: 'دوره مورد نظر یافت نشد' } } };
    }

    const studentId = isStudent ? currentStudentId : (body.student_id ? parseInt(body.student_id, 10) : currentStudentId || 20);

    const existing = (ctx.subscriptions || []).find(
      (s) => s.student_id === studentId && s.course_id === courseId && s.is_active === 1 && ['pending', 'active'].includes(s.status)
    );
    if (existing) {
      return {
        status: 409,
        body: { success: false, data: null, error: { code: 'ALREADY_SUBSCRIBED', message: 'این دانش‌آموز در حال حاضر اشتراک فعال یا در انتظار تایید برای این دوره دارد' } },
      };
    }

    const defaultFee = course.monthly_fee || course.price || 0;
    const monthlyFee = body.monthly_fee !== undefined && body.monthly_fee !== '' ? Number(body.monthly_fee) : defaultFee;
    const tuitionStatus = (body.tuition_status && ['unpaid', 'paid', 'pending', 'exempt', 'partial'].includes(body.tuition_status))
      ? body.tuition_status
      : 'unpaid';

    const now = new Date().toISOString();
    const dueDate = body.tuition_due_date || new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
    const newId = (ctx.subscriptions || []).reduce((max, s) => Math.max(max, s.id), 0) + 1;

    const newSub = {
      id: newId,
      course_id: courseId,
      student_id: studentId,
      status: isStudent ? 'pending' : (body.status || 'pending'),
      is_active: 1,
      monthly_fee: monthlyFee,
      tuition_status: tuitionStatus,
      tuition_due_date: dueDate,
      last_payment_date: tuitionStatus === 'paid' ? now.split('T')[0] : null,
      tuition_notes: body.tuition_notes || null,
      note: body.note || null,
      started_at: now,
      end_at: null,
      created_at: now,
      updated_at: now,
    };

    if (!ctx.subscriptions) ctx.subscriptions = [];
    ctx.subscriptions.push(newSub);

    return {
      status: 201,
      body: { success: true, data: formatSubscription(newSub, ctx), pagination: null, error: null },
    };
  }

  // 4. POST /api/v1/subscriptions
  if (pathname === '/api/v1/subscriptions' && method === 'POST') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    const courseId = parseInt(body.course_id, 10);
    if (!courseId) {
      return { status: 422, body: { success: false, data: null, error: { code: 'VALIDATION_ERROR', message: 'شناسه دوره الزامی است' } } };
    }
    const course = (ctx.courses || []).find((c) => c.id === courseId);
    if (!course) {
      return { status: 404, body: { success: false, data: null, error: { code: 'COURSE_NOT_FOUND', message: 'دوره مورد نظر یافت نشد' } } };
    }

    const studentId = isStudent ? currentStudentId : (body.student_id ? parseInt(body.student_id, 10) : currentStudentId || 20);

    const existing = (ctx.subscriptions || []).find(
      (s) => s.student_id === studentId && s.course_id === courseId && s.is_active === 1 && ['pending', 'active'].includes(s.status)
    );
    if (existing) {
      return {
        status: 409,
        body: { success: false, data: null, error: { code: 'ALREADY_SUBSCRIBED', message: 'این دانش‌آموز در حال حاضر اشتراک فعال یا در انتظار تایید برای این دوره دارد' } },
      };
    }

    const defaultFee = course.monthly_fee || course.price || 0;
    const monthlyFee = body.monthly_fee !== undefined && body.monthly_fee !== '' ? Number(body.monthly_fee) : defaultFee;
    const tuitionStatus = (body.tuition_status && ['unpaid', 'paid', 'pending', 'exempt', 'partial'].includes(body.tuition_status))
      ? body.tuition_status
      : 'unpaid';

    const now = new Date().toISOString();
    const dueDate = body.tuition_due_date || new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
    const newId = (ctx.subscriptions || []).reduce((max, s) => Math.max(max, s.id), 0) + 1;

    const newSub = {
      id: newId,
      course_id: courseId,
      student_id: studentId,
      status: isStudent ? 'pending' : (body.status || 'pending'),
      is_active: 1,
      monthly_fee: monthlyFee,
      tuition_status: tuitionStatus,
      tuition_due_date: dueDate,
      last_payment_date: tuitionStatus === 'paid' ? now.split('T')[0] : null,
      tuition_notes: body.tuition_notes || null,
      note: body.note || null,
      started_at: now,
      end_at: null,
      created_at: now,
      updated_at: now,
    };

    if (!ctx.subscriptions) ctx.subscriptions = [];
    ctx.subscriptions.push(newSub);

    return {
      status: 201,
      body: { success: true, data: formatSubscription(newSub, ctx), pagination: null, error: null },
    };
  }

  // 5. GET /api/v1/subscriptions
  if (pathname === '/api/v1/subscriptions' && method === 'GET') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    let items = [...(ctx.subscriptions || [])];

    if (isStudent) {
      items = items.filter((s) => s.student_id === currentStudentId);
    } else if (urlObj.searchParams.get('student_id')) {
      const sid = parseInt(urlObj.searchParams.get('student_id'), 10);
      items = items.filter((s) => s.student_id === sid);
    }

    if (urlObj.searchParams.get('course_id')) {
      const cid = parseInt(urlObj.searchParams.get('course_id'), 10);
      items = items.filter((s) => s.course_id === cid);
    }

    if (urlObj.searchParams.get('status')) {
      const st = urlObj.searchParams.get('status');
      items = items.filter((s) => s.status === st);
    }

    if (urlObj.searchParams.get('tuition_status')) {
      const tst = urlObj.searchParams.get('tuition_status');
      items = items.filter((s) => s.tuition_status === tst);
    }

    if (urlObj.searchParams.get('is_active') !== null && urlObj.searchParams.get('is_active') !== '') {
      const act = parseInt(urlObj.searchParams.get('is_active'), 10);
      items = items.filter((s) => s.is_active === act);
    }

    const page = Math.max(1, parseInt(urlObj.searchParams.get('page') || '1', 10));
    const perPage = Math.max(1, Math.min(100, parseInt(urlObj.searchParams.get('perPage') || '20', 10)));
    const total = items.length;
    const paginated = items.slice((page - 1) * perPage, page * perPage);

    return {
      status: 200,
      body: {
        success: true,
        data: paginated.map((s) => formatSubscription(s, ctx)),
        pagination: {
          total,
          page,
          perPage,
          totalPages: Math.ceil(total / perPage),
        },
        error: null,
      },
    };
  }

  // 6. PATCH|PUT /api/v1/subscriptions/{id}/tuition
  const tuitionMatch = pathname.match(/^\/api\/v1\/subscriptions\/(\d+)\/tuition$/);
  if (tuitionMatch && (method === 'PATCH' || method === 'PUT')) {
    if (!authUser || authUser.role === 'student') {
      return { status: 403, body: { success: false, data: null, error: { code: 'FORBIDDEN', message: 'دسترسی فقط برای پشتیبان و ادمین مجاز است' } } };
    }
    const id = parseInt(tuitionMatch[1], 10);
    const sub = (ctx.subscriptions || []).find((s) => s.id === id);
    if (!sub) {
      return { status: 404, body: { success: false, data: null, error: { code: 'SUBSCRIPTION_NOT_FOUND', message: 'اشتراک یافت نشد' } } };
    }

    if (body.tuition_status) {
      if (!['unpaid', 'paid', 'pending', 'exempt', 'partial'].includes(body.tuition_status)) {
        return { status: 422, body: { success: false, data: null, error: { code: 'VALIDATION_ERROR', message: 'وضعیت شهریه نامعتبر است' } } };
      }
      sub.tuition_status = body.tuition_status;
      if (body.tuition_status === 'paid' && !sub.last_payment_date) {
        sub.last_payment_date = new Date().toISOString().split('T')[0];
      }
    }
    if (body.monthly_fee !== undefined && body.monthly_fee !== '') {
      sub.monthly_fee = Number(body.monthly_fee);
    }
    if (body.tuition_due_date !== undefined) sub.tuition_due_date = body.tuition_due_date;
    if (body.last_payment_date !== undefined) sub.last_payment_date = body.last_payment_date;
    if (body.tuition_notes !== undefined) sub.tuition_notes = body.tuition_notes;
    sub.updated_at = new Date().toISOString();

    return {
      status: 200,
      body: { success: true, data: formatSubscription(sub, ctx), pagination: null, error: null },
    };
  }

  // 7. PATCH|PUT /api/v1/subscriptions/{id}/status
  const statusMatch = pathname.match(/^\/api\/v1\/subscriptions\/(\d+)\/status$/);
  if (statusMatch && (method === 'PATCH' || method === 'PUT')) {
    if (!authUser || authUser.role === 'student') {
      return { status: 403, body: { success: false, data: null, error: { code: 'FORBIDDEN', message: 'دسترسی فقط برای پشتیبان و ادمین مجاز است' } } };
    }
    const id = parseInt(statusMatch[1], 10);
    const sub = (ctx.subscriptions || []).find((s) => s.id === id);
    if (!sub) {
      return { status: 404, body: { success: false, data: null, error: { code: 'SUBSCRIPTION_NOT_FOUND', message: 'اشتراک یافت نشد' } } };
    }
    if (!body.status) {
      return { status: 422, body: { success: false, data: null, error: { code: 'VALIDATION_ERROR', message: 'وضعیت الزامی است' } } };
    }
    sub.status = body.status;
    if (body.note !== undefined) sub.note = body.note;
    sub.updated_at = new Date().toISOString();

    return {
      status: 200,
      body: { success: true, data: formatSubscription(sub, ctx), pagination: null, error: null },
    };
  }

  // 8. GET /api/v1/subscriptions/{id}
  const singleMatch = pathname.match(/^\/api\/v1\/subscriptions\/(\d+)$/);
  if (singleMatch && method === 'GET') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    const id = parseInt(singleMatch[1], 10);
    const sub = (ctx.subscriptions || []).find((s) => s.id === id);
    if (!sub) {
      return { status: 404, body: { success: false, data: null, error: { code: 'SUBSCRIPTION_NOT_FOUND', message: 'اشتراک یافت نشد' } } };
    }
    if (isStudent && sub.student_id !== currentStudentId) {
      return { status: 403, body: { success: false, data: null, error: { code: 'FORBIDDEN', message: 'دسترسی غیرمجاز' } } };
    }
    return {
      status: 200,
      body: { success: true, data: formatSubscription(sub, ctx), pagination: null, error: null },
    };
  }

  // 9. PUT /api/v1/subscriptions/{id}
  if (singleMatch && method === 'PUT') {
    if (!authUser || authUser.role === 'student') {
      return { status: 403, body: { success: false, data: null, error: { code: 'FORBIDDEN', message: 'دسترسی فقط برای پشتیبان و ادمین مجاز است' } } };
    }
    const id = parseInt(singleMatch[1], 10);
    const sub = (ctx.subscriptions || []).find((s) => s.id === id);
    if (!sub) {
      return { status: 404, body: { success: false, data: null, error: { code: 'SUBSCRIPTION_NOT_FOUND', message: 'اشتراک یافت نشد' } } };
    }
    if (body.status !== undefined) sub.status = body.status;
    if (body.is_active !== undefined) sub.is_active = Number(body.is_active);
    if (body.monthly_fee !== undefined) sub.monthly_fee = Number(body.monthly_fee);
    if (body.tuition_status !== undefined) sub.tuition_status = body.tuition_status;
    if (body.tuition_due_date !== undefined) sub.tuition_due_date = body.tuition_due_date;
    if (body.last_payment_date !== undefined) sub.last_payment_date = body.last_payment_date;
    if (body.tuition_notes !== undefined) sub.tuition_notes = body.tuition_notes;
    if (body.note !== undefined) sub.note = body.note;
    sub.updated_at = new Date().toISOString();

    return {
      status: 200,
      body: { success: true, data: formatSubscription(sub, ctx), pagination: null, error: null },
    };
  }

  // 10. DELETE /api/v1/subscriptions/{id}
  if (singleMatch && method === 'DELETE') {
    if (!authUser) {
      return { status: 401, body: { success: false, data: null, error: { code: 'UNAUTHORIZED', message: 'احراز هویت الزامی است' } } };
    }
    const id = parseInt(singleMatch[1], 10);
    const subIdx = (ctx.subscriptions || []).findIndex((s) => s.id === id);
    if (subIdx === -1) {
      return { status: 404, body: { success: false, data: null, error: { code: 'SUBSCRIPTION_NOT_FOUND', message: 'اشتراک یافت نشد' } } };
    }
    const sub = ctx.subscriptions[subIdx];
    if (isStudent && (sub.student_id !== currentStudentId || sub.status !== 'pending')) {
      return { status: 400, body: { success: false, data: null, error: { code: 'CANNOT_DELETE_SUBSCRIPTION', message: 'فقط اشتراک‌های در انتظار تایید توسط دانش‌آموز قابل لغو هستند' } } };
    }
    ctx.subscriptions.splice(subIdx, 1);
    return {
      status: 200,
      body: { success: true, data: null, pagination: null, error: null },
    };
  }

  return {
    status: 404,
    body: { success: false, data: null, pagination: null, error: { code: 'NOT_FOUND', message: 'مسیر یافت نشد' } },
  };
}

const REQUIRED_PHP_FILES = [
  'openapi.yaml',
  'public/openapi.yaml',
  'README.md',
  'database/schema.postgres.sql',
  'database/migrations/008_tutoring_classroom_audit_log.sql',
  'database/migrations/009_staff_activity_logs.sql',
  'database/migrations/010_supporter_description.sql',
  'database/migrations/011_subscription_tuition.sql',
  'database/migrations/012_sms_pattern_settings.sql',
  'config/database.php',
  'app/Core/Database.php',
  'app/Core/Migrator.php',
  'app/Core/PhoneNumber.php',
  'app/Core/RedisClient.php',
  'app/Core/RedisMonitorMiddleware.php',
  'app/Core/Cache.php',
  'app/Core/PublicCacheMiddleware.php',
  'app/Core/RateLimiter.php',
  'app/Core/RateLimitMiddleware.php',
  'app/Core/JwtRevocation.php',
  'config/rate_limits.php',
  'app/Core/SmsPatternSettings.php',
  'app/Core/SmsService.php',
  'app/Modules/Settings/SmsSettingsController.php',
  'app/Modules/Appointments/AppointmentService.php',
  'app/Modules/Appointments/AppointmentController.php',
  'app/Modules/ActivityLogs/ActivityLog.php',
  'app/Modules/ActivityLogs/ActivityLogController.php',
  'app/Modules/Tutoring/Tutoring.php',
  'app/Modules/Tutoring/TutoringService.php',
  'app/Modules/Tutoring/TutoringController.php',
  'app/Modules/Subscription/Subscription.php',
  'app/Modules/Subscription/SubscriptionService.php',
  'app/Modules/Subscription/SubscriptionController.php',
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

      <section class="panel" id="classroom-audit-panel">
        <div class="panel-header">
          <div class="panel-title">Tutoring Classroom Configuration & Audit Log (<code>/api/v1/tutoring/classrooms/audit-logs</code>)</div>
          <span id="audit-log-count" style="font-size: 0.75rem; color: var(--emerald);">1 audit entry</span>
        </div>
        <div class="panel-body">
          <div class="controls-row" style="gap: 0.6rem;">
            <label for="select-audit-actor">
              Acting Identity:
              <select id="select-audit-actor" aria-label="Acting Identity" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.35rem 0.55rem; font-size: 0.78rem;">
                <option value="admin:1:0">Admin (actor_user_id: 1)</option>
                <option value="teacher:4:30">Instructor #30 (actor_user_id: 4, instructor_id: 30)</option>
              </select>
            </label>
            <label for="input-cls-name">
              Class Name:
              <input id="input-cls-name" aria-label="Class Name" type="text" value="کلاس زیست پیشرفته" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 9.5rem;" />
            </label>
            <label for="input-cls-teacher">
              Teacher:
              <input id="input-cls-teacher" aria-label="Teacher Name" type="text" value="دکتر محسنی" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 8.5rem;" />
            </label>
            <label for="input-cls-field">
              Field:
              <input id="input-cls-field" aria-label="Field" type="text" value="تجربی" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 5.5rem;" />
            </label>
            <button id="btn-create-classroom" class="primary">Add Classroom</button>
          </div>

          <div id="classroom-list-container" style="display: flex; flex-direction: column; gap: 0.45rem;"></div>

          <div style="overflow-x: auto;">
            <table class="redis-table" aria-label="Classroom Configuration Audit Logs">
              <thead>
                <tr>
                  <th>Timestamp (UTC)</th>
                  <th>Admin / Instructor ID</th>
                  <th>Action</th>
                  <th>Classroom</th>
                  <th>Specific Changes Made</th>
                </tr>
              </thead>
              <tbody id="audit-logs-tbody">
                <tr><td colspan="5" style="color: var(--muted);">Loading audit logs...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="panel" id="staff-activity-monitor">
        <div class="panel-header">
          <div class="panel-title">Admin Panel — Staff Activity Monitor (<code>/api/v1/admin/activity-logs</code>)</div>
          <span id="staff-activity-count" style="font-size: 0.75rem; color: var(--emerald);">3 activities</span>
        </div>
        <div class="panel-body">
          <div class="metrics-bar" id="staff-role-metrics">
            <div class="metric-box">
              <div class="metric-label">Total Staff Actions</div>
              <div class="metric-val" id="stat-total-actions">3</div>
            </div>
            <div class="metric-box">
              <div class="metric-label">Admins (ادمین‌ها)</div>
              <div class="metric-val val-hit" id="stat-admin-actions">1</div>
            </div>
            <div class="metric-box">
              <div class="metric-label">Supporters (پشتیبان‌ها)</div>
              <div class="metric-val val-rate" id="stat-supporter-actions">1</div>
            </div>
            <div class="metric-box">
              <div class="metric-label">Teachers (اساتید)</div>
              <div class="metric-val" style="color: #a78bfa;" id="stat-teacher-actions">1</div>
            </div>
          </div>

          <div class="controls-row" style="gap: 0.6rem; flex-wrap: wrap;">
            <label for="filter-activity-role">
              Role Filter:
              <select id="filter-activity-role" aria-label="Filter Staff Role" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.35rem 0.55rem; font-size: 0.78rem;">
                <option value="">All Roles (همه نقش‌ها)</option>
                <option value="admin">Admin (ادمین)</option>
                <option value="supporter">Supporter (پشتیبان)</option>
                <option value="teacher">Teacher (استاد / مدرس)</option>
              </select>
            </label>
            <label for="filter-activity-module">
              Module:
              <select id="filter-activity-module" aria-label="Filter Activity Module" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.35rem 0.55rem; font-size: 0.78rem;">
                <option value="">All Modules (همه بخش‌ها)</option>
                <option value="appointments">appointments (نوبت‌دهی)</option>
                <option value="tutoring">tutoring (رفع اشکال)</option>
                <option value="exams">exams (آزمون و کارنامه)</option>
                <option value="attendance">attendance (حضور و غیاب)</option>
                <option value="students">students (دانش‌آموزان)</option>
              </select>
            </label>
            <label for="filter-activity-q">
              Search:
              <input id="filter-activity-q" aria-label="Search Staff Activities" type="text" placeholder="جستجو در نام یا شرح فعالیت..." style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 11rem;" />
            </label>
            <button id="btn-sim-supporter" aria-label="Simulate Supporter Action">Simulate Supporter Action</button>
            <button id="btn-sim-teacher" aria-label="Simulate Teacher Action">Simulate Teacher Action</button>
          </div>

          <div style="overflow-x: auto;">
            <table class="redis-table" aria-label="Staff Activity Logs">
              <thead>
                <tr>
                  <th>Time (UTC)</th>
                  <th>Staff Member & Role</th>
                  <th>Module</th>
                  <th>Method & Endpoint</th>
                  <th>Activity Description (هر کس چه کاری انجام داد)</th>
                </tr>
              </thead>
              <tbody id="staff-activity-tbody">
                <tr><td colspan="5" style="color: var(--muted);">Loading staff activity logs...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="panel" id="subscription-tuition-panel">
        <div class="panel-header">
          <div class="panel-title">ثبت‌نام دوره، شهریه ماهانه و وضعیت پرداخت شهریه (<code>/api/v1/subscriptions</code>)</div>
          <span id="subscription-count" style="font-size: 0.75rem; color: var(--emerald);">1 subscription</span>
        </div>
        <div class="panel-body">
          <div class="controls-row" style="gap: 0.6rem; flex-wrap: wrap;">
            <label for="select-sub-student">
              Student:
              <select id="select-sub-student" aria-label="Select Student" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.35rem 0.55rem; font-size: 0.78rem;">
                <option value="20">دانش‌آموز نمونه (#20)</option>
              </select>
            </label>
            <label for="select-sub-course">
              Course:
              <select id="select-sub-course" aria-label="Select Course" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.35rem 0.55rem; font-size: 0.78rem;">
                <option value="101" data-fee="450000">ریاضیات جامع کنکور (#101 - ماهانه ۴۵۰,۰۰۰ تومان)</option>
                <option value="102" data-fee="420000">فیزیک جامع کنکور (#102 - ماهانه ۴۲۰,۰۰۰ تومان)</option>
                <option value="103" data-fee="390000">شیمی جامع کنکور (#103 - ماهانه ۳۹۰,۰۰۰ تومان)</option>
              </select>
            </label>
            <label for="input-sub-fee">
              Monthly Fee (شهریه ماهانه):
              <input id="input-sub-fee" aria-label="Monthly Fee" type="number" value="450000" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 8.5rem;" />
            </label>
            <label for="select-sub-tuition-status">
              Tuition Status:
              <select id="select-sub-tuition-status" aria-label="Tuition Status" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.35rem 0.55rem; font-size: 0.78rem;">
                <option value="unpaid">پرداخت نشده (unpaid)</option>
                <option value="paid">پرداخت شده (paid)</option>
                <option value="pending">در انتظار تایید (pending)</option>
                <option value="exempt">معاف از شهریه (exempt)</option>
                <option value="partial">پرداخت ناقص / بیعانه (partial)</option>
              </select>
            </label>
            <button id="btn-enroll-course" class="primary" style="font-weight: 600;">Enroll in Course (ثبت‌نام دانش‌آموز)</button>
          </div>

          <div style="overflow-x: auto;">
            <table class="redis-table" aria-label="Course Subscriptions and Tuition">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Student (شاگرد)</th>
                  <th>Course (دوره)</th>
                  <th>Monthly Tuition (شهریه ماهانه)</th>
                  <th>Tuition Status (وضعیت شهریه)</th>
                  <th>Due Date (سررسید)</th>
                  <th>Last Payment</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="subscriptions-tbody">
                <tr><td colspan="8" style="color: var(--muted);">Loading subscriptions...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="panel" id="sms-pattern-settings-panel">
        <div class="panel-header">
          <div class="panel-title">تنظیمات کد پترن‌های ملی‌پیامک و پیش‌نمایش پیامک نوبت (<code>/api/v1/admin/sms/patterns</code>)</div>
          <span id="sms-appt-status-badge" style="font-size: 0.75rem; color: var(--emerald);">پترن نوبت مستقل از لاگین ۲مرحله‌ای</span>
        </div>
        <div class="panel-body">
          <div class="controls-row" style="gap: 0.65rem; flex-wrap: wrap;">
            <label for="input-sms-auth-body">
              Auth 2FA BodyId:
              <input id="input-sms-auth-body" aria-label="Auth 2FA BodyId" type="number" min="1" placeholder="مثلاً 500001" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 7.5rem;" />
            </label>
            <label for="input-sms-appt-body">
              Appointment BodyId (نوبت):
              <input id="input-sms-appt-body" aria-label="Appointment BodyId" type="number" min="1" placeholder="کد پترن نوبت" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 8rem;" />
            </label>
            <label for="input-sms-exam-body">
              Exam Result BodyId (کارنامه):
              <input id="input-sms-exam-body" aria-label="Exam Result BodyId" type="number" min="1" placeholder="کد پترن کارنامه" style="background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 0.35rem; padding: 0.32rem 0.55rem; font-size: 0.78rem; width: 8rem;" />
            </label>
            <button id="btn-save-sms-patterns" class="primary">ذخیره کد پترن‌ها</button>
          </div>
          <div id="sms-appt-template-preview" style="margin-top: 0.65rem; padding: 0.65rem 0.8rem; background: var(--bg); border: 1px solid var(--border); border-radius: 0.45rem; font-size: 0.78rem; color: var(--muted); direction: rtl; text-align: right;">
            در حال دریافت اطلاعات پترن پیامک نوبت...
          </div>
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

    function getActorHeader() {
      const val = document.getElementById('select-audit-actor').value || 'admin:1:0';
      const [role, sub, instId] = val.split(':');
      return JSON.stringify({
        role,
        sub: Number(sub),
        instructor_id: Number(instId) > 0 ? Number(instId) : null,
      });
    }

    async function refreshClassroomsAndAuditLogs() {
      const headers = { 'X-Demo-Actor': getActorHeader() };
      const [clsRes, logRes] = await Promise.all([
        fetch('/api/v1/tutoring/classrooms', { headers }),
        fetch('/api/v1/tutoring/classrooms/audit-logs', { headers }),
      ]);
      const clsJson = await clsRes.json();
      const logJson = await logRes.json();

      const classrooms = clsJson.data || [];
      const logs = logJson.data || [];

      document.getElementById('audit-log-count').textContent =
        logs.length + (logs.length === 1 ? ' audit entry' : ' audit entries');

      const clsContainer = document.getElementById('classroom-list-container');
      if (classrooms.length === 0) {
        clsContainer.innerHTML = '<div style="font-size: 0.78rem; color: var(--muted);">No active classrooms. Add one above.</div>';
      } else {
        clsContainer.innerHTML = classrooms.map(c => \`
          <div class="endpoint-row" data-classroom-id="\${c.id}">
            <div class="endpoint-meta">
              <span class="method-tag">#\${c.id}</span>
              <div>
                <div class="endpoint-path">\${c.name} <span style="color: var(--muted);">(\${c.field || '—'})</span></div>
                <div class="endpoint-desc">Teacher: \${c.teacher_name || '—'} · Instructor ID: \${c.instructor_id}</div>
              </div>
            </div>
            <div style="display: flex; gap: 0.4rem;">
              <button data-edit-cls="\${c.id}" aria-label="Rename Classroom #\${c.id}">Update Config</button>
              <button class="danger" data-delete-cls="\${c.id}" aria-label="Delete Classroom #\${c.id}">Delete</button>
            </div>
          </div>
        \`).join('');
      }

      clsContainer.querySelectorAll('[data-edit-cls]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-edit-cls');
          const nameInput = document.getElementById('input-cls-name').value.trim();
          const teacherInput = document.getElementById('input-cls-teacher').value.trim();
          const fieldInput = document.getElementById('input-cls-field').value.trim();
          await fetch('/api/v1/tutoring/classrooms/' + id, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'X-Demo-Actor': getActorHeader(),
            },
            body: JSON.stringify({
              name: nameInput || 'کلاس ویرایش‌شده',
              teacher_name: teacherInput,
              field: fieldInput,
            }),
          });
          await refreshClassroomsAndAuditLogs();
          await refreshStaffActivityLogs();
        });
      });

      clsContainer.querySelectorAll('[data-delete-cls]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-delete-cls');
          await fetch('/api/v1/tutoring/classrooms/' + id, {
            method: 'DELETE',
            headers: { 'X-Demo-Actor': getActorHeader() },
          });
          await refreshClassroomsAndAuditLogs();
          await refreshStaffActivityLogs();
        });
      });

      const auditTbody = document.getElementById('audit-logs-tbody');
      if (logs.length === 0) {
        auditTbody.innerHTML = '<tr><td colspan="5" style="color: var(--muted);">No audit entries recorded.</td></tr>';
      } else {
        auditTbody.innerHTML = logs.map(l => {
          const diffEntries = Object.entries(l.changes?.diff || {})
            .map(([k, v]) => \`\${k}: \${JSON.stringify(v.from)} → \${JSON.stringify(v.to)}\`)
            .join(' | ');
          const actorBadge = l.actor_instructor_id
            ? \`Instructor #\${l.actor_instructor_id} (User #\${l.actor_user_id})\`
            : \`Admin #\${l.actor_user_id} (\${l.actor_role})\`;
          return \`
            <tr>
              <td style="white-space: nowrap;">\${l.timestamp}</td>
              <td style="color: var(--accent);">\${actorBadge}</td>
              <td><span class="method-tag">\${l.action.toUpperCase()}</span></td>
              <td>#\${l.classroom_id}</td>
              <td>
                <div style="font-weight: 500; color: var(--text);">\${l.summary}</div>
                <div style="color: var(--muted); font-size: 0.7rem;">\${diffEntries}</div>
              </td>
            </tr>
          \`;
        }).join('');
      }
    }

    document.getElementById('btn-create-classroom').addEventListener('click', async () => {
      const name = document.getElementById('input-cls-name').value.trim();
      const teacher_name = document.getElementById('input-cls-teacher').value.trim();
      const field = document.getElementById('input-cls-field').value.trim();
      await fetch('/api/v1/tutoring/classrooms', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Demo-Actor': getActorHeader(),
        },
        body: JSON.stringify({ name, class_name: name, teacher_name, field }),
      });
      await refreshClassroomsAndAuditLogs();
      await refreshStaffActivityLogs();
    });

    async function refreshStaffActivityLogs() {
      const role = document.getElementById('filter-activity-role').value;
      const mod = document.getElementById('filter-activity-module').value;
      const q = document.getElementById('filter-activity-q').value.trim();

      const params = new URLSearchParams();
      if (role) params.set('role', role);
      if (mod) params.set('module', mod);
      if (q) params.set('q', q);

      const adminHeader = { 'X-Demo-Actor': JSON.stringify({ role: 'admin', sub: 1 }) };
      const [listRes, statsRes] = await Promise.all([
        fetch('/api/v1/admin/activity-logs?' + params.toString(), { headers: adminHeader }),
        fetch('/api/v1/admin/activity-logs/stats', { headers: adminHeader }),
      ]);
      const listJson = await listRes.json();
      const statsJson = await statsRes.json();

      const items = listJson.data || [];
      const stats = statsJson.data || { total_logs: 0, by_role: { admin: 0, supporter: 0, teacher: 0 } };

      document.getElementById('staff-activity-count').textContent =
        items.length + (items.length === 1 ? ' activity' : ' activities');
      document.getElementById('stat-total-actions').textContent = String(stats.total_logs ?? items.length);
      document.getElementById('stat-admin-actions').textContent = String(stats.by_role?.admin ?? 0);
      document.getElementById('stat-supporter-actions').textContent = String(stats.by_role?.supporter ?? 0);
      document.getElementById('stat-teacher-actions').textContent = String(stats.by_role?.teacher ?? 0);

      const tbody = document.getElementById('staff-activity-tbody');
      if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="color: var(--muted);">هیچ فعالیتی مطابق فیلتر انتخاب‌شده یافت نشد.</td></tr>';
      } else {
        tbody.innerHTML = items.map(item => \`
          <tr>
            <td style="white-space: nowrap;">\${item.timestamp}</td>
            <td>
              <div style="font-weight: 600; color: var(--text);">\${item.actor_name || ('User #' + item.actor_user_id)}</div>
              <div style="font-size: 0.7rem; color: var(--accent);">\${item.actor_role_label} (\${item.actor_role}) · ID #\${item.actor_user_id}</div>
            </td>
            <td><span class="method-tag">\${item.module}</span></td>
            <td style="font-size: 0.72rem; color: var(--muted);">\${item.http_method} \${item.endpoint}</td>
            <td style="font-weight: 500; color: var(--text);">\${item.summary}</td>
          </tr>
        \`).join('');
      }
    }

    document.getElementById('filter-activity-role').addEventListener('change', refreshStaffActivityLogs);
    document.getElementById('filter-activity-module').addEventListener('change', refreshStaffActivityLogs);
    document.getElementById('filter-activity-q').addEventListener('input', refreshStaffActivityLogs);

    document.getElementById('btn-sim-supporter').addEventListener('click', async () => {
      await fetch('/api/v1/admin/activity-logs/record', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Demo-Actor': JSON.stringify({ role: 'supporter', sub: 2, linked_id: 10 }),
        },
        body: JSON.stringify({
          module: 'appointments',
          action: 'update',
          target_type: 'appointment',
          target_id: '29',
          summary: 'ویرایش نوبت مشاوره #29 برای دانش‌آموز نمونه',
          http_method: 'PUT',
          endpoint: '/api/v1/appointments/29',
          status_code: 200,
        }),
      });
      await refreshStaffActivityLogs();
    });

    document.getElementById('btn-sim-teacher').addEventListener('click', async () => {
      await fetch('/api/v1/admin/activity-logs/record', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Demo-Actor': JSON.stringify({ role: 'teacher', sub: 4, instructor_id: 30 }),
        },
        body: JSON.stringify({
          module: 'tutoring',
          action: 'start_session',
          target_type: 'tutoring_session',
          target_id: '14',
          summary: 'ثبت ورود دانش‌آموز #20 به جلسه رفع اشکال',
          http_method: 'POST',
          endpoint: '/api/v1/tutoring/sessions',
          status_code: 201,
        }),
      });
      await refreshStaffActivityLogs();
    });

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

    async function refreshSubscriptions() {
      const tbody = document.getElementById('subscriptions-tbody');
      try {
        const res = await fetch('/api/v1/subscriptions', {
          headers: { 'X-Demo-Actor': getActorHeader() },
        });
        const json = await res.json();
        if (!json.success || !json.data) return;

        const subs = json.data;
        document.getElementById('subscription-count').textContent = \`\${subs.length} subscription\${subs.length !== 1 ? 's' : ''}\`;

        if (subs.length === 0) {
          tbody.innerHTML = '<tr><td colspan="8" style="color: var(--muted); text-align: center;">هیچ اشتراکی یافت نشد</td></tr>';
          return;
        }

        const badgeStyles = {
          paid: 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4);',
          unpaid: 'background: rgba(244, 63, 94, 0.15); color: #fda4af; border: 1px solid rgba(244, 63, 94, 0.4);',
          pending: 'background: rgba(245, 158, 11, 0.15); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.4);',
          exempt: 'background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.4);',
          partial: 'background: rgba(56, 189, 248, 0.15); color: #7dd3fc; border: 1px solid rgba(56, 189, 248, 0.4);',
        };

        tbody.innerHTML = subs
          .map((sub) => {
            const feeFormatted = (sub.monthly_fee || 0).toLocaleString('fa-IR') + ' تومان';
            const badgeStyle = badgeStyles[sub.tuition_status] || badgeStyles.unpaid;
            const nextStatus = sub.tuition_status === 'paid' ? 'unpaid' : 'paid';
            const nextLabel = sub.tuition_status === 'paid' ? 'علامت به پرداخت نشده' : 'علامت به پرداخت شده ✓';

            return \`<tr>
              <td style="font-family: var(--font-mono); font-weight: 600;">#\${sub.id}</td>
              <td>\${sub.student ? sub.student.name : 'شاگرد #' + sub.student_id}</td>
              <td style="font-weight: 500;">\${sub.course ? sub.course.name : 'دوره #' + sub.course_id}</td>
              <td style="font-family: var(--font-mono); color: var(--emerald); font-weight: 600;">\${feeFormatted}</td>
              <td>
                <span style="display: inline-block; padding: 0.15rem 0.5rem; border-radius: 0.25rem; font-size: 0.74rem; font-weight: 600; \${badgeStyle}">
                  \${sub.tuition_status_label || sub.tuition_status}
                </span>
              </td>
              <td style="font-family: var(--font-mono); font-size: 0.74rem; color: var(--muted);">\${sub.tuition_due_date || '—'}</td>
              <td style="font-family: var(--font-mono); font-size: 0.74rem; color: var(--muted);">\${sub.last_payment_date || '—'}</td>
              <td>
                <button onclick="updateTuitionStatus(\${sub.id}, '\${nextStatus}')" style="font-size: 0.72rem; padding: 0.2rem 0.45rem;">
                  \${nextLabel}
                </button>
              </td>
            </tr>\`;
          })
          .join('');
      } catch (e) {
        tbody.innerHTML = \`<tr><td colspan="8" style="color: var(--rose);">خطا در دریافت لیست اشتراک‌ها: \${e.message}</td></tr>\`;
      }
    }

    window.updateTuitionStatus = async function(id, status) {
      try {
        await fetch(\`/api/v1/subscriptions/\${id}/tuition\`, {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            'X-Demo-Actor': getActorHeader(),
          },
          body: JSON.stringify({ tuition_status: status }),
        });
        await refreshSubscriptions();
      } catch (e) {
        console.error('Failed to update tuition status', e);
      }
    };

    document.getElementById('select-sub-course').addEventListener('change', (e) => {
      const opt = e.target.options[e.target.selectedIndex];
      if (opt && opt.dataset.fee) {
        document.getElementById('input-sub-fee').value = opt.dataset.fee;
      }
    });

    document.getElementById('btn-enroll-course').addEventListener('click', async () => {
      const studentId = parseInt(document.getElementById('select-sub-student').value, 10);
      const courseId = parseInt(document.getElementById('select-sub-course').value, 10);
      const monthlyFee = parseInt(document.getElementById('input-sub-fee').value, 10);
      const tuitionStatus = document.getElementById('select-sub-tuition-status').value;

      try {
        const res = await fetch(\`/api/v1/courses/\${courseId}/enroll\`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Demo-Actor': getActorHeader(),
          },
          body: JSON.stringify({
            student_id: studentId,
            monthly_fee: monthlyFee,
            tuition_status: tuitionStatus,
          }),
        });
        const json = await res.json();
        if (!json.success && json.error) {
          alert('خطا: ' + json.error.message);
        } else {
          await refreshSubscriptions();
        }
      } catch (e) {
        console.error('Enroll failed', e);
      }
    });

    async function refreshSmsPatternsAndPreview() {
      const adminHeader = { 'X-Demo-Actor': JSON.stringify({ role: 'admin', sub: 1 }) };
      try {
        const [patRes, prevRes] = await Promise.all([
          fetch('/api/v1/admin/sms/patterns', { headers: adminHeader }),
          fetch('/api/v1/appointments/sms/preview?week_date=2026-10-24', { headers: adminHeader }),
        ]);
        const patJson = await patRes.json();
        const prevJson = await prevRes.json();
        if (patJson.success && patJson.data) {
          document.getElementById('input-sms-auth-body').value = patJson.data.auth_body_id || '';
          document.getElementById('input-sms-appt-body').value = patJson.data.appointment_body_id || '';
          document.getElementById('input-sms-exam-body').value = patJson.data.exam_body_id || '';
          const apptPat = patJson.data.patterns?.appointment_reminder;
          const badge = document.getElementById('sms-appt-status-badge');
          if (apptPat?.is_configured) {
            badge.textContent = \`پترن نوبت فعال: #\${apptPat.body_id} (منبع: \${apptPat.source})\`;
            badge.style.color = 'var(--emerald)';
          } else {
            badge.textContent = 'کد پترن نوبت تنظیم نشده (ارسال متوقف می‌شود)';
            badge.style.color = 'var(--amber)';
          }
        }
        const previewEl = document.getElementById('sms-appt-template-preview');
        const firstSample = (prevJson.data?.items || []).find((i) => i.sms_preview_text);
        if (firstSample) {
          previewEl.textContent = \`نمونه متن ارسالی به والد (\${firstSample.student_name}): \${firstSample.sms_preview_text}\`;
        } else if (prevJson.data?.pattern?.template) {
          previewEl.textContent = \`الگوی نوبت: \${prevJson.data.pattern.template}\`;
        }
      } catch (e) {
        console.error('Failed to load SMS pattern settings', e);
      }
    }

    document.getElementById('btn-save-sms-patterns').addEventListener('click', async () => {
      const authVal = document.getElementById('input-sms-auth-body').value.trim();
      const apptVal = document.getElementById('input-sms-appt-body').value.trim();
      const examVal = document.getElementById('input-sms-exam-body').value.trim();
      const res = await fetch('/api/v1/admin/sms/patterns', {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-Demo-Actor': JSON.stringify({ role: 'admin', sub: 1 }),
        },
        body: JSON.stringify({
          auth_body_id: authVal ? Number(authVal) : null,
          appointment_body_id: apptVal ? Number(apptVal) : null,
          exam_body_id: examVal ? Number(examVal) : null,
        }),
      });
      const json = await res.json();
      if (json.success) {
        await refreshSmsPatternsAndPreview();
      }
    });

    // Run initial requests on load so state is populated immediately
    callPublicEndpoint('/api/v1/public/courses');
    refreshClassroomsAndAuditLogs();
    refreshStaffActivityLogs();
    refreshSubscriptions();
    refreshSmsPatternsAndPreview();
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

    if (pathname.startsWith('/api/v1/tutoring/classrooms')) {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        let parsedBody = {};
        if (raw) {
          try {
            parsedBody = JSON.parse(raw);
          } catch {
            parsedBody = {};
          }
        }
        let actor = { sub: 1, role: 'admin', instructor_id: null };
        const rawActor = req.headers['x-demo-actor'];
        if (typeof rawActor === 'string' && rawActor) {
          try {
            actor = JSON.parse(rawActor);
          } catch {
            // keep default admin actor
          }
        }
        const result = handleTutoringClassroomEndpoint(ctx, method, urlObj, parsedBody, actor);
        res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(result.body));
      });
      return;
    }

    if (
      pathname.startsWith('/api/v1/admin/activity-logs') ||
      pathname.startsWith('/api/v1/admin/stats/activity-logs')
    ) {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        let parsedBody = {};
        if (raw) {
          try {
            parsedBody = JSON.parse(raw);
          } catch {
            parsedBody = {};
          }
        }
        let actor = { sub: 1, role: 'admin', instructor_id: null };
        const rawActor = req.headers['x-demo-actor'];
        if (typeof rawActor === 'string' && rawActor) {
          try {
            actor = JSON.parse(rawActor);
          } catch {
            // keep default admin actor
          }
        }
        const result = handleAdminActivityLogEndpoint(ctx, method, urlObj, parsedBody, actor);
        res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(result.body));
      });
      return;
    }

    if (pathname === '/api/v1/admin/sms/patterns') {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        let parsedBody = {};
        if (raw) {
          try {
            parsedBody = JSON.parse(raw);
          } catch {
            parsedBody = {};
          }
        }
        let actor = { sub: 1, role: 'admin', instructor_id: null };
        const rawActor = req.headers['x-demo-actor'];
        if (typeof rawActor === 'string' && rawActor) {
          try {
            actor = JSON.parse(rawActor);
          } catch {
            // keep default admin actor
          }
        }
        const result = handleSmsPatternEndpoint(ctx, method, urlObj, parsedBody, actor);
        res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(result.body));
      });
      return;
    }

    if (pathname.startsWith('/api/v1/appointments/sms/')) {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        let parsedBody = {};
        if (raw) {
          try {
            parsedBody = JSON.parse(raw);
          } catch {
            parsedBody = {};
          }
        }
        let actor = { sub: 1, role: 'admin', instructor_id: null };
        const rawActor = req.headers['x-demo-actor'];
        if (typeof rawActor === 'string' && rawActor) {
          try {
            actor = JSON.parse(rawActor);
          } catch {
            // keep default admin actor
          }
        }
        const result = handleAppointmentSmsEndpoint(ctx, method, urlObj, parsedBody, actor);
        res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(result.body));
      });
      return;
    }

    if (pathname === '/api/v1/supporters' || pathname.startsWith('/api/v1/supporters/')) {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        let parsedBody = {};
        if (raw) {
          try {
            parsedBody = JSON.parse(raw);
          } catch {
            parsedBody = {};
          }
        }
        let actor = { sub: 1, role: 'admin', instructor_id: null };
        const rawActor = req.headers['x-demo-actor'];
        if (typeof rawActor === 'string' && rawActor) {
          try {
            actor = JSON.parse(rawActor);
          } catch {
            // keep default admin actor
          }
        }
        const result = handleSupporterEndpoint(ctx, method, urlObj, parsedBody, actor);
        res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(result.body));
      });
      return;
    }

    if (
      pathname.startsWith('/api/v1/subscriptions') ||
      pathname.match(/^\/api\/v1\/courses\/(\d+)\/enroll$/) ||
      pathname.match(/^\/api\/v1\/students\/(\d+)\/subscriptions$/)
    ) {
      let raw = '';
      req.on('data', (chunk) => {
        raw += chunk;
      });
      req.on('end', () => {
        let parsedBody = {};
        if (raw) {
          try {
            parsedBody = JSON.parse(raw);
          } catch {
            parsedBody = {};
          }
        }
        let actor = { sub: 1, role: 'admin', instructor_id: null };
        const rawActor = req.headers['x-demo-actor'];
        if (typeof rawActor === 'string' && rawActor) {
          try {
            actor = JSON.parse(rawActor);
          } catch {
            // keep default admin actor
          }
        }
        const result = handleSubscriptionEndpoint(ctx, method, urlObj, parsedBody, actor);
        res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(result.body));
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
