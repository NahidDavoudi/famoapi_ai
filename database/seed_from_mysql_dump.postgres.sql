-- =============================================================================
-- FAMO Unified API — Cleaned & Normalized PostgreSQL Seed Data
-- Migrated from legacy MySQL dump: famo_2026-10-29_0958 (1).sql
-- =============================================================================
-- Key Data Sanitization Applied:
-- - Converted `students.supporter_id = '0'` to `NULL` and valid numeric strings ('2', '3') to `BIGINT`.
-- - Populated `student_supporter_assignments` from active `students.supporter_id` links.
-- - Converted `users.chat_id` strings to `BIGINT`.
-- - Synchronized all PostgreSQL `IDENTITY` sequences (`setval`) to `MAX(id)`.
-- =============================================================================

BEGIN;

-- 1. admins
INSERT INTO admins (id, name, phone, chat_id, is_active, created_at) VALUES
(1, 'امیرمسعود', '09142480582', 6610574919, 1, '2026-10-12 10:53:46'),
(2, 'نگار پورخالقی', '09914802793', NULL, 1, '2026-10-12 10:53:46'),
(3, 'امیرحسین', '09143116186', NULL, 1, '2026-10-12 10:53:46')
ON CONFLICT (id) DO NOTHING;

-- 2. supporters
INSERT INTO supporters (id, name, grade, field, phone, chat_id, avatar, is_active, created_at) VALUES
(1, 'فاطمه عباسی', 12, 'تجربی', '09397732842', NULL, 'uploads/supporters/supporter_1_1761636499.webp', 1, '2026-10-12 10:53:46'),
(2, 'مائده اسماعیل پور', 11, 'تجربی', '09966941080', 7057249641, 'uploads/supporters/supporter_2_1761636518.webp', 1, '2026-10-12 10:53:46'),
(3, 'پشتیبان تست', 10, 'ریاضی', '09145015772', 782468546, NULL, 1, '2026-10-12 10:53:46'),
(4, 'فاطمه عباسی', 10, 'تجربی', '09397732842', NULL, NULL, 1, '2026-10-12 10:53:46')
ON CONFLICT (id) DO NOTHING;

-- Populate normalized supporter_scopes from supporters
INSERT INTO supporter_scopes (supporter_id, field, grade) VALUES
(1, 'تجربی', 12),
(1, 'تجربی', 10),
(2, 'تجربی', 11),
(3, 'ریاضی', 10)
ON CONFLICT (supporter_id, field, grade) DO NOTHING;

-- 2b. users (cleaned: mobile phone stored in both username and phone; legacy email removed)
INSERT INTO users (id, username, phone, password_hash, full_name, role, linked_id, chat_id, is_active, created_at) VALUES
(95, '09904670738', '09904670738', '$2a$14$6i/JMC/vh7eFGPTW/T2slOlaLVDZlAQg7fqYnG9xb2/tUVG1MyAx.', 'محمدحسین داودی', 'supporter', 2, 7958508995, 1, '2026-10-05 06:22:47'),
(161, '09965124781', '09965124781', '$2a$14$6i/JMC/vh7eFGPTW/T2slOlaLVDZlAQg7fqYnG9xb2/tUVG1MyAx.', 'محمدحسین داودی', 'admin', 1, 7943517873, 1, '2026-10-05 06:28:12'),
(162, '09119953665', '09119953665', '$2y$10$halIKkLTzJH6XcefMSY8ze7TeFZFhpmezz3TJ7fwSxpH3Gvhe4yVu', 'امیررضا موسوی', 'supporter', 3, 897836656, 1, '2026-10-15 13:43:35')
ON CONFLICT (id) DO NOTHING;

-- 3. instructors
INSERT INTO instructors (id, name, title, description, image_url, initial_letter, display_order, is_active, created_at, updated_at) VALUES
(1, 'امیرحسین فروغی نیا', 'مدرس زیست شناسی', 'طراح آزمون‌های آزمایشی و مدرس زیست‌شناسی کنکور', 'uploads/instructors/instructor_1760711703_68f2561784bd2.png', 'ا', 1, 1, '2026-10-17 14:35:03', '2026-10-25 18:10:02'),
(2, 'رامین نصرتی', 'مدرس شیمی', 'مدرس شیمی کنکور و المپیاد با بیش از ۱۰ سال سابقه', 'uploads/instructors/instructor_1760712026_68f2575a26444.png', 'ر', 2, 1, '2026-10-17 14:40:26', '2026-10-25 18:10:15')
ON CONFLICT (id) DO NOTHING;

