# مستندات اتصال فرانت‌اند: نوبت‌دهی پیامکی هفتگی (`Appointments Weekly SMS`)

این مستند مرجع کامل و خودکفای پیاده‌سازی صفحه‌ی **«ارسال پیامکی نوبت هفتگی شاگردان»** در پنل ادمین (React SPA) است. تمامی قراردادهای شبکه، ساختارهای JSON، قواعد وضعیت‌ها، وابستگی‌ها (ویرایش نوبت و مخاطب والد) و تفاوت‌های بین بک‌اند اصلی (PHP / Slim 4) و سرور تست (`dev-server.mjs`) در این فایل مستند شده‌اند.

> **فایل تایپ‌های TypeScript همراه:** [`docs/frontend/appointments-sms.types.ts`](./appointments-sms.types.ts)

---

## ۱. کلیات (`General Conventions`)

### ۱.۱. مسیر پایه (`Base Path`)
تمامی اندپوینت‌های این فیچر زیرمجموعه‌ی مسیر نسخه‌بندی‌شده‌ی زیر هستند:
```text
/api/v1
```
- در محیط پروداکشن: `https://api.famoacademy.ir/api/v1`
- در محیط توسعه (`dev-server`): `http://localhost:3000/api/v1` (یا پراکسی نسبی `/api/v1`)

### ۱.۲. پاکت استاندارد پاسخ (`Standard JSON Envelope`)
همه‌ی پاسخ‌های موفق و خطا (به‌جز خطاهای سطح شبکه) دارای یک پاکت JSON یکسان با ۴ کلید اصلی هستند:

**پاسخ موفق (`200 OK` / `201 Created`):**
```json
{
  "success": true,
  "data": { ... },
  "pagination": null,
  "error": null
}
```
در اندپوینت‌های صفحه‌بندی‌شده (مانند `GET /api/v1/appointments/sms/log` و `GET /api/v1/appointments`)، فیلد `pagination` به شکل زیر پر می‌شود:
```json
{
  "page": 1,
  "per_page": 50,
  "total": 3,
  "total_pages": 1
}
```

**پاسخ خطا (`4xx` / `5xx`):**
```json
{
  "success": false,
  "data": null,
  "pagination": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "فرمت تاریخ هفته نامعتبر است (YYYY-MM-DD)"
  }
}
```
> **نکته برای کد `429 RATE_LIMITED`:** در زمان برخورد با محدودیت نرخ کلی، علاوه بر هدر HTTP `Retry-After`، یک فیلد عددی `retry_after` (بر حسب ثانیه) نیز در سطح اول JSON در کنار `error` برگردانده می‌شود.

### ۱.۳. نحوه‌ی احراز هویت (`Authentication`)
- **ارسال توکن در درخواست‌ها:**
  1. **هدر استاندارد (پیشنهادی برای SPA):**
     ```http
     Authorization: Bearer <JWT_TOKEN>
     ```
  2. **کوکی مشترک (`HttpOnly Cookie`):** در صورتی که هدر `Authorization` ارسال نشود، میدلور `AuthMiddleware` به صورت خودکار کوکی `famo_jwt` (در صورت ارسال درخواست با `credentials: 'include'`) را نیز می‌خواند.
- **نحوه‌ی دریافت توکن برای نقش `admin` (دو مرحله‌ای - 2FA):**
  1. `POST /api/v1/auth/login` با بدنه‌ی `{"username": "...", "password": "..."}`: برای نقش‌های `admin`، `supporter` و `teacher` کد وضعیت **`202 Accepted`** با `data: { "requires_2fa": true, "user_id": 1, "phone_mask": "0912***01" }` برمی‌گرداند.
  2. `POST /api/v1/auth/verify-2fa` با بدنه‌ی `{"user_id": 1, "code": "123456"}`: کد وضعیت **`200 OK`** به همراه `data.token` (رشته‌ی JWT با الگوریتم `HS256`) و اطلاعات کاربر برمی‌گرداند.

### ۱.۴. نقش‌های مجاز (`Authorization & Roles`)
در سیستم فعلی هیچ نقشی به نام `secretary` وجود ندارد (`users.role` فقط یکی از `admin`، `supporter`، `student`، `teacher` است). سطح دسترسی اندپوینت‌های مرتبط با این صفحه به شرح زیر است:

| گروه اندپوینت | مسیر | نقش‌های مجاز | رفتار در صورت عدم دسترسی |
| :--- | :--- | :---: | :--- |
| **پیکربندی کد پترن‌های ملی‌پیامک** | `GET /api/v1/admin/sms/patterns`<br>`PUT\|PATCH /api/v1/admin/sms/patterns` | **فقط `admin`** | `403 FORBIDDEN` (`دسترسی محدود به مدیران`) |
| **پیامک نوبت هفتگی** | `GET /api/v1/appointments/sms/preview`<br>`POST /api/v1/appointments/sms/send`<br>`GET /api/v1/appointments/sms/log` | **فقط `admin`** | `403 FORBIDDEN` (`دسترسی محدود به مدیران`) |
| **مدیریت نوبت‌ها** | `GET /api/v1/appointments`<br>`POST /api/v1/appointments`<br>`PUT /api/v1/appointments/{id}/status`<br>`DELETE /api/v1/appointments/{id}` | **`admin`** و **`supporter`** | `403 FORBIDDEN` (`دسترسی محدود به پشتیبانان`) |
| **مخاطبین والد (`parent_contacts`)** | `GET /api/v1/students/{studentId}/parent-contacts` | **`admin`** و **`supporter`** | `403 FORBIDDEN` |
| **ویرایش مخاطبین والد** | `POST /api/v1/students/{studentId}/parent-contacts`<br>`PUT /api/v1/students/{studentId}/parent-contacts/{id}`<br>`DELETE /api/v1/students/{studentId}/parent-contacts/{id}` | **فقط `admin`** | `403 FORBIDDEN` |

### ۱.۵. فرمت تاریخ، ساعت و منطقه‌ی زمانی (`Date, Time & Timezone`)
- **منطقه‌ی زمانی منطق تقویم:** تمامی محاسبات روز و هفته‌ی تقویمی بر پایه‌ی **`Asia/Tehran`** در کلاس `App\Modules\Bot\IranDay` انجام می‌شود.
- **فرمت `week_date` و نحوه‌ی ارسال از فرانت:**
  - فرمت ورودی همیشه **تاریخ میلادی `YYYY-MM-DD`** (مثلاً `2026-10-24` یا `2026-10-25`) است.
  - **آیا فرانت باید حتماً شنبه را بفرستد یا هر روز دلخواه از هفته؟** فرانت می‌تواند **هر روز دلخواه در طول هفته** (از شنبه تا جمعه، مثلاً تاریخ امروز `IranDay::today()`) را در پارامتر `week_date` بفرستد.
  - **تبدیل در بک‌اند:** بک‌اند به کمک `IranDay::weekStart($weekDate)` تاریخ دریافتی را به **شنبه‌ی شروع همان هفته** تبدیل می‌کند و پایان هفته را با `IranDay::addDays($weekStart, 6)` روی **جمعه‌ی همان هفته** قرار می‌دهد. در پاسخ خروجی نیز همیشه `week_date` (شنبه‌ی نرمال‌شده) و `week_end` (جمعه) برگردانده می‌شوند.
- **تولید تاریخ شمسی و نام روز هفته:**
  - **بک‌اند تاریخ شمسی را می‌سازد:** فرانت برای ارسال پیامک یا نمایش پیش‌نمایش نیازی به تبدیل تاریخ به شمسی ندارد. بک‌اند به کمک `IranDay::jalali` و `IranDay::weekdayName` تاریخ شمسی (`1405/08/02`) و نام روز هفته (`شنبه`) را تولید می‌کند و هم در خروجی `preview` تحویل می‌دهد و هم مستقیماً در متغیر دوم پترن ملی‌پیامک (`شنبه 1405/08/02`) قرار می‌دهد.
- **فرمت ساعت و `appointment_at`:**
  - ساعت‌ها در دیتابیس از نوع `TIME` (`HH:MM:SS` یا `HH:MM`) هستند.
  - اسنپ‌شات زمان نوبت (`appointment_at`) همیشه از ترکیب تاریخ میلادی نوبت و ساعت شروع به فرمت استاندارد **`YYYY-MM-DD HH:MM:SS`** (مثلاً `"2026-10-24 16:30:00"`) ساخته می‌شود.
  - متغیر سوم پیامک ارسالی به والد، ساعت کوتاه به فرمت **`HH:MM`** (مثلاً `"16:30"`) است.
  - فیلدهای زمان ثبت لاگ (`sent_at`, `created_at`, `updated_at`) در دیتابیس به صورت UTC با فرمت `YYYY-MM-DD HH:MM:SS` ذخیره می‌شوند.

---

## ۲. مستندات تک‌تک اندپوینت‌ها (`Endpoints Reference`)

### ۲.۱. پیش‌نمایش وضعیت پیامک نوبت هفتگی (`Preview`)
بدون ارسال هیچ پیامکی، لیست شاگردها و وضعیت نوبت و پیامک هر کدام را برای هفته‌ی انتخاب‌شده برمی‌گرداند.

- **متد و مسیر:** `GET /api/v1/appointments/sms/preview`
- **نقش مجاز:** فقط `admin`

#### پارامترهای Query
| نام پارامتر | نوع | اجباری / اختیاری | مقدار پیش‌فرض | توضیح |
| :--- | :---: | :---: | :---: | :--- |
| `week_date` | `string` (`YYYY-MM-DD`) | **اجباری** (در PHP و OpenAPI) | ندارد | هر تاریخ میلادی معتبر درون هفته‌ی هدف (شنبه تا جمعه). بک‌اند آن را به شنبه‌ی همان هفته تبدیل می‌کند. |

#### نمونه‌ی درخواست `curl`
```bash
curl -X GET "https://api.famoacademy.ir/api/v1/appointments/sms/preview?week_date=2026-10-25" \
  -H "Authorization: Bearer <ADMIN_JWT_TOKEN>" \
  -H "Accept: application/json"
```

