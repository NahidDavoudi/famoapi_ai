# برنامه پیاده‌سازی فاز اول API بک‌اند Famo (Auth، Admins و Blog Categories)

این برنامه بر اساس بررسی دقیق کدهای فعلی و اسکیمای واقعی دیتابیس (`database/migrations/001` تا `005`) تنظیم شده و بدون تغییر معماری فعلی، صرفاً اندپوینت‌ها، مستندات و تست‌های فاز اول را در **۳ مرحله‌ی مجزا و با تأیید مرحله‌به‌مرحله** اضافه می‌کند.

---

## خلاصه بررسی معماری و اسکیمای واقعی پروژه

1. **معماری و پاکت پاسخ**: کنترلرها ورودی را اعتبارسنجی کرده و با سرویس/مدل کار می‌کنند. خروجی‌ها با `ResponseHelper::success($response, $data, $status, $pagination)` و خطاها با `ResponseHelper::error($response, $code, $message, $status, $details)` برگردانده می‌شوند.
2. **احراز هویت و میدلورها**: در `routes/api.php`، `$authMiddleware` توکن JWT را دیکود کرده و در `$request->getAttribute('user')` قرار می‌دهد (`sub` برابر `users.id` و `role` برابر نقش کاربر). `$requireAdmin` فقط نقش `admin` و `$requireSupporter` نقش‌های `supporter` و `admin` را مجاز می‌داند.
3. **نرمال‌سازی شماره موبایل و کش**: `PhoneNumber::normalizeOrFail($phone)` شماره‌های ایرانی را به فرمت ۱۱ رقمی `09xxxxxxxxx` تبدیل کرده و در صورت نامعتبر بودن، استثنای `422 VALIDATION_ERROR` پرتاب می‌کند. `Cache::flushGroup('public')` کش عمومی را پاکسازی می‌کند.
4. **جدول `users` و جداول نقش‌ها (`001_core_identity_and_users.sql`)**:
   - `users`: شامل `id`, `username` (یکتا), `phone`, `password_hash`, `full_name`, `role` (`admin|supporter|student|teacher`), `linked_id`, `chat_id`, `is_active` (`SMALLINT`).
   - `admins`: شامل `id`, `name`, `phone`, `chat_id`, `is_active`.
   - `supporters`: شامل `id`, `name`, `grade`, `field`, `phone`, `chat_id`, `avatar`, `is_active`.
   - `students`: شامل `id`, `name`, `national_id`, `grade`, `field`, `phone`, `chat_id`, `supporter_id`, `is_active`.
   - **نکته مهم اسکیمای `instructors` (نقش `teacher`)**: جدول `instructors` دارای ستون `name` است اما **ستون `phone` ندارد**. بنابراین در `PUT /api/v1/auth/profile` برای نقش `teacher`، نام در `instructors.name` و `users.full_name` همگام می‌شود و `phone` در `users.phone` ذخیره می‌گردد.
5. **بررسی Foreign Keyها برای حذف ادمین**:
   - هیچ جدولی FK مستقیم به `admins(id)` ندارد.
   - جداول دارای FK به `users(id)` در مایگریشن‌ها:
     - `login_codes.user_id` و `telegram_2fa_challenges.user_id` دارای `ON DELETE CASCADE` هستند.
     - `student_supporter_assignments.assigned_by`، `daily_attendance.removed_by`، `daily_attendance.created_by` و `tutoring_sessions.created_by` همگی دارای `ON DELETE SET NULL` هستند.
     - جدول `telegram_links` ستون‌های `(role, account_id)` دارد که در تراکنش حذف ادمین، رکورد `role = 'admin' AND account_id = :admin_id` نیز پاکسازی می‌شود تا داده یتیم باقی نماند.
6. **بررسی اسکیمای `blog_category` و `blog_posts` (`003_courses_blog_and_public.sql`)**:
   - ستون `blog_posts.category_id` در خط ۷۵ مایگریشن `003` به‌صورت `BIGINT DEFAULT NULL REFERENCES blog_category(id) ON DELETE SET NULL` تعریف شده است (نیازی به مایگریشن جدید نیست).
   - الگوی تولید `slug` در پروژه (`BlogPost::slugify` و `makeUniqueSlug`) با عبارت باقاعده `/[^\w\s\-]/u` و `mb_strtolower($text, 'UTF-8')` حروف فارسی و انگلیسی را حفظ کرده، فاصله‌ها را به `-` تبدیل می‌کند و در صورت تکراری بودن، پسوند عددی (`-1`, `-2`, ...) اضافه می‌کند.

---

## مرحله اول: تکمیل ماژول احراز هویت (Auth)