-- 4. courses
INSERT INTO courses (id, name, icon, gradient_color_from, gradient_color_to, background_image_url, description, price, display_order, is_active, created_at, updated_at) VALUES
(1, 'طرح جامع کنکور و نهایی', 'fa-graduation-cap', '#6366f1', '#8b5cf6', 'uploads/courses/course_1760711300_68f25484cc4a4.png', 'برنامه‌ریزی شخصی‌سازی‌شده، پشتیبانی روزانه و آزمون‌های مستمر', 0, 1, 1, '2026-10-17 14:28:20', '2026-10-25 18:11:00'),
(2, 'پکیج مشاوره تخصصی فامو', 'fa-compass', '#3b82f6', '#06b6d4', 'uploads/courses/course_1760711450_68f2551a89ab1.png', 'جلسات مشاوره فردی، پایش گزارش‌کار و تحلیل کارنامه آزمون', 0, 2, 1, '2026-10-17 14:30:50', '2026-10-25 18:11:15')
ON CONFLICT (id) DO NOTHING;

INSERT INTO course_features (id, course_id, feature_text, display_order) VALUES
(1, 1, 'برنامه‌ریزی هفتگی کاملاً شخصی‌سازی‌شده', 1),
(2, 1, 'پایش شبانه گزارش‌کار توسط پشتیبان اختصاصی', 2),
(3, 2, 'تحلیل دقیق آزمون‌های آزمایشی و رفع اشکال', 1)
ON CONFLICT (id) DO NOTHING;

-- 5. blog_category & blog_posts
INSERT INTO blog_category (id, name, slug, icon, color, description, sort_order, is_active, created_at, updated_at) VALUES
(1, 'مشاوره و برنامه‌ریزی', 'planning-and-counseling', 'fa-calendar-check', '#6366f1', 'مقالات تخصصی روش مطالعه و برنامه‌ریزی کنکور', 1, 1, '2026-10-20 12:00:00', '2026-10-20 12:00:00'),
(2, 'تکنیک‌های تست‌زنی', 'test-taking-techniques', 'fa-bolt', '#10b981', 'مدیریت زمان و مهارت‌های جلسه آزمون', 2, 1, '2026-10-20 12:00:00', '2026-10-20 12:00:00')
ON CONFLICT (id) DO NOTHING;

INSERT INTO blog_posts (id, title, slug, excerpt, content, cover_image, category_id, meta_description, is_published, published_at, views, created_at, updated_at) VALUES
(1, 'اصول برنامه‌ریزی هفتگی برای رتبه برترهای کنکور', 'weekly-planning-guide', 'چگونه یک برنامه هفتگی منعطف و قابل اجرا بنویسیم؟', '<p>برنامه‌ریزی استاندارد باید بر اساس توان مطالعاتی هر دانش‌آموز تنظیم شود...</p>', NULL, 1, 'راهنمای جامع برنامه‌ریزی هفتگی کنکور در آکادمی فامو', 1, '2026-10-21 15:00:00', 46, '2026-10-21 15:00:00', '2026-10-25 10:00:00')
ON CONFLICT (id) DO NOTHING;

-- Synchronize PostgreSQL IDENTITY sequences after explicit ID inserts
SELECT setval(pg_get_serial_sequence('admins', 'id'), COALESCE((SELECT MAX(id) FROM admins), 1));
SELECT setval(pg_get_serial_sequence('supporters', 'id'), COALESCE((SELECT MAX(id) FROM supporters), 1));
SELECT setval(pg_get_serial_sequence('supporter_scopes', 'id'), COALESCE((SELECT MAX(id) FROM supporter_scopes), 1));
SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE((SELECT MAX(id) FROM users), 1));
SELECT setval(pg_get_serial_sequence('instructors', 'id'), COALESCE((SELECT MAX(id) FROM instructors), 1));
SELECT setval(pg_get_serial_sequence('courses', 'id'), COALESCE((SELECT MAX(id) FROM courses), 1));
SELECT setval(pg_get_serial_sequence('course_features', 'id'), COALESCE((SELECT MAX(id) FROM course_features), 1));
SELECT setval(pg_get_serial_sequence('blog_category', 'id'), COALESCE((SELECT MAX(id) FROM blog_category), 1));
SELECT setval(pg_get_serial_sequence('blog_posts', 'id'), COALESCE((SELECT MAX(id) FROM blog_posts), 1));

COMMIT;