#### نمونه‌ی پاسخ موفق واقعی (`200 OK` — خروجی واقعی اجراشده روی `dev-server`)
```json
{
  "success": true,
  "data": {
    "week_date": "2026-10-24",
    "week_end": "2026-10-30",
    "recipient_type": "parent",
    "summary": {
      "total_students": 4,
      "will_send": 0,
      "already_sent": 0,
      "changed_since_sent": 1,
      "failed": 1,
      "no_phone": 1,
      "no_appointment": 1,
      "multiple_appointments": 1
    },
    "items": [
      {
        "student_id": 20,
        "student_name": "امیرحسین رضایی",
        "grade": 12,
        "field": "ریاضی",
        "recipient_type": "parent",
        "parent_name": "بهرام رضایی",
        "mobile": "09120001122",
        "has_valid_phone": true,
        "appointment_id": 101,
        "appointment_date": "2026-10-24",
        "start_time": "17:30:00",
        "end_time": "17:00:00",
        "appointment_status": "scheduled",
        "appointment_at": "2026-10-24 17:30:00",
        "day_and_date_fa": "شنبه 1405/08/02",
        "time_fa": "17:30",
        "multiple_appointments": true,
        "active_appointments_count": 2,
        "status": "changed_since_sent",
        "last_sent_appointment_at": "2026-10-24 16:30:00",
        "last_sent_at": "2026-11-04 12:31:28",
        "attempts": 1,
        "last_error": null
      },
      {
        "student_id": 21,
        "student_name": "سارا محمدی",
        "grade": 12,
        "field": "تجربی",
        "recipient_type": "parent",
        "parent_name": "مریم حسینی",
        "mobile": "09120003344",
        "has_valid_phone": true,
        "appointment_id": 103,
        "appointment_date": "2026-10-25",
        "start_time": "15:00:00",
        "end_time": "15:30:00",
        "appointment_status": "pending",
        "appointment_at": "2026-10-25 15:00:00",
        "day_and_date_fa": "یکشنبه 1405/08/03",
        "time_fa": "15:00",
        "multiple_appointments": false,
        "active_appointments_count": 1,
        "status": "failed",
        "last_sent_appointment_at": "2026-10-25 15:00:00",
        "last_sent_at": null,
        "attempts": 1,
        "last_error": "خطا در ارسال پیامک از درگاه ملی‌پیامک"
      },
      {
        "student_id": 22,
        "student_name": "کیانوش احمدی",
        "grade": 11,
        "field": "انسانی",
        "recipient_type": "parent",
        "parent_name": null,
        "mobile": null,
        "has_valid_phone": false,
        "appointment_id": 104,
        "appointment_date": "2026-10-27",
        "start_time": "17:00:00",
        "end_time": "17:30:00",
        "appointment_status": "scheduled",
        "appointment_at": "2026-10-27 17:00:00",
        "day_and_date_fa": "سه‌شنبه 1405/08/05",
        "time_fa": "17:00",
        "multiple_appointments": false,
        "active_appointments_count": 1,
        "status": "no_phone",
        "last_sent_appointment_at": "2026-10-27 17:00:00",
        "last_sent_at": null,
        "attempts": 0,
        "last_error": "شماره تماس والد اصلی ثبت نشده یا نامعتبر است"
      },
      {
        "student_id": 23,
        "student_name": "نگین کریمی",
        "grade": 10,
        "field": "تجربی",
        "recipient_type": "parent",
        "parent_name": null,
        "mobile": null,
        "has_valid_phone": false,
        "appointment_id": null,
        "appointment_date": null,
        "start_time": null,
        "end_time": null,
        "appointment_status": null,
        "appointment_at": null,
        "day_and_date_fa": null,
        "time_fa": null,
        "multiple_appointments": false,
        "active_appointments_count": 0,
        "status": "no_appointment",
        "last_sent_appointment_at": null,
        "last_sent_at": null,
        "attempts": 0,
        "last_error": null
      }
    ]
  },
  "pagination": null,
  "error": null
}
```

#### جدول فیلدهای پاسخ `data` در `preview`
> **توجه مهم:** چند کلید بین بک‌اند PHP (`AppointmentService.php`) و `dev-server.mjs` تفاوت نام جزئی دارند که در جدول زیر هر دو ذکر شده‌اند (کد فرانت با خواندن `item.mobile_mask ?? item.mobile` و `summary.total ?? summary.total_students` با هر دو سازگار خواهد بود).

| مسیر فیلد | نوع | Nullable | معنا و توضیح |
| :--- | :---: | :---: | :--- |
| `data.week_date` | `string` | خیر | تاریخ میلادی شنبه‌ی شروع هفته (`YYYY-MM-DD`). |
| `data.week_end` | `string` | خیر | تاریخ میلادی جمعه‌ی پایان هفته (`YYYY-MM-DD`). |
| `data.pattern` | `object` | خیر | وضعیت کد پترن نوبت‌دهی (`key`, `body_id`, `source`, `is_configured`, `template`). اگر `is_configured === false` باشد، پیش از ارسال باید کد پترن از طریق `PUT /api/v1/admin/sms/patterns` ثبت شود. |
| `data.summary.total` *(PHP)* / `total_students` *(dev-server)* | `integer` | خیر | تعداد کل شاگردان بررسی‌شده در این هفته. |
| `data.summary.will_send` | `integer` | خیر | تعداد شاگردانی که نوبت فعال و شماره‌ی والد معتبر دارند و هنوز پیامکی برایشان ارسال نشده است. |
| `data.summary.already_sent` | `integer` | خیر | تعداد شاگردانی که پیامک نوبت فعلی با موفقیت برایشان ارسال شده و زمان نوبت تغییر نکرده است. |
| `data.summary.changed_since_sent` | `integer` | خیر | تعداد شاگردانی که پس از ارسال موفق پیامک، زمان نوبتشان (`appointment_at`) تغییر کرده است. |
| `data.summary.failed` | `integer` | خیر | تعداد شاگردانی که آخرین تلاش ارسال پیامک برایشان با خطا مواجه شده است. |
| `data.summary.no_phone` | `integer` | خیر | تعداد شاگردانی که نوبت فعال دارند اما والد اصلی یا شماره‌ی موبایل معتبر والد ندارند. |
| `data.summary.no_appointment` | `integer` | خیر | تعداد شاگردان فعالی که هیچ نوبت فعالی در بازه‌ی شنبه تا جمعه‌ی این هفته ندارند. |
| `data.summary.multiple_appointments` | `integer` | خیر | تعداد شاگردانی که بیش از یک نوبت فعال در این هفته دارند. |
| `data.items` (و `data.students` در PHP) | `array` | خیر | آرایه‌ی وضعیت تک‌تک شاگردان. |
| `items[].student_id` | `integer` | خیر | شناسه‌ی یکتای شاگرد در جدول `students`. |
| `items[].student_name` | `string` | خیر | نام و نام خانوادگی شاگرد. |
| `items[].grade` | `integer` | بله | پایه‌ی تحصیلی شاگرد (`7` تا `12`). |
| `items[].field` | `string` | بله | رشته‌ی تحصیلی شاگرد (مثلاً `ریاضی`، `تجربی`، `انسانی`). |
| `items[].status` | `string` | خیر | وضعیت پیامک شاگرد: یکی از `will_send`, `already_sent`, `changed_since_sent`, `failed`, `no_phone`, `no_appointment`. |
| `items[].recipient_type` | `string` | خیر | همیشه مقدار ثابت `"parent"`. |
| `items[].parent_name` | `string` | بله | نام والد اصلی (`parent_contacts` با `ORDER BY is_primary DESC, id ASC LIMIT 1`). در صورت عدم ثبت والد: `null`. |
| `items[].mobile_mask` *(PHP)* / `items[].mobile` *(dev-server)* | `string` | بله | شماره‌ی موبایل والد اصلی (در PHP به صورت ماسک‌شده مثل `"0912***22"`، در `dev-server` به صورت `"09120001122"`). در صورت نبود شماره معتبر: `null`. |
| `items[].appointment_id` | `integer` | بله | شناسه‌ی نزدیک‌ترین نوبت فعال شاگرد در این هفته (`null` اگر `no_appointment`). |
| `items[].appointment_date` | `string` | بله | تاریخ میلادی نوبت انتخاب‌شده (`YYYY-MM-DD`). |
| `items[].appointment_jalali` *(PHP)* | `string` | بله | تاریخ شمسی نوبت (`YYYY/MM/DD` مثلاً `"1405/08/02"`). |
| `items[].weekday` *(PHP)* | `string` | بله | نام روز هفته‌ی نوبت به فارسی (مثلاً `"شنبه"`). |
| `items[].day_and_date_fa` *(dev-server)* | `string` | بله | ترکیب نام روز هفته و تاریخ شمسی (`"شنبه 1405/08/02"`). در PHP از ترکیب `${weekday} ${appointment_jalali}` ساخته می‌شود. |
| `items[].start_time` | `string` | بله | ساعت شروع نوبت (در PHP به فرمت `"16:30"`، در `dev-server` به فرمت `"16:30:00"`). |
| `items[].end_time` | `string` | بله | ساعت پایان نوبت (در PHP به فرمت `"17:00"`، در `dev-server` به فرمت `"17:00:00"`). |
| `items[].appointment_status` | `string` | بله | وضعیت خود نوبت انتخاب‌شده (`pending`، `confirmed` یا `scheduled`). |
| `items[].appointment_at` | `string` | بله | اسنپ‌شات استاندارد زمان نوبت (`YYYY-MM-DD HH:MM:SS`). |
| `items[].sms_preview_text` | `string` | بله | متن کامل و جایگذاری‌شده‌ی پیامک مطابق پترن ملی‌پیامک نوبت‌دهی (`{0}` نام شاگرد، `{1}` روز و تاریخ شمسی، `{2}` ساعت). |
| `items[].multiple_appointments` | `boolean` | خیر | `true` اگر شاگرد بیش از یک نوبت فعال در این هفته داشته باشد. |
| `items[].active_appointments_count` | `integer` | خیر | تعداد کل نوبت‌های فعال شاگرد در این هفته (`0`، `1`، `2` و ...). |
| `items[].last_sent_at` | `string` | بله | زمان آخرین ارسال موفق پیامک در این هفته (`YYYY-MM-DD HH:MM:SS`). |
| `items[].last_appointment_at` *(PHP)* / `last_sent_appointment_at` *(dev-server)* | `string` | بله | مقدار `appointment_at` ثبت‌شده در آخرین لاگ این هفته (برای نمایش زمان قبلی وقتی وضعیت `changed_since_sent` است). |
| `items[].attempts` | `integer` | خیر | تعداد دفعات تلاش ثبت‌شده در لاگ این هفته (`0` اگر هنوز لاگی ثبت نشده). |
| `items[].last_error` | `string` | بله | متن آخرین خطای ثبت‌شده در لاگ (در صورت وجود). |