### ۱. تغییر رمز عبور (`POST /api/v1/auth/change-password`)
- **مسیر و دسترسی**: نیازمند `$authMiddleware`.
- **ورودی**: `current_password` (الزامی) و `new_password` (الزامی، حداقل ۴ کاراکتر؛ در غیر این صورت `422 VALIDATION_ERROR`).
- **منطق**:
  - واکشی کاربر فعلی از جدول `users` با `sub` توکن.
  - بررسی صحت `current_password` با `password_verify`؛ در صورت عدم تطابق، بازگشت خطای `401 Unauthorized` با کد `INVALID_PASSWORD`.
  - هش کردن `new_password` با ` PASSWORD_BCRYPT` و ذخیره در `users.password_hash`.

### ۲. ویرایش پروفایل کاربری (`PUT /api/v1/auth/profile`)
- **مسیر و دسترسی**: نیازمند `$authMiddleware`.
- **ورودی**: `full_name` (یا `name`، الزامی)، `phone` (الزامی) و `current_password` (الزامی در صورت تغییر شماره برای نقش‌های دارای 2FA).
- **منطق و امنیت**:
  - نرمال‌سازی شماره موبایل با `PhoneNumber::normalizeOrFail($phone)` (در صورت نامعتبر بودن ← `422 VALIDATION_ERROR`).
  - بررسی عدم تکرار شماره موبایل در سایر رکوردهای `users` (`WHERE phone = :phone AND id != :id`)؛ در صورت وجود ← `409 Conflict` با کد `DUPLICATE_PHONE`.
  - **گارد امنیتی 2FA**: اگر شماره موبایل جدید با شماره قبلی کاربر متفاوت باشد و نقش کاربر یکی از نقش‌های دارای تایید دو مرحله‌ای (`admin`, `supporter`, `teacher`) باشد:
    - ارسال `current_password` الزامی است (در صورت عدم ارسال ← `422 VALIDATION_ERROR`).
    - صحت `current_password` با `password_verify` بررسی می‌شود (در صورت اشتباه بودن ← `401 INVALID_PASSWORD`).
  - اگر `username` فعلی کاربر برابر با `phone` قبلی او بوده است، بررسی می‌شود `phone` جدید در `users.username` تکراری نباشد (`409 DUPLICATE_USERNAME`) و سپس `username` نیز به شماره جدید تغییر می‌یابد.
  - **بروزرسانی تراکنشی (`PDO Transaction`)**:
    - بروزرسانی `full_name`, `phone` (و در صورت لزوم `username`) در جدول `users`.
    - همگام‌سازی جدول نقش مرتبط بر اساس `role` و `linked_id`:
      - `admin` ← بروزرسانی `name` و `phone` در `admins`.
      - `supporter` ← بروزرسانی `name` و `phone` در `supporters`.
      - `student` ← بروزرسانی `name` و `phone` در `students`.
      - `teacher` ← بروزرسانی `name` در `instructors`.
  - **خروجی**: بازگرداندن اطلاعات کامل پروفایل با همان ساختار متد `me()`.

---

## مرحله دوم: ماژول مدیریت ادمین‌ها (Admins CRUD)

تمامی مسیرهای این بخش زیرمجموعه `/api/v1/admins` بوده و با `$authMiddleware` و `$requireAdmin` محافظت می‌شوند.

### ۱. لیست و جزئیات ادمین‌ها
- **`GET /api/v1/admins`**:
  - پشتیبانی از صفحه‌بندی (`page`, `per_page`)، جستجو (`search` روی `admins.name`, `users.username`, `admins.phone`) و فیلتر وضعیت (`is_active`).
  - اتصال (`LEFT JOIN`) جدول `admins` با `users` (`role = 'admin' AND linked_id = admins.id`).
  - محاسبه فیلد بولی `can_view_report_content` برای هر ادمین بر اساس حضور `users.id` در متغیر محیطی `ADMIN_CONTENT_READER_IDS` (مطابق منطق `ContentAccessMiddleware`).
- **`GET /api/v1/admins/{id}`**:
  - بازگرداندن جزئیات ادمین به‌همراه `username`, `user_id` و `can_view_report_content` (در صورت عدم وجود ← `404 NOT_FOUND`).

### ۲. ایجاد، ویرایش و حذف ادمین
- **`POST /api/v1/admins`**:
  - ورودی‌ها: `name` (الزامی)، `phone` (الزامی، نرمال‌سازی با `PhoneNumber::normalizeOrFail`)، `username` (اختیاری؛ پیش‌فرض برابر شماره موبایل نرمال‌شده)، `password` (الزامی، حداقل ۴ کاراکتر)، `chat_id` (اختیاری)، `is_active` (اختیاری، پیش‌فرض `1`).
  - بررسی یکتایی `username` و `phone` در `users` و `admins` (در صورت تکرار ← `409 DUPLICATE_USERNAME` / `DUPLICATE_PHONE`).
  - درج تراکنشی در جدول `admins` و سپس جدول `users` با `role = 'admin'` و `linked_id = admins.id`.
