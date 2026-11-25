# `App\Modules\Sms` — Internal SMS Module

Self-contained SMS delivery, idempotency, status reconciliation, and balance-monitoring module backed by Kavenegar (`https://kavenegar.com/rest.html`) and a deterministic `FakeProvider` for non-production environments and tests.

## Design Principles

- **Zero domain coupling**: Only depends on `App\Core\Database` for the `sms_log` table. Imports no other FAMO domain models, services, or controllers.
- **No Redis, no queue workers**: Uses a single MySQL table (`sms_log`, migration `015_sms_log.sql`) for idempotency and audit state, plus a lightweight CLI scheduled task (`scripts/sms_cron.php`).
- **Single public entry point**: Call `App\Modules\Sms\Sms::send(array $input)`.
- **Provider failures never throw**: Only invalid caller input (`SmsInputException`, thrown before any DB write or provider call) and missing/invalid configuration (`SmsConfigException`) throw exceptions. Network timeouts, provider errors, and unexpected exceptions are persisted in `sms_log` and returned as structured statuses (`failed` or `unknown`).
- **Secret & OTP hygiene**:
  - `KAVENEGAR_API_KEY` is part of every Kavenegar REST URL path; it is never logged, persisted to `sms_log.error`, or printed to stdout/stderr (`Redactor::redactSecrets()`).
  - Any `purpose` starting with `otp` (case-insensitive, e.g. `otp.auth_2fa`) stores `[otp-redacted len=N]` in `sms_log.text` instead of the plaintext message body.

---

## Quick Usage

```php
use App\Modules\Sms\Sms;

// Standard SMS
$res = Sms::send([
    'purpose'  => 'appointment.reminder',
    'receptor' => '09123456789', // accepts +98, 0098, 98, Persian/Arabic digits
    'text'     => 'یادآوری جلسه مشاوره فردا ساعت ۱۰',
    'ref'      => 'appointment:42:24h', // opaque caller idempotency key (<= 191 chars)
]);
// Returns: ['id' => 15, 'status' => 'sent', 'providerMsgId' => '123456789']

// Kavenegar VerifyLookup template (OTP text is automatically redacted in sms_log)
$otpRes = Sms::send([
    'purpose'  => 'otp.auth_2fa',
    'receptor' => '+98 ۹۱۲ ۳۴۵ ۶۷۸۹',
    'text'     => '482910',
    'ref'      => 'login:admin:1:1735000000',
    'template' => 'famo-otp',
    'tokens'   => ['482910'], // up to 3 tokens (positional or token/token2/token3)
]);

// Explicit retry on a previously failed row (reuses the same sms_log row)
$retryRes = Sms::send([
    'purpose'  => 'appointment.reminder',
    'receptor' => '09123456789',
    'text'     => 'یادآوری جلسه مشاوره فردا ساعت ۱۰',
    'ref'      => 'appointment:42:24h',
    'retry'    => true,
]);
```

### Idempotency & Retry Rules

The unique index `uq_sms_log_dedup (client, purpose, receptor, ref)` enforces at-most-once delivery per tuple:

| Existing `sms_log.status` | `retry: false` (default) | `retry: true` |
| --- | --- | --- |
| `pending` / `sent` / `delivered` | Returns existing row (no provider call) | Returns existing row (no provider call) |
| `failed` (definitive rejection) | Returns existing row (`failed`) | Resets row to `pending` and re-sends using the **same** row id |
| `unknown` (timeout / network error) | Returns existing row (`unknown`) | **Never auto-retried** — returns existing row (`unknown`) |

---

## Environment Variables

Configure via `.env` (no database settings, no extra config files):

| Variable | Required | Default | Description |
| --- | --- | --- | --- |
| `SMS_PROVIDER` | No | `kavenegar` in production (`APP_ENV=production` or unset), `fake` everywhere else | Active provider: `kavenegar` or `fake`. |
| `KAVENEGAR_API_KEY` | When `SMS_PROVIDER=kavenegar` | — | Kavenegar REST API key. Secret; never logged or committed. |
| `KAVENEGAR_SENDER` | When `SMS_PROVIDER=kavenegar` | — | Sender line number (e.g. `10004346`). |
| `SMS_LOW_BALANCE_THRESHOLD` | When `SMS_PROVIDER=kavenegar` | `0` (fake) | Threshold in **Iranian Rial** below which `BalanceMonitor::checkBalance()` raises a rate-limited alert. |
| `SMS_REQUEST_TIMEOUT_MS` | No | `10000` (min `1000`) | HTTP request timeout in milliseconds. |
| `SMS_RECONCILE_MIN_AGE_SECONDS` | No | `60` | Minimum age of a `sent`/`unknown` row before reconciliation polls Kavenegar. |
| `SMS_RECONCILE_MAX_AGE_DAYS` | No | `3` | Maximum age of rows included in automatic reconciliation. |
| `SMS_LOW_BALANCE_NOTIFY_MINUTES` | No | `360` | Cooldown window (in minutes) between repeated low-balance / insufficient-credit alerts. |