#### جدول خطاهای `GET /api/v1/appointments/sms/preview`
| کد HTTP | `error.code` | چه موقع رخ می‌دهد؟ | پیام پیشنهادی فرانت به کاربر |
| :---: | :---: | :--- | :--- |
| `401` | `UNAUTHORIZED` / `AUTH_ERROR` | توکن ارسال نشده، نامعتبر است یا منقضی شده است. | «نشست کاربری شما منقضی شده است. لطفاً مجدداً وارد شوید.» (هدایت به صفحه ورود) |
| `403` | `FORBIDDEN` | نقش کاربر چیزی غیر از `admin` است. | «شما دسترسی لازم برای مشاهده‌ی پنل پیامک نوبت‌ها را ندارید (ویژه مدیر).» |
| `422` | `VALIDATION_ERROR` | پارامتر `week_date` ارسال نشده یا فرمت `YYYY-MM-DD` معتبری ندارد. | «تاریخ هفته‌ی انتخاب‌شده معتبر نیست.» |

---

### ۲.۲. ارسال گروهی پیامک نوبت هفتگی (`Send`)
ارسال پیامک یادآوری نوبت هفتگی از طریق پترن ملی‌پیامک (`MELIPAYAMAK_APPOINTMENT_BODY_ID`) به والد اصلی شاگردان واجد شرایط بر اساس `mode` انتخابی.

- **متد و مسیر:** `POST /api/v1/appointments/sms/send`
- **نقش مجاز:** فقط `admin`
- **متغیرهای پترن ارسالی به ملی‌پیامک:** `"{student_name};{weekday} {jalali_date};{HH:MM}"` (مخاطب: والد اصلی شاگرد).

#### فیلدهای بدنه‌ی درخواست (`Request Body JSON`)
| نام فیلد | نوع | اجباری / اختیاری | مقدار پیش‌فرض | توضیح |
| :--- | :---: | :---: | :---: | :--- |
| `week_date` | `string` (`YYYY-MM-DD`) | **اجباری** | ندارد | تاریخ هفته‌ی هدف (توسط بک‌اند با `IranDay::weekStart` به شنبه‌ی همان هفته تبدیل می‌شود). |
| `mode` | `'unsent' \| 'failed' \| 'changed'` | **اجباری** (در OpenAPI؛ در کنترلر پیش‌فرض `'unsent'`) | `'unsent'` | تعیین می‌کند پیامک برای کدام دسته از شاگردان ارسال شود (توضیح کامل در بخش ۳.۱). |
| `student_ids` | `number[] \| null` | اختیاری | `null` (همه‌ی واجدین) | آرایه‌ای از شناسه‌های شاگردان (`[20, 21]`). اگر ارسال نشود یا `null` باشد، همه‌ی شاگردان واجد شرایط آن `mode` پردازش می‌شوند. **هشدار:** آرایه‌ی خالی `[]` نفرستید (در PHP خطای `422` می‌دهد). |
| `operation_id` | `string \| null` | اختیاری | `null` | کلید یکتای عملیات (Idempotency key) برای جلوگیری از ارسال تکراری بر اثر دابل‌کلیک (در PHP کلید `idempotency_key` نیز پذیرفته می‌شود). |
| `body_id` | `number \| null` | اختیاری | مقدار ذخیره‌شده در DB / Env | کد پترن (`bodyId`) اختصاصی نوبت‌دهی در ملی‌پیامک برای ارسال فعلی (جایگزین تنظیمات DB و متغیر محیطی). |
| `save_pattern` | `boolean` | اختیاری | `false` | اگر همراه با `body_id` برابر `true` ارسال شود، این کد پترن در جدول `sms_pattern_settings` برای ارسال‌های آینده نیز ذخیره می‌شود. |

#### نمونه‌ی درخواست `curl`
```bash
curl -X POST "https://api.famoacademy.ir/api/v1/appointments/sms/send" \
  -H "Authorization: Bearer <ADMIN_JWT_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "week_date": "2026-10-24",
    "mode": "unsent",
    "operation_id": "op-week-2026-10-24-unsent"
  }'
```

#### نمونه‌ی ۱ پاسخ موفق واقعی (`mode: "unsent"` — خروجی واقعی `dev-server`)
```json
{
  "success": true,
  "data": {
    "week_date": "2026-10-24",
    "week_end": "2026-10-30",
    "mode": "unsent",
    "recipient_type": "parent",
    "summary": {
      "sent": 1,
      "failed": 1,
      "skipped": 1,
      "skipped_no_phone": 1
    },
    "details": [
      {
        "student_id": 20,
        "student_name": "امیرحسین رضایی",
        "mobile": "09120001122",
        "appointment_at": "2026-10-24 16:30:00",
        "multiple_appointments": true,
        "action": "sent",
        "status": "sent",
        "error": null
      },
      {
        "student_id": 21,
        "student_name": "سارا محمدی",
        "mobile": "09120003344",
        "appointment_at": "2026-10-25 15:00:00",
        "multiple_appointments": false,
        "action": "failed",
        "status": "failed",
        "error": "خطا در ارسال پیامک از درگاه ملی‌پیامک"
      },
      {
        "student_id": 22,
        "student_name": "کیانوش احمدی",
        "mobile": null,
        "appointment_at": "2026-10-27 17:00:00",
        "multiple_appointments": false,
        "action": "skipped_no_phone",
        "status": "skipped_no_phone",
        "error": "شماره تماس والد اصلی ثبت نشده یا نامعتبر است"
      }
    ]
  },
  "pagination": null,
  "error": null
}
```

#### نمونه‌ی ۲ پاسخ موفق واقعی (`mode: "changed"` با `student_ids: [20]` — خروجی واقعی `dev-server`)
```json
{
  "success": true,
  "data": {
    "week_date": "2026-10-24",
    "week_end": "2026-10-30",
    "mode": "changed",
    "recipient_type": "parent",
    "summary": {
      "sent": 1,
      "failed": 0,
      "skipped": 0,
      "skipped_no_phone": 0
    },
    "details": [
      {
        "student_id": 20,
        "student_name": "امیرحسین رضایی",
        "mobile": "09120001122",
        "appointment_at": "2026-10-24 17:30:00",
        "multiple_appointments": true,
        "action": "sent",
        "status": "sent",
        "error": null
      }
    ]
  },
  "pagination": null,
  "error": null
}
```

#### جدول فیلدهای پاسخ `data` در `send`
| مسیر فیلد | نوع | Nullable | معنا و توضیح |
| :--- | :---: | :---: | :--- |
| `data.week_date` | `string` | خیر | شنبه‌ی شروع هفته (`YYYY-MM-DD`). |
| `data.week_end` | `string` | خیر | جمعه‌ی پایان هفته (`YYYY-MM-DD`). |
| `data.mode` | `string` | خیر | حالت ارسال اجراشده (`unsent`، `failed` یا `changed`). |
| `data.sent` *(PHP)* / `data.summary.sent` *(هر دو)* | `integer` | خیر | تعداد پیامک‌هایی که در این درخواست با موفقیت ارسال شدند. |
| `data.failed` *(PHP)* / `data.summary.failed` *(هر دو)* | `integer` | خیر | تعداد پیامک‌هایی که ارسال آن‌ها با خطا مواجه شد. |
| `data.skipped` *(PHP)* / `data.summary.skipped` *(هر دو)* | `integer` | خیر | تعداد کل شاگردانی که رد (skip) شدند (شامل بدون شماره، قبلاً ارسال‌شده، عدم تطابق با `mode`، و در PHP شاگردان بدون نوبت). |
| `data.skipped_no_phone` *(PHP)* / `data.summary.skipped_no_phone` *(هر دو)* | `integer` | خیر | تعداد شاگردانی که به علت نداشتن شماره‌ی والد معتبر رد شدند و در لاگ با وضعیت `skipped_no_phone` ثبت شدند. |
| `data.summary.already_sent` *(PHP)* | `integer` | خیر | تعداد شاگردانی که قبلاً برای همین `appointment_at` پیامک دریافت کرده‌اند. |
| `data.summary.no_appointment` *(PHP)* | `integer` | خیر | تعداد شاگردانی که نوبت فعالی در هفته نداشتند. |
| `data.items` *(PHP)* / `data.details` *(dev-server)* | `array` | خیر | آرایه‌ی نتیجه‌ی پردازش به تفکیک هر شاگرد. فرانت باید `data.items ?? data.details ?? []` را بخواند. |
| `items[].student_id` | `integer` | خیر | شناسه‌ی شاگرد. |
| `items[].student_name` | `string` | خیر | نام شاگرد. |
| `items[].mobile_mask` *(PHP)* / `items[].mobile` *(dev-server)* | `string` | بله | شماره‌ی موبایل والد اصلی (ماسک‌شده در PHP، کامل در `dev-server`). |
| `items[].appointment_at` | `string` | بله | زمان نوبت (`YYYY-MM-DD HH:MM:SS`). |
| `items[].multiple_appointments` | `boolean` | خیر | آیا شاگرد بیش از یک نوبت فعال در این هفته داشته است؟ |
| `items[].status` | `string` | خیر | نتیجه‌ی نهایی شاگرد: `sent`, `failed`, `skipped_no_phone`, `already_sent`, `changed_since_sent`, `no_appointment`, `skipped`. |
| `items[].action` *(dev-server)* | `string` | خیر | دلیل دقیق اقدام یا skip در `dev-server` (مثلاً `sent`, `failed`, `skipped_no_phone`, `skipped_already_sent`, `skipped_mode_mismatch`). |
| `items[].error` | `string` | بله | متن خطای فارسی در صورت `failed` (یا در `dev-server` برای `skipped_no_phone`). |