- **`PUT /api/v1/admins/{id}`**:
  - گارد خود-غیرفعال‌سازی: اگر ادمین هدف همان ادمین لاگین‌شده فعلی باشد و `is_active` به `0` تغییر کند ← `400 CANNOT_MODIFY_SELF`.
  - گارد آخرین ادمین فعال: اگر ادمین هدف تنها ادمین فعال سیستم باشد و `is_active` به `0` تغییر کند ← `400 LAST_ADMIN`.
  - بررسی یکتایی `username` و `phone` در صورت تغییر (`409 Conflict`).
  - بروزرسانی تراکنشی جداول `admins` و `users` (تغییر رمز عبور فقط در صورتی که فیلد `password` غیرخالی ارسال شده باشد، با حداقل ۴ کاراکتر).
- **`DELETE /api/v1/admins/{id}`**:
  - گارد خود-حذفی: اگر ادمین هدف همان ادمین لاگین‌شده فعلی باشد ← `400 CANNOT_DELETE_SELF`.
  - گارد آخرین ادمین فعال: اگر ادمین هدف فعال باشد و تنها ادمین فعال باقی‌مانده در سیستم باشد ← `400 LAST_ADMIN`.
  - حذف تراکنشی رکورد مرتبط در `telegram_links`، `users` و `admins`.

---

## مرحله سوم: مدیریت دسته‌بندی‌های وبلاگ (Blog Categories CRUD)

### ۱. اندپوینت‌ها و سطوح دسترسی
- **`GET /api/v1/blog/categories`** (`$authMiddleware` + `$requireSupporter`):
  - پشتیبانی از پارامتر کوئری `include_inactive=1` برای برگرداندن همه دسته‌بندی‌ها (فعال و غیرفعال) به‌همراه `post_count`؛ در حالت پیش‌فرض همان رفتار فعلی (فقط دسته‌بندی‌های فعال `is_active = 1`) حفظ می‌شود.
- **`GET /api/v1/blog/categories/{id}`** (`$authMiddleware` + `$requireSupporter`):
  - دریافت جزئیات یک دسته‌بندی به‌همراه تعداد مقالات مرتبط (`post_count`). در صورت عدم وجود ← `404 NOT_FOUND`.
- **`POST /api/v1/blog/categories`** (`$authMiddleware` + `$requireAdmin`):
  - ورودی‌ها: `name` (الزامی)، `slug` (اختیاری؛ در صورت عدم ارسال، تولید خودکار یکتا از روی `name` با همان الگوی `BlogPost::slugify` و `makeUniqueSlug`)، `icon` (پیش‌فرض `'fa-folder'`)، `color` (پیش‌فرض `'#6366f1'`)، `description`، `sort_order` (پیش‌فرض `0`)، `is_active` (پیش‌فرض `1`).
  - در صورت ارسال دستی `slug` تکراری ← `409 DUPLICATE_SLUG`.
  - فراخوانی `Cache::flushGroup('public')` پس از ایجاد.
- **`PUT /api/v1/blog/categories/{id}`** (`$authMiddleware` + `$requireAdmin`):
  - بروزرسانی فیلدهای دسته‌بندی با بررسی یکتایی `slug` (`409 DUPLICATE_SLUG` در صورت تکراری بودن در سایر رکوردها).
  - فراخوانی `Cache::flushGroup('public')` پس از ویرایش.
- **`DELETE /api/v1/blog/categories/{id}`** (`$authMiddleware` + `$requireAdmin`):
  - حذف دسته‌بندی از `blog_category` (به‌واسطه `ON DELETE SET NULL` در `blog_posts.category_id`، مقالات مرتبط بدون خطا حفظ می‌شوند) و فراخوانی `Cache::flushGroup('public')`.

---

## مستندات، تست‌ها و ترتیب اجرا

1. **مستندسازی همزمان در هر مرحله**:
   - بروزرسانی `/openapi.yaml` (نسخه `3.1.0` با `SuccessResponse`/`ErrorResponse` و `operationId`های یکتا) و جدول مسیرها در `/README.md`.
2. **تست‌های خودکار (`/dev-server.test.mjs`)**:
   - پوشش سناریوهای موفق (`200`/`201`)، خطاهای دسترسی (`401`/`403`)، خطاهای اعتبارسنجی (`422`)، تکراری بودن داده‌ها (`409`) و گاردهای محافظتی (`CANNOT_MODIFY_SELF`, `CANNOT_DELETE_SELF`, `LAST_ADMIN` و الزام `current_password` هنگام تغییر شماره نقش‌های 2FA).
3. **ترتیب اجرای گام‌به‌گام با تأیید شما**:
   - **گام ۱**: پیاده‌سازی و تست کامل بخش **Auth** + گزارش نتیجه و توقف برای تأیید شما.
   - **گام ۲**: پیاده‌سازی و تست کامل بخش **Admins** + گزارش نتیجه و توقف برای تأیید شما.
   - **گام ۳**: پیاده‌سازی و تست کامل بخش **Blog Categories** + گزارش نهایی فایل‌های تغییریافته.