---

## Kavenegar Status Mapping

### Delivery Status (`sms/status.json` → `sms_log.status`)

| Kavenegar `status` | Meaning | Internal `sms_log.status` |
| --- | --- | --- |
| `1` | Queued for sending | `sent` |
| `2` | Scheduled | `sent` |
| `4`, `5` | Handed over to telecom operator | `sent` |
| `6` | Sending failed | `failed` |
| `10` | Delivered to handset | `delivered` (sets `delivered_at`) |
| `11` | Not delivered (handset off / unreachable) | `failed` |
| `13` | Cancelled / failed (cost refunded) | `failed` |
| `14` | Blocked by recipient (cost refunded) | `failed` |
| `100` | Invalid message id | `unknown` |
| Any other / missing | Unrecognized | `unknown` |

### Send-Time Return Status (`return.status`)

| `return.status` | Classification |
| --- | --- |
| `200` (with valid `entries[0].messageid`) | Accepted (`sent`) |
| `400`, `401`, `402`, `403`, `404`, `405`, `418` | Definitive rejection (`failed`) |
| `409` or any other code / transport failure | Outcome `unknown` (never blindly retried) |

---

## Scheduled Tasks (`scripts/sms_cron.php`)

Run via system `cron` (no queue worker or Redis required):

```cron
# Reconcile delivery statuses every 5 minutes
*/5 * * * * php /path/to/famo-api/scripts/sms_cron.php reconcile --limit=200 >> /path/to/famo-api/storage/logs/sms_cron.log 2>&1

# Check provider balance every 30 minutes
*/30 * * * * php /path/to/famo-api/scripts/sms_cron.php balance >> /path/to/famo-api/storage/logs/sms_cron.log 2>&1
```

Manual operator review of `unknown` rows that have no `provider_msg_id`:

```bash
php scripts/sms_cron.php unknown --limit=100 --json
```

Alert cooldown state for `BalanceMonitor` is stored locally in `storage/logs/sms_balance_alerts.json` and critical alert lines are appended to `storage/logs/app.log`.

---

## Testing Seam

In automated tests or local development, inject `FakeProvider` via `Sms::setProvider(new FakeProvider(...))` and restore with `Sms::setProvider(null)` in `tearDown()`.

```bash
vendor/bin/phpunit tests/Modules/Sms/SmsModuleTest.php
```

---

## Not Verified

The following Kavenegar edge details could not be verified against a live Kavenegar production account during offline implementation and should be confirmed on first live smoke test:

1. **Insufficient-credit `return.status` code (`411` vs `418`)**: `KavenegarProvider::INSUFFICIENT_BALANCE_CODES` currently treats `411` as a best-effort insufficient-balance signal (from third-party Kavenegar SDK tables), while official Kavenegar REST docs list `418` for insufficient credit and `411` for invalid receptor. Verify the exact live `return.status` returned on a depleted account before relying on send-time credit alerts (periodic `account/info.json` polling via `scripts/sms_cron.php balance` is the primary low-balance safeguard).
2. **`account/info.json` balance payload shape**: `KavenegarProvider::getBalance()` defensively checks both `entries.balance` and `entries.account.balance`. Confirm the exact JSON structure returned by your Kavenegar account tier on first `php scripts/sms_cron.php balance` run.
3. **Latin single-segment length (`160` vs `140` chars)**: Kavenegar's Persian documentation page mentions "140 Latin characters" in one section, which conflicts with the standard GSM 03.38 7-bit packing limit (`160` septets single-part / `153` septets multi-part; `70`/`67` code points for UCS-2). `SegmentCounter` implements standard GSM-7 (`160`/`153`) and UCS-2 (`70`/`67`), while `sms_log.provider_cost` remains the billing source of truth.