#### جدول خطاهای `POST /api/v1/appointments/sms/send`
| کد HTTP | `error.code` | چه موقع رخ می‌دهد؟ | پیام پیشنهادی فرانت به کاربر |
| :---: | :---: | :--- | :--- |
| `401` | `UNAUTHORIZED` / `AUTH_ERROR` | عدم ارسال یا انقضای توکن JWT. | «نشست کاربری شما منقضی شده است. لطفاً دوباره وارد شوید.» |
| `403` | `FORBIDDEN` | کاربر دارای نقش غیر از `admin` است. | «فقط مدیر سیستم مجاز به ارسال پیامک نوبت‌هاست.» |
| `409` | `CONCURRENT_SEND_LOCKED` | ارسال همزمان برای همان `week_date` در حال اجراست یا همان `operation_id` در ۶۰ ثانیه‌ی اخیر ارسال شده است. | «عملیات ارسال پیامک برای این هفته در حال انجام است. لطفاً چند لحظه صبر کنید.» |
| `422` | `APPOINTMENT_PATTERN_NOT_CONFIGURED` | کد پترن یادآوری نوبت نه در جدول `sms_pattern_settings`، نه در `MELIPAYAMAK_APPOINTMENT_BODY_ID` و نه در `body_id` درخواست تنظیم نشده است (هرگز به پترن لاگین ۲مرحله‌ای fallback نمی‌شود). | «کد پترن پیامک نوبت در ملی‌پیامک ثبت نشده است. لطفاً کد پترن را در تنظیمات وارد کنید.» |
| `422` | `VALIDATION_ERROR` | `week_date` نامعتبر است، `mode` خارج از ۳ مقدار مجاز است، یا `student_ids` آرایه‌ی خالی `[]` یا شامل مقدار غیرعددی است. | نمایش `error.message` برگشتی از سرور. |
| `429` | `RATE_LIMITED` | سقف ارسال ساعتی کاربر ادمین (`200` پیامک در ساعت) پیش از شروع درخواست پر شده است. | «سقف ارسال پیامک ساعتی شما تکمیل شده است. لطفاً پس از زمان اعلام‌شده تلاش کنید.» |

---

### ۲.۲.۱. دریافت و ویرایش کد پترن‌های ملی‌پیامک (`Admin SMS Patterns`)
بک‌اند برای جلوگیری از ارسال پیامک نوبت با پترن لاگین ادمین، هر کاربرد را کاملاً ایزوله مدیریت می‌کند (`auth_2fa`, `appointment_reminder`, `exam_result`):
- **دریافت تنظیمات:** `GET /api/v1/admin/sms/patterns` (فقط `admin`) → خروجی شامل `patterns.appointment_reminder` (`body_id`, `source`, `is_configured`, `pattern_text`) و کلیدهای مستقیم `appointment_body_id`, `exam_body_id`, `auth_body_id`.
- **ذخیره / ویرایش:** `PUT` یا `PATCH /api/v1/admin/sms/patterns` با بدنه‌ی نمونه:
  ```json
  {
    "appointment_body_id": 345678
  }
  ```
  یا به صورت آبجکت کامل برای تغییر همزمان متن الگو:
  ```json
  {
    "patterns": {
      "appointment_reminder": {
        "body_id": 345678,
        "pattern_text": "موسسه آموزشی فامو | دکتر موسوی والدین گرامی {0} ، نوبت فرزند شما روز {1} ساعت {2} میباشد. در صورت تایید به شماره موسسه پیامک دهید."
      }
    }
  }
  ```

---

### ۲.۳. مشاهده‌ی تاریخچه و لاگ ارسال (`Log`)
مشاهده‌ی ردیف‌های جدول `appointment_sms_log` با قابلیت فیلتر بر اساس هفته، شاگرد و وضعیت لاگ به همراه صفحه‌بندی.

- **متد و مسیر:** `GET /api/v1/appointments/sms/log`
- **نقش مجاز:** فقط `admin`

#### پارامترهای Query
| نام پارامتر | نوع | اجباری / اختیاری | مقدار پیش‌فرض | توضیح |
| :--- | :---: | :---: | :---: | :--- |
| `week_date` | `string` (`YYYY-MM-DD`) | اختیاری | بدون فیلتر | فیلتر بر اساس هفته (توسط بک‌اند با `IranDay::weekStart` به شنبه‌ی همان هفته تبدیل می‌شود). |
| `student_id` | `integer` (`>= 1`) | اختیاری | بدون فیلتر | فیلتر لاگ‌های یک شاگرد خاص. |
| `status` | `'sent' \| 'failed' \| 'skipped_no_phone'` | اختیاری | بدون فیلتر | فیلتر بر اساس وضعیت ثبت‌شده در لاگ. |
| `page` | `integer` (`>= 1`) | اختیاری | `1` | شماره‌ی صفحه. |
| `per_page` (یا `perPage`) | `integer` (`1..100`) | اختیاری | `50` (در PHP) / `20` (در `dev-server`) | تعداد رکورد در هر صفحه (حداکثر `100`). |

#### نمونه‌ی درخواست `curl`
```bash
curl -X GET "https://api.famoacademy.ir/api/v1/appointments/sms/log?week_date=2026-10-24&page=1&per_page=50" \
  -H "Authorization: Bearer <ADMIN_JWT_TOKEN>" \
  -H "Accept: application/json"
```

#### نمونه‌ی پاسخ موفق واقعی (`200 OK` — خروجی واقعی `dev-server`)
```json
{
  "success": true,
  "data": {
    "logs": [
      {
        "id": 3,
        "student_id": 22,
        "week_date": "2026-10-24",
        "mobile": "",
        "recipient_type": "parent",
        "appointment_at": "2026-10-27 17:00:00",
        "status": "skipped_no_phone",
        "error": "شماره تماس والد اصلی ثبت نشده یا نامعتبر است",
        "attempts": 0,
        "sent_at": null,
        "created_by": 1,
        "created_at": "2026-11-04 12:31:28",
        "updated_at": "2026-11-04 12:31:28",
        "student_name": "کیانوش احمدی",
        "created_by_name": "ادمین اصلی"
      },
      {
        "id": 2,
        "student_id": 21,
        "week_date": "2026-10-24",
        "mobile": "09120003344",
        "recipient_type": "parent",
        "appointment_at": "2026-10-25 15:00:00",
        "status": "sent",
        "error": null,
        "attempts": 2,
        "sent_at": "2026-11-04 12:31:28",
        "created_by": 1,
        "created_at": "2026-11-04 12:31:28",
        "updated_at": "2026-11-04 12:31:28",
        "student_name": "سارا محمدی",
        "created_by_name": "ادمین اصلی"
      },
      {
        "id": 1,
        "student_id": 20,
        "week_date": "2026-10-24",
        "mobile": "09120001122",
        "recipient_type": "parent",
        "appointment_at": "2026-10-24 17:30:00",
        "status": "sent",
        "error": null,
        "attempts": 2,
        "sent_at": "2026-11-04 12:31:28",
        "created_by": 1,
        "created_at": "2026-11-04 12:31:28",
        "updated_at": "2026-11-04 12:31:28",
        "student_name": "امیرحسین رضایی",
        "created_by_name": "ادمین اصلی"
      }
    ]
  },
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 3,
    "total_pages": 1
  },
  "error": null
}
```

#### جدول فیلدهای پاسخ `GET /api/v1/appointments/sms/log`
> **توجه:** در بک‌اند PHP و `openapi.yaml` آرایه‌ی لاگ‌ها در `data.items` قرار دارد، اما در `dev-server.mjs` در `data.logs` قرار گرفته است. در فرانت‌اند از `data.items ?? data.logs ?? []` استفاده کنید.

| مسیر فیلد | نوع | Nullable | معنا و توضیح |
| :--- | :---: | :---: | :--- |
| `id` | `integer` | خیر | شناسه‌ی یکتای ردیف لاگ در `appointment_sms_log`. |
| `student_id` | `integer` | خیر | شناسه‌ی شاگرد. |
| `student_name` | `string` | خیر | نام و نام خانوادگی شاگرد. |
| `grade` *(PHP)* | `integer` | بله | پایه‌ی تحصیلی شاگرد. |
| `field` *(PHP)* | `string` | بله | رشته‌ی تحصیلی شاگرد. |
| `week_date` | `string` | خیر | تاریخ شنبه‌ی هفته‌ی مربوطه (`YYYY-MM-DD`). |
| `mobile_mask` *(PHP)* / `mobile` *(dev-server)* | `string` | بله | شماره‌ی موبایل والد (در PHP ماسک‌شده مثل `"0912***22"` یا `null` اگر بدون شماره بوده؛ در `dev-server` رشته‌ی کامل یا `""`). |
| `recipient_type` | `string` | خیر | نوع گیرنده (`"parent"`). |
| `appointment_at` | `string` | بله | اسنپ‌شات زمان نوبت (`YYYY-MM-DD HH:MM:SS`) در لحظه‌ی ثبت لاگ. |
| `status` | `string` | خیر | یکی از `sent`، `failed` یا `skipped_no_phone`. |
| `error` | `string` | بله | متن خطای ثبت‌شده (برای `failed` و `skipped_no_phone`). |
| `attempts` | `integer` | خیر | تعداد دفعات تلاش ارسال برای این `(student_id, week_date, mobile)`. |
| `sent_at` | `string` | بله | زمان ارسال موفق پیامک (`YYYY-MM-DD HH:MM:SS`). برای `failed` و `skipped_no_phone` برابر `null` است. |
| `created_by` | `integer` | بله | شناسه‌ی کاربر ادمین (`users.id`) که دکمه‌ی ارسال را زده است. |
| `created_by_username` *(PHP)* / `created_by_name` *(dev-server)* | `string` | بله | نام کاربری یا نام کامل ادمین ارسال‌کننده. |
| `created_at` | `string` | خیر | زمان ایجاد اولیه‌ی ردیف لاگ. |
| `updated_at` | `string` | خیر | زمان آخرین بروزرسانی ردیف لاگ. |

#### جدول خطاهای `GET /api/v1/appointments/sms/log`
| کد HTTP | `error.code` | چه موقع رخ می‌دهد؟ | پیام پیشنهادی فرانت به کاربر |
| :---: | :---: | :--- | :--- |
| `401` | `UNAUTHORIZED` / `AUTH_ERROR` | توکن نامعتبر یا ارسال‌نشده. | «نشست کاربری شما منقضی شده است.» |
| `403` | `FORBIDDEN` | نقش کاربر غیر از `admin` است. | «دسترسی محدود به مدیر سیستم است.» |
| `422` | `VALIDATION_ERROR` | فرمت `week_date` نامعتبر است، `student_id` عدد صحیح مثبت نیست، یا `status` خارج از ۳ مقدار مجاز است. | «فیلترهای واردشده برای گزارش پیامک معتبر نیستند.» |

---

### ۲.۴. چهار اندپوینت فعلی مدیریت نوبت‌ها (`/api/v1/appointments`)
صفحه‌ی نوبت‌دهی پیامکی برای ایجاد، ویرایش ساعت، لغو یا حذف نوبت‌ها از این ۴ اندپوینت موجود استفاده می‌کند.

#### ۲.۴.۱. لیست نوبت‌ها: `GET /api/v1/appointments`
- **نقش‌های مجاز:** `admin` و `supporter`
- **پارامترهای Query:**
  - `page` (`integer`, اختیاری، پیش‌فرض `1`)
  - `perPage` (`integer`, اختیاری، پیش‌فرض `20`، حداکثر `100`) — *نکته: کنترلر PHP کلید camelCase `perPage` را می‌خواند؛ برای اطمینان می‌توانید هر دو `perPage` و `per_page` را بفرستید.*
- **نمونه‌ی درخواست `curl`:**
  ```bash
  curl -X GET "https://api.famoacademy.ir/api/v1/appointments?page=1&perPage=20" \
    -H "Authorization: Bearer <JWT_TOKEN>"
  ```
- **پاسخ موفق (`200 OK`):**
  ```json
  {
    "success": true,
    "data": [
      {
        "id": 101,
        "student_id": 20,
        "appointment_date": "2026-10-24",
        "start_time": "16:30:00",
        "end_time": "17:00:00",
        "status": "scheduled",
        "type": "حضوری",
        "description": "بررسی آزمون جامع",
        "created_at": "2026-10-20 09:15:00",
        "updated_at": "2026-10-20 09:15:00",
        "student_name": "امیرحسین رضایی",
        "grade": 12,
        "field": "ریاضی",
        "phone": "09120000020"
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1,
      "total_pages": 1
    },
    "error": null
  }
  ```

#### ۲.۴.۲. ساخت یا ویرایش نوبت (Upsert): `POST /api/v1/appointments`
- **نقش‌های مجاز:** `admin` و `supporter`
- **رفتار مهم Upsert در بک‌اند (`AppointmentService::createOrUpdate`):**
  - این اندپوینت **هیچ پیامک خودکاری ارسال نمی‌کند** (فراخوانی قدیمی کاوه‌نگار حذف شده است).
  - بک‌اند ابتدا بررسی می‌کند که آیا برای `(student_id, appointment_date)` نوبتی در دیتابیس وجود دارد یا خیر (`Appointment::findByStudentAndDate`):
    - **اگر در همان `appointment_date` نوبتی وجود داشته باشد:** همان ردیف را `UPDATE` می‌کند (`start_time`, `end_time`, `type`, `description`) و `id` و `status` قبلی حفظ می‌شوند.
    - **اگر در آن `appointment_date` نوبتی نباشد:** یک ردیف جدید با وضعیت پیش‌فرض `'pending'` می‌سازد.
  - **هشدار مهم هنگام تغییر روز نوبت:** چون اندپوینت `PUT /api/v1/appointments/{id}` در بک‌اند وجود ندارد، اگر بخواهید نوبت یک شاگرد را از **یک روز به روز دیگری در همان هفته** (مثلاً از شنبه به دوشنبه) منتقل کنید، صرفاً صدا زدن `POST /api/v1/appointments` با تاریخ دوشنبه باعث **ساخته شدن نوبت دوم** می‌شود (و فلگ `multiple_appointments` روشن می‌شود). بنابراین برای تغییر روز نوبت، فرانت باید نوبت قبلی را با `DELETE /api/v1/appointments/{oldId}` حذف (یا با `PUT /api/v1/appointments/{oldId}/status` لغو) کند و نوبت تاریخ جدید را با `POST /api/v1/appointments` ثبت نماید.
- **فیلدهای بدنه (`JSON Body`):**
  | فیلد | نوع | اجباری | محدودیت / فرمت | توضیح |
  | :--- | :---: | :---: | :--- | :--- |
  | `student_id` | `integer` | **بله** | عدد صحیح مثبت (`> 0`) | شناسه‌ی شاگرد. |
  | `appointment_date` | `string` | **بله** | `YYYY-MM-DD` | تاریخ میلادی نوبت. |
  | `start_time` | `string` | **بله** | `HH:MM` یا `HH:MM:SS` | ساعت شروع نوبت (مثلاً `"16:30"`). |
  | `end_time` | `string \| null` | خیر | `HH:MM` یا `HH:MM:SS` | ساعت پایان نوبت (اختیاری؛ پیش‌فرض `null`). |
  | `type` | `string` | خیر | حداکثر ۱۰۰ کاراکتر | نوع جلسه (مثلاً `"حضوری"` یا `"تلفنی"`). پیش‌فرض `""`. |
  | `description` | `string` | خیر | حداکثر ۱۰۰۰ کاراکتر | توضیحات نوبت. پیش‌فرض `""`. |
- **نمونه‌ی درخواست `curl`:**
  ```bash
  curl -X POST "https://api.famoacademy.ir/api/v1/appointments" \
    -H "Authorization: Bearer <JWT_TOKEN>" \
    -H "Content-Type: application/json" \
    -d '{
      "student_id": 20,
      "appointment_date": "2026-10-24",
      "start_time": "17:30",
      "end_time": "18:00",
      "type": "حضوری",
      "description": "تغییر ساعت جلسه مشاوره"
    }'
  ```
- **پاسخ موفق (`201 Created`):** آبجکت کامل نوبت (`AppointmentRecord`) در `data` برگردانده می‌شود.

#### ۲.۴.۳. تغییر وضعیت نوبت: `PUT /api/v1/appointments/{id}/status`
- **نقش‌های مجاز:** `admin` و `supporter`
- **پارامتر مسیر (`Path`):** `id` (`integer` — شناسه‌ی نوبت یعنی `appointment_id`)
- **فیلدهای بدنه (`JSON Body`):**
  | فیلد | نوع | اجباری | مقادیر مجاز در کد PHP |
  | :--- | :---: | :---: | :--- |
  | `status` | `string` | **بله** | `'pending'`, `'confirmed'`, `'scheduled'`, `'completed'`, `'cancelled'`, `'canceled'`, `'absent'`, `'done'` |
- **نمونه‌ی درخواست `curl` (برای لغو نوبت):**
  ```bash
  curl -X PUT "https://api.famoacademy.ir/api/v1/appointments/101/status" \
    -H "Authorization: Bearer <JWT_TOKEN>" \
    -H "Content-Type: application/json" \
    -d '{"status": "cancelled"}'
  ```
- **پاسخ موفق (`200 OK`):** آبجکت به‌روزشده‌ی نوبت (`AppointmentRecord`) در `data` برگردانده می‌شود.

#### ۲.۴.۴. حذف نوبت: `DELETE /api/v1/appointments/{id}`
- **نقش‌های مجاز:** `admin` و `supporter`
- **پارامتر مسیر (`Path`):** `id` (`integer` — شناسه‌ی نوبت)
- **نمونه‌ی درخواست `curl`:**
  ```bash
  curl -X DELETE "https://api.famoacademy.ir/api/v1/appointments/101" \
    -H "Authorization: Bearer <JWT_TOKEN>"
  ```
- **پاسخ موفق (`200 OK`):**
  ```json
  {
    "success": true,
    "data": null,
    "pagination": null,
    "error": null
  }
  ```

#### جدول خطاهای ۴ اندپوینت `/api/v1/appointments`
| کد HTTP | `error.code` | چه موقع رخ می‌دهد؟ | پیام پیشنهادی فرانت به کاربر |
| :---: | :---: | :--- | :--- |
| `401` | `UNAUTHORIZED` | عدم احراز هویت. | «نشست کاربری منقضی شده است.» |
| `403` | `FORBIDDEN` | نقش کاربر دانش‌آموز یا استاد است. | «شما مجوز مدیریت نوبت‌ها را ندارید.» |
| `404` | `NOT_FOUND` | شناسه‌ی نوبت (`id`) در تغییر وضعیت یا حذف وجود ندارد (`نوبت یافت نشد`). | «نوبت مورد نظر یافت نشد یا قبلاً حذف شده است.» |
| `422` | `VALIDATION_ERROR` | فیلدهای اجباری نوبت ناقص است، فرمت تاریخ/ساعت اشتباه است، یا `status` نامعتبر است. | نمایش `error.message` برگشتی از سرور. |

---

## ۳. مرجع وضعیت‌ها (`Statuses & Badges Reference`)

### ۳.۱. وضعیت پیامک هر شاگرد در `preview` و ارتباط با `mode` در `send`
بک‌اند هنگام اجرای `GET /api/v1/appointments/sms/preview` وضعیت هر شاگرد را با **ترتیب اولویت دقیق زیر** (از ۱ به ۵) تعیین می‌کند (یعنی اگر شرط بالاتر برقرار باشد، شرط‌های پایین‌تر دیگر بررسی نمی‌شوند):

| اولویت | کد وضعیت (`status`) | شرط دقیق برقراری | کدام `mode` در `send` روی آن اثر می‌گذارد؟ | برچسب کوتاه پیشنهادی (Badge) | رنگ پیشنهادی UI |
| :---: | :--- | :--- | :---: | :---: | :---: |
| **۱ (بالاترین)** | `no_appointment` | شاگرد هیچ نوبت فعالی (`pending`, `confirmed`, `scheduled`) در بازه‌ی شنبه تا جمعه‌ی آن هفته ندارد (حتی اگر شماره‌ی والد هم نداشته باشد، وضعیتش `no_appointment` می‌شود نه `no_phone`). | هیچ‌کدام (همیشه Skip می‌شود) | **بدون نوبت** | خاکستری (`slate/neutral`) |
| **۲** | `no_phone` | شاگرد نوبت فعال در هفته دارد، اما یا هیچ رکوردی در `parent_contacts` ندارد و یا شماره‌ی والد اصلی پس از نرمال‌سازی با `PhoneNumber::normalize` نامعتبر است. (هرگز به شماره‌ی خود شاگرد fallback نمی‌شود). | هیچ‌کدام (در حالت `unsent` یک ردیف `skipped_no_phone` در لاگ ثبت می‌شود) | **فاقد شماره والد** | نارنجی / کهربایی (`amber/warning`) |
| **۳-الف** | `already_sent` | شاگرد نوبت فعال و شماره‌ی والد معتبر دارد، آخرین لاگ این هفته `status === 'sent'` است و `log.appointment_at === current.appointment_at`. | هیچ‌کدام (در تمام modeها برای جلوگیری از ارسال تکراری Skip می‌شود) | **ارسال‌شده** | سبز (`emerald/success`) |
| **۳-ب** | `changed_since_sent` | شاگرد نوبت فعال و شماره‌ی والد معتبر دارد، آخرین لاگ این هفته `status === 'sent'` است اما زمان نوبت فعلی (`appointment_at`) با زمان ثبت‌شده در لاگ (`last_appointment_at`) **تفاوت دارد**. | فقط **`mode: "changed"`** | **تغییر زمان نوبت** | بنفش / آبی (`indigo/info`) |
| **۴** | `failed` | شاگرد نوبت فعال و شماره‌ی والد معتبر دارد و آخرین لاگ ثبت‌شده برای این هفته دارای `status === 'failed'` است. | فقط **`mode: "failed"`** | **خطا در ارسال** | قرمز (`rose/danger`) |
| **۵ (پیش‌فرض)** | `will_send` | شاگرد نوبت فعال و شماره‌ی والد معتبر دارد و یا هنوز هیچ لاگی در این هفته ندارد، و یا لاگ قبلی او `skipped_no_phone` بوده و اکنون شماره‌ی والدش ثبت و اصلاح شده است. | فقط **`mode: "unsent"`** | **آماده ارسال** | آبی روشن (`sky/primary`) |

### ۳.۲. وضعیت‌های جدول لاگ (`appointment_sms_log.status`)
| کد وضعیت در لاگ | معنا و زمان ثبت | برچسب کوتاه پیشنهادی (Badge) |
| :--- | :--- | :---: |
| `sent` | پیامک با موفقیت به وب‌سرویس ملی‌پیامک (`BaseServiceNumber` با `RetStatus === 1`) تحویل داده شد. | **ارسال موفق** |
| `failed` | ارسال به دلیل خطای درگاه ملی‌پیامک، خطای شبکه/cURL یا پر شدن سقف Rate Limit با شکست مواجه شد (علت در ستون `error` ذخیره می‌شود). | **ناموفق** |
| `skipped_no_phone` | شاگرد در زمان اجرای ارسال، نوبت فعال داشته اما والد اصلی یا شماره‌ی معتبر والد نداشته است (`mobile = ''`). | **رد شده (بی‌شماره)** |

### ۳.۳. وضعیت‌های خود نوبت (`appointments.status`)
| کد وضعیت نوبت | آیا برای پیامک هفتگی «فعال» محسوب می‌شود؟ | توضیح و کاربرد | برچسب کوتاه پیشنهادی (Badge) |
| :--- | :---: | :--- | :---: |
| `pending` | **بله (فعال)** | وضعیت پیش‌فرض هنگام ایجاد نوبت جدید. | **در انتظار** |
| `confirmed` | **بله (فعال)** | نوبت تأییدشده. | **تأییدشده** |
| `scheduled` | **بله (فعال)** | نوبت زمان‌بندی‌شده. | **زمان‌بندی‌شده** |
| `completed` | خیر (غیرفعال) | جلسه برگزار شده و به پایان رسیده است. | **برگزارشده** |
| `done` | خیر (غیرفعال) | معادل انجام‌شده (سازگاری با عقب). | **انجام‌شده** |
| `cancelled` | خیر (غیرفعال) | نوبت لغوشده (املای بریتانیایی). | **لغوشده** |
| `canceled` | خیر (غیرفعال) | نوبت لغوشده (املای آمریکایی). | **لغوشده** |
| `absent` | خیر (غیرفعال) | شاگرد در جلسه غایب بوده است. | **غایب** |

### ۳.۴. معنی فلگ `multiple_appointments` و قانون انتخاب نوبت
- اگر یک شاگرد در بازه‌ی شنبه تا جمعه‌ی هفته‌ی هدف **بیش از یک نوبت فعال** (`status IN ('pending', 'confirmed', 'scheduled')`) داشته باشد:
  1. فیلد بولی `multiple_appointments` برابر `true` می‌شود و `active_appointments_count` تعداد کل نوبت‌های فعال آن هفته را نشان می‌دهد.
  2. بک‌اند تمام نوبت‌های فعال آن شاگرد در آن هفته را به ترتیب زیر مرتب می‌کند:
     ```sql
     ORDER BY appointment_date ASC, start_time ASC NULLS LAST, id ASC
     ```
  3. **فقط اولین (نزدیک‌ترین / زودترین) نوبت فعال** برای ارسال پیامک و نمایش در `preview` انتخاب می‌شود.
  4. در UI پیشنهاد می‌شود کنار ردیف‌هایی که `multiple_appointments === true` دارند، یک نشانگر هشدار («دارای چند نوبت فعال در هفته») نمایش داده شود تا ادمین در صورت نیاز نوبت‌های اضافی را بررسی یا لغو کند.

---

## ۴. رفتار ارسال (`Send Behavior, Timeouts, Locking & Rate Limits`)

### ۴.۱. اجرای همگام (`Synchronous`) و Timeout پیشنهادی فرانت
- اندپوینت `POST /api/v1/appointments/sms/send` به صورت **کاملاً همگام (Synchronous)** در همان درخواست HTTP اجرا می‌شود و هر شاگرد در یک بلوک `try / catch` مجزا پردازش می‌گردد تا خطای یک شاگرد روند ارسال بقیه را متوقف نکند.
- برای هر شاگرد یک درخواست HTTPS به ملی‌پیامک با `CURLOPT_CONNECTTIMEOUT = 10s` و `CURLOPT_TIMEOUT = 15s` ارسال می‌شود.
- **زمان تقریبی برای ۵۰ شاگرد:** در شرایط نرمال پاسخ‌دهی درگاه ملی‌پیامک (حدود ۲۰۰ تا ۵۰۰ میلی‌ثانیه به ازای هر درخواست)، ارسال ۵۰ شاگرد حدود **۱۰ تا ۲۵ ثانیه** زمان می‌برد؛ اما در صورت اختلال یا کندی شبکه ممکن است طولانی‌تر شود.
- **Timeout پیشنهادی کلاینت فرانت (`axios` / `fetch`):**
  - حتماً timeout درخواست `POST /api/v1/appointments/sms/send` را حداقل روی **۶۰ تا ۹۰ ثانیه (`60000` تا `90000` میلی‌ثانیه)** قرار دهید و از timeout پیش‌فرض کوتاه (مثلاً ۱۰ ثانیه) برای این اندپوینت استفاده نکنید.

### ۴.۲. قفل جلوگیری از دابل‌کلیک (`409 CONCURRENT_SEND_LOCKED`)
بک‌اند دارای دو لایه قفل همزمانی در Redis است:
1. **قفل سطح هفته (`appointment_sms:lock:{weekStart}`):** در طول اجرای حلقه ارسال فعال است (با TTL ایمنی ۳۰ ثانیه در PHP / ۱۲۰ ثانیه در `dev-server`) و در انتهای کار (`finally`) آزاد می‌شود.
2. **قفل شناسه‌ی عملیات (`operation_id`):** اگر فرانت یک `operation_id` ارسال کند، آن شناسه به مدت **۶۰ ثانیه** در Redis قفل می‌شود تا ارسال مجدد همان درخواست بر اثر دابل‌کلیک یا retry خودکار شبکه مسدود شود.
- **نحوه‌ی هندل در فرانت:**
  - به محض کلیک کاربر روی دکمه‌ی ارسال، دکمه را `disabled` و در حالت `isSubmitting = true` قرار دهید.
  - به ازای هر بار باز شدن مودال تأیید ارسال، یک `operation_id` یکتا (مثلاً با `crypto.randomUUID()`) بسازید.
  - اگر کد وضعیت `409` (`CONCURRENT_SEND_LOCKED`) دریافت شد، پیام خطا را به صورت Toast نمایش دهید و پس از ۳ تا ۵ ثانیه `GET /api/v1/appointments/sms/preview` را مجدداً فراخوانی کنید.

### ۴.۳. رفتار محدودیت نرخ (`Rate Limiting`)
محدودیت‌های نرخ این فیچر کاملاً مستقل از پیامک‌های کارنامه (`exam_sms`) و لاگین (`2fa`) هستند:
1. **محدودیت به ازای هر شماره‌ی موبایل والد (`appointment_sms:phone`):**
   - سقف پیش‌فرض: **۳ پیامک در هر ساعت** (`3600` ثانیه) برای هر شماره‌ی موبایل والد (`APPOINTMENT_SMS_PHONE_HOURLY_MAX`).
   - **رفتار در زمان برخورد:** کل درخواست با `429` متوقف **نمی‌شود**؛ بلکه فقط همان شاگرد (یا شاگرد دومی که والد مشترک دارد و سقف شماره‌اش پر شده) در آرایه‌ی خروجی وضعیت `failed` با خطای `"RATE_LIMITED: سقف ارسال پیامک ساعتی برای این شماره پر شده است"` می‌گیرد و ارسال برای سایر شاگردان ادامه می‌یابد.
2. **محدودیت به ازای کاربر ادمین (`appointment_sms:user`):**
   - سقف پیش‌فرض: **۲۰۰ پیامک در هر ساعت** (`3600` ثانیه) برای هر ادمین (`APPOINTMENT_SMS_USER_HOURLY_MAX`).
   - **رفتار در زمان برخورد:**
     - اگر **پیش از شروع حلقه** سقف ساعتی ادمین پر شده باشد، بک‌اند PHP کد وضعیت HTTP **`429 Too Many Requests`** (`error.code = "RATE_LIMITED"`) به همراه هدر `Retry-After` و فیلد `retry_after` برمی‌گرداند.
     - اگر **در میانه‌ی حلقه** (مثلاً در شاگردِ دویست‌ویکم) سقف ادمین پر شود، شاگردان باقی‌مانده وضعیت `failed` با خطای `"RATE_LIMITED: سقف ارسال ساعتی کاربر تکمیل شده است"` می‌گیرند و پاسخ کلی `200 OK` با خلاصه‌ی نتایج برمی‌گردد.

### ۴.۴. رفتار Idempotency (فراخوانی مجدد `send` با همان `mode`)
- اگر `POST /api/v1/appointments/sms/send` دوباره با همان `mode` (و یک `operation_id` جدید پس از اتمام درخواست قبلی) فراخوانی شود:
  - شاگردانی که در بار اول پیامکشان با موفقیت ارسال شده (`status = 'sent'`) و `appointment_at` نوبتشان تغییر نکرده است، به صورت خودکار توسط شرط جلوگیری از ارسال تکراری تشخیص داده می‌شوند و با وضعیت `already_sent` (در `dev-server` با `action: "skipped_already_sent"`) رد (Skip) می‌شوند؛ یعنی **هیچ پیامک تکراری ارسال نخواهد شد** و شمارنده‌ی `sent` برابر `0` خواهد بود.

---

## ۵. اثر جانبی ویرایش، لغو یا حذف نوبت (`Appointment Edit Side-Effects`)

1. **ویرایش ساعت یا تاریخ نوبت پس از ارسال پیامک (`changed_since_sent`):**
   - جدول `appointment_sms_log` در لحظه‌ی ارسال موفق، مقدار `appointment_at` (یعنی `"YYYY-MM-DD HH:MM:SS"`) را ذخیره می‌کند.
   - زمانی که نوبت شاگرد ویرایش می‌شود (مثلاً ساعت شروع از `16:30` به `17:30` تغییر می‌کند)، در فراخوانی بعدی `GET /api/v1/appointments/sms/preview`، بک‌اند مقدار جدید `appointment_at` (`"2026-10-24 17:30:00"`) را با مقدار ذخیره‌شده در لاگ (`"2026-10-24 16:30:00"`) مقایسه می‌کند.
   - چون این دو مقدار نابرابرند، وضعیت شاگرد در `preview` بلافاصله به **`changed_since_sent`** تغییر می‌کند و فیلد `last_appointment_at` (یا `last_sent_appointment_at`) زمان قبلی ارسال‌شده را نگه می‌دارد تا ادمین بتواند هم زمان قبلی و هم زمان جدید را ببیند و با دکمه‌ی «ارسال مجدد تغییرات» (`mode: "changed"`) پیامک اصلاحیه را بفرستد.
2. **حذف (`DELETE`) یا لغو (`status = 'cancelled'`) نوبت پس از ارسال پیامک:**
   - **حالت الف — شاگرد هیچ نوبت فعال دیگری در آن هفته ندارد:**
     - در `GET /api/v1/appointments/sms/preview`، چون شرط `nearest === null` بالاترین اولویت را دارد، وضعیت شاگرد در پیش‌نمایش به **`no_appointment`** تبدیل می‌شود (و دیگر در هیچ‌یک از modeهای ارسال، پیامکی برایش ارسال نمی‌شود).
     - در عین حال، رکورد پیامک قبلی در جدول `appointment_sms_log` دست‌نخورده باقی می‌ماند و در `GET /api/v1/appointments/sms/log` قابل مشاهده است.
   - **حالت ب — شاگرد یک نوبت فعال دیگر در همان هفته دارد:**
     - با حذف یا لغو نوبت اول، نوبت فعال دوم به عنوان نزدیک‌ترین نوبت (`nearest`) انتخاب می‌شود. از آنجا که `appointment_at` این نوبت جدید با `appointment_at` ارسال‌شده‌ی قبلی متفاوت است، وضعیت شاگرد در `preview` به **`changed_since_sent`** تبدیل می‌شود.

---

## ۶. وابستگی‌های صفحه (`Parent Contacts & Client-Side Filtering`)

### ۶.۱. ارتباط `preview` با اطلاعات والد و رفع وضعیت `no_phone`
- در خروجی `GET /api/v1/appointments/sms/preview`، شناسه‌ی رکورد والد (`parent_contact_id`) وجود **ندارد**، اما **`student_id`** همواره موجود است.
- فیلد **`student_id`** برای لینک دادن به بخش مدیریت والدین یا باز کردن مودال «ثبت / ویرایش شماره‌ی والد» کاملاً کافی است:
  1. ابتدا فرانت با داشتن `student_id`، اندپوینت `GET /api/v1/students/{studentId}/parent-contacts` را صدا می‌زند.
  2. اگر لیست برگشتی خالی بود (`[]`)، فرم در حالت **ایجاد مخاطب والد (`POST`)** باز می‌شود (بک‌اند به صورت خودکار اولین والد ثبت‌شده برای شاگرد را `is_primary = 1` قرار می‌دهد).
  3. اگر در لیست برگشتی رکوردی وجود داشت (اولین آیتم لیست همیشه والد اصلی است چون بک‌اند با `ORDER BY is_primary DESC, id ASC` مرتب می‌کند)، فرانت با استفاده از `contact.id` فرم را در حالت **ویرایش (`PUT`)** ارسال می‌کند.
  4. به محض ذخیره شدن شماره‌ی معتبر برای والد اصلی و فراخوانی مجدد `GET /api/v1/appointments/sms/preview`، وضعیت شاگرد از `no_phone` به **`will_send`** تبدیل می‌شود (حتی اگر قبلاً در لاگ `skipped_no_phone` خورده باشد، هنگام ارسال موفق بعدی در `Appointment::upsertSmsLog` رکورد موقت `mobile = ''` پاک‌سازی می‌شود).

### ۶.۲. مستندات کامل اندپوینت‌های `parent_contacts`

#### ۶.۲.۱. دریافت لیست مخاطبین والد یک شاگرد
- **متد و مسیر:** `GET /api/v1/students/{studentId}/parent-contacts`
- **نقش‌های مجاز:** `admin` و `supporter`
- **پارامتر مسیر (`Path`):** `studentId` (`integer` — شناسه‌ی شاگرد)
- **نمونه‌ی درخواست `curl`:**
  ```bash
  curl -X GET "https://api.famoacademy.ir/api/v1/students/20/parent-contacts" \
    -H "Authorization: Bearer <ADMIN_JWT_TOKEN>"
  ```
- **پاسخ موفق (`200 OK`):**
  ```json
  {
    "success": true,
    "data": [
      {
        "id": 1,
        "student_id": 20,
        "parent_name": "بهرام رضایی",
        "relationship": "father",
        "phone": "09120001122",
        "is_primary": 1,
        "created_at": "2026-10-01 10:00:00",
        "updated_at": "2026-10-01 10:00:00"
      }
    ],
    "pagination": null,
    "error": null
  }
  ```

#### ۶.۲.۲. افزودن مخاطب والد جدید برای شاگرد
- **متد و مسیر:** `POST /api/v1/students/{studentId}/parent-contacts`
- **نقش مجاز:** فقط `admin`
- **فیلدهای بدنه (`JSON Body`):**
  | فیلد | نوع | اجباری | مقادیر مجاز / توضیح |
  | :--- | :---: | :---: | :--- |
  | `parent_name` | `string` | **بله** | نام و نام خانوادگی والد (غیرخالی). |
  | `relationship` | `string` | **بله** | یکی از ۴ مقدار: `'father'`, `'mother'`, `'guardian'`, `'other'`. |
  | `phone` | `string` | **بله** | شماره‌ی موبایل ایرانی (ارقام فارسی/عربی و پیش‌شماره‌های `+98`/`0098`/`09` پذیرفته و به `09XXXXXXXXX` نرمال می‌شوند). |
  | `is_primary` | `boolean \| 0 \| 1` | خیر | اگر `true`/`1` باشد (یا شاگرد هیچ والد دیگری نداشته باشد)، این مخاطب به عنوان والد اصلی تنظیم و `is_primary` سایر مخاطبین آن شاگرد `0` می‌شود. |
- **نمونه‌ی درخواست `curl`:**
  ```bash
  curl -X POST "https://api.famoacademy.ir/api/v1/students/22/parent-contacts" \
    -H "Authorization: Bearer <ADMIN_JWT_TOKEN>" \
    -H "Content-Type: application/json" \
    -d '{
      "parent_name": "محمود احمدی",
      "relationship": "father",
      "phone": "09120005566",
      "is_primary": true
    }'
  ```
- **پاسخ موفق (`201 Created`):** آبجکت مخاطب والد ایجادشده در `data` برگردانده می‌شود.

#### ۶.۲.۳. ویرایش مخاطب والد
- **متد و مسیر:** `PUT /api/v1/students/{studentId}/parent-contacts/{id}`
- **نقش مجاز:** فقط `admin`
- **پارامترهای مسیر (`Path`):** `studentId` (`integer`)، `id` (`integer` — شناسه‌ی رکورد `parent_contacts`)
- **فیلدهای بدنه (`JSON Body`):** حداقل یکی از فیلدهای `parent_name`، `relationship`، `phone` یا `is_primary`. (ارسال فیلد اضافی خارج از این ۴ کلید باعث خطای `422 فیلد ارسالی پشتیبانی نمی‌شود` می‌گردد!)
- **نمونه‌ی درخواست `curl`:**
  ```bash
  curl -X PUT "https://api.famoacademy.ir/api/v1/students/20/parent-contacts/1" \
    -H "Authorization: Bearer <ADMIN_JWT_TOKEN>" \
    -H "Content-Type: application/json" \
    -d '{
      "phone": "09120009988",
      "is_primary": true
    }'
  ```
- **پاسخ موفق (`200 OK`):** آبجکت به‌روزشده‌ی مخاطب والد در `data` برگردانده می‌شود.

#### جدول خطاهای اندپوینت‌های `parent_contacts`
| کد HTTP | `error.code` | چه موقع رخ می‌دهد؟ | پیام پیشنهادی فرانت به کاربر |
| :---: | :---: | :--- | :--- |
| `401` | `UNAUTHORIZED` | توکن ارسال نشده یا منقضی است. | «نشست کاربری شما منقضی شده است.» |
| `403` | `FORBIDDEN` | کاربر غیر از `admin` قصد افزودن یا ویرایش والد را دارد. | «فقط مدیر سیستم مجاز به ویرایش اطلاعات تماس والدین است.» |
| `404` | `NOT_FOUND` | `studentId` یا `id` مخاطب والد یافت نشد. | «دانش‌آموز یا مخاطب والد مورد نظر یافت نشد.» |
| `422` | `VALIDATION_ERROR` | شماره موبایل معتبر نیست، نسبت خارج از ۴ مقدار مجاز است، یا فیلد اضافی در بدنه ارسال شده است. | نمایش `error.message` برگشتی از سرور. |

### ۶.۳. فیلتر، جستجو و مرتب‌سازی در `preview` (سمت کلاینت)
- اندپوینت `GET /api/v1/appointments/sms/preview` تنها پارامتر `week_date` را می‌پذیرد و کل لیست شاگردان فعال هفته را یکجا (بدون صفحه‌بندی) برمی‌گرداند.
- بنابراین تمامی عملیات زیر در تب پیش‌نمایش **باید ۱۰۰٪ سمت کلاینت (`Client-Side`) در React انجام شوند:**
  - فیلتر بر اساس تب وضعیت (`همه`، `آماده ارسال (will_send)`، `تغییر زمان (changed_since_sent)`، `خطا (failed)`، `فاقد شماره (no_phone)`، `ارسال‌شده (already_sent)`، `بدون نوبت (no_appointment)`).
  - فیلتر بر اساس پایه (`grade`)، رشته (`field`) یا فلگ `multiple_appointments`.
  - جستجوی متنی روی `student_name`، `parent_name` و شماره‌ی موبایل.
  - مرتب‌سازی جدول (بر اساس نام شاگرد، زمان نوبت `appointment_at` یا وضعیت).
- در مقابل، در تب **«تاریخچه و لاگ ارسال» (`GET /api/v1/appointments/sms/log`)**، فیلترهای `week_date`، `student_id`، `status` و صفحه‌بندی (`page`, `per_page`) **سمت سرور (`Server-Side`)** هستند.

---

## ۷. ترتیب فراخوانی پیشنهادی صفحه (`Recommended Page Call Flow`)

### ۷.۱. لود اولیه‌ی صفحه (`Initial Mount` یا تغییر هفته)
1. تاریخ هفته‌ی جاری (مثلاً تاریخ امروز به فرمت `YYYY-MM-DD`) را در state قرار دهید.
2. فراخوانی `GET /api/v1/appointments/sms/preview?week_date=YYYY-MM-DD`.
3. با دریافت پاسخ، مقدار `data.week_date` (شنبه) و `data.week_end` (جمعه) را به عنوان بازه‌ی رسمی هفته‌ی انتخاب‌شده در هدر صفحه نمایش دهید و کارت‌های خلاصه (`summary`) و جدول شاگردان (`items`) را رندر کنید.
4. (اختیاری) اگر تب «لاگ ارسال» به صورت همزمان در صفحه دیده می‌شود، به صورت موازی (`Promise.all`) اندپوینت `GET /api/v1/appointments/sms/log?week_date=YYYY-MM-DD&page=1&per_page=50` را نیز فراخوانی کنید.

### ۷.۲. جریان ارسال پیامک (`Send Flow`)
1. کاربر یکی از دکمه‌های ارسال را انتخاب می‌کند:
   - «ارسال به ارسال‌نشده‌ها» (`mode: "unsent"`)
   - «تلاش مجدد ناموفق‌ها» (`mode: "failed"`)
   - «ارسال اصلاحیه زمان‌های تغییریافته» (`mode: "changed"`)
   - و یا چند ردیف خاص را با چک‌باکس انتخاب می‌کند (`student_ids: [...]`).
2. یک `operation_id` یکتا تولید کنید و دکمه‌ی ارسال را در حالت Loading/Disabled قرار دهید.
3. فراخوانی `POST /api/v1/appointments/sms/send` با `{ week_date, mode, student_ids, operation_id }` (با timeout حداقل ۶۰ ثانیه).
4. نمایش خلاصه‌ی نتیجه‌ی عملیات (`sent`, `failed`, `skipped`) در یک مودال یا بنر نتیجه.
5. بلافاصله پس از اتمام، `GET /api/v1/appointments/sms/preview?week_date=...` را دوباره فراخوانی کنید تا وضعیت ردیف‌ها و شمارنده‌های بالای صفحه به‌روز شوند (و اگر تب لاگ باز است، `GET /api/v1/appointments/sms/log` را نیز رفرش کنید).

### ۷.۳. جریان ویرایش یا لغو نوبت از داخل جدول پیش‌نمایش
1. **تغییر ساعت نوبت در همان روز:**
   - فراخوانی `POST /api/v1/appointments` با `{ student_id, appointment_date, start_time, end_time, type, description }`.
   - پس از دریافت `201`، فراخوانی مجدد `GET /api/v1/appointments/sms/preview?week_date=...` (اگر قبلاً پیامک ارسال شده بود، وضعیت شاگرد اکنون `changed_since_sent` می‌شود).
2. **انتقال نوبت به یک روز دیگر در همان هفته:**
   - ابتدا حذف نوبت قبلی با `DELETE /api/v1/appointments/{appointment_id}` (یا تغییر وضعیت آن به `cancelled` با `PUT /api/v1/appointments/{appointment_id}/status`).
   - سپس ثبت نوبت در تاریخ جدید با `POST /api/v1/appointments`.
   - در نهایت فراخوانی مجدد `GET /api/v1/appointments/sms/preview?week_date=...`.
3. **لغو نوبت:**
   - فراخوانی `PUT /api/v1/appointments/{appointment_id}/status` با `{"status": "cancelled"}`.
   - فراخوانی مجدد `GET /api/v1/appointments/sms/preview?week_date=...`.

### ۷.۴. جریان ثبت/ویرایش شماره‌ی والد برای ردیف‌های `no_phone`
1. کلیک روی دکمه‌ی «تکمیل شماره والد» در ردیف شاگرد (`student_id`).
2. فراخوانی `GET /api/v1/students/{student_id}/parent-contacts`.
3. اگر آرایه‌ی `data` خالی بود ← ارسال فرم با `POST /api/v1/students/{student_id}/parent-contacts` (`is_primary: true`).
4. اگر آرایه‌ی `data` دارای آیتم بود ← برداشتن `data[0].id` و ارسال فرم با `PUT /api/v1/students/{student_id}/parent-contacts/{id}` (`{ phone, parent_name, relationship, is_primary: true }`).
5. پس از موفقیت، بستن مودال و فراخوانی مجدد `GET /api/v1/appointments/sms/preview?week_date=...` (وضعیت ردیف از `no_phone` به `will_send` تغییر خواهد کرد).

---

## ۸. مغایرت‌ها و موارد مبهم (`Discrepancies & Edge Cases`)

در بررسی دقیق کدهای بک‌اند PHP (`app/Modules/Appointments/*`, `app/Modules/ParentContacts/*`)، سرور تست (`dev-server.mjs`) و فایل `openapi.yaml`، موارد زیر مشاهده شد که بدون تغییر در کد بک‌اند، جهت اطلاع و هندل ایمن در فرانت‌اند گزارش می‌شوند:

1. **تفاوت کلیدهای JSON بین بک‌اند PHP (`AppointmentService.php`) و `dev-server.mjs`:**
   - در `GET /appointments/sms/preview`:
     - تعداد کل در خلاصه: در PHP و `openapi.yaml` کلید `summary.total` است، اما در `dev-server.mjs` کلید `summary.total_students` است.
     - شماره‌ی والد: در PHP و `openapi.yaml` فقط `mobile_mask` (`"0912***22"`) برگردانده می‌شود، اما در `dev-server.mjs` کلید `mobile` (شماره کامل) و `has_valid_phone` برگردانده می‌شود.
     - تاریخ شمسی و روز هفته: در PHP کلیدهای `appointment_jalali` (`"1405/08/02"`) و `weekday` (`"شنبه"`) جداگانه هستند و ساعت‌ها به فرمت `HH:MM` کوتاه شده‌اند؛ در `dev-server.mjs` کلید ترکیبی `day_and_date_fa` (`"شنبه 1405/08/02"`) و `time_fa` (`"16:30"`) وجود دارد و `start_time`/`end_time` به فرمت `HH:MM:SS` هستند.
     - زمان ارسال قبلی: در PHP و `openapi.yaml` کلید `last_appointment_at` است، اما در `dev-server.mjs` کلید `last_sent_appointment_at` است.
   - در `POST /appointments/sms/send`:
     - آرایه‌ی نتایج هر شاگرد در PHP و `openapi.yaml` با نام `data.items` برگردانده می‌شود، اما در `dev-server.mjs` با نام `data.details` است.
   - در `GET /appointments/sms/log`:
     - آرایه‌ی لاگ‌ها در PHP و `openapi.yaml` در `data.items` قرار دارد، اما در `dev-server.mjs` در `data.logs` است. همچنین نام کاربری ادمین در PHP با کلید `created_by_username` و در `dev-server.mjs` با کلید `created_by_name` برمی‌گردد.
2. **مغایرت نام پارامتر `perPage` در `GET /api/v1/appointments`:**
   - در `openapi.yaml` پارامتر صفحه‌بندی `per_page` (snake_case) مستند شده است، اما در `AppointmentController::list` (خط ۲۱) فقط `$params['perPage'] ?? 20` (camelCase) خوانده می‌شود. در مقابل، `AppointmentController::smsLog` هر دو `per_page` و `perPage` را با پیش‌فرض `50` می‌پذیرد (در حالی که `openapi.yaml` پیش‌فرض را `20` نوشته است).
3. **مغایرت مقادیر مجاز `status` نوبت در `openapi.yaml` و کد PHP:**
   - در `openapi.yaml` برای `PUT /api/v1/appointments/{id}/status` فقط ۴ مقدار `[pending, confirmed, completed, cancelled]` درج شده است، اما در `AppointmentController::updateStatus` (خطوط ۱۰۵-۱۰۷) ۸ مقدار `['pending', 'confirmed', 'completed', 'cancelled', 'canceled', 'absent', 'done', 'scheduled']` معتبر هستند و در دیتابیس نوبت‌های با وضعیت `scheduled` نیز وجود دارند.
4. **رفتار Upsert در `POST /api/v1/appointments` و نبود اندپوینت `PUT /api/v1/appointments/{id}`:**
   - در `openapi.yaml` ذکر نشده که `POST /api/v1/appointments` بر اساس `(student_id, appointment_date)` عملیات Upsert انجام می‌دهد. به همین دلیل ویرایش ساعت نوبت در همان روز با `POST /api/v1/appointments` نوبت قبلی را آپدیت می‌کند، اما تغییر تاریخ نوبت به روز دیگر در همان هفته بدون حذف/لغو نوبت قبلی باعث ایجاد نوبت دوم (`multiple_appointments: true`) می‌شود.
5. **فیلدهای غایب در پاسخ `preview` که فرانت باید از آن آگاه باشد:**
   - در آیتم‌های `GET /api/v1/appointments/sms/preview`:
     - فیلد `parent_contact_id` و `relationship` والد وجود ندارد (برای ویرایش والد باید ابتدا با `student_id` اندپوینت `GET /api/v1/students/{studentId}/parent-contacts` فراخوانی شود).
     - فیلدهای `type` و `description` نوبت انتخاب‌شده در خروجی `preview` برگردانده نمی‌شوند (فقط در `GET /api/v1/appointments` هستند).
     - در بک‌اند PHP شماره‌ی والد در `preview` به صورت ماسک‌شده (`mobile_mask`) است؛ بنابراین برای نمایش شماره‌ی کامل در فرم ویرایش والد، فراخوانی `GET /api/v1/students/{studentId}/parent-contacts` ضروری است.
     - اگر شاگرد بیش از یک نوبت فعال در هفته داشته باشد (`multiple_appointments: true`)، در `preview` فقط جزئیات نزدیک‌ترین نوبت برگردانده می‌شود و لیست سایر نوبت‌های فعال آن شاگرد در همان هفته در آیتم `preview` وجود ندارد.
