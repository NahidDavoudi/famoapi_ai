<?php

namespace App\Core;

class SmsService
{
    private string $apiKey;
    private string $sender;
    private string $melipayamakUsername;
    private string $melipayamakPassword;
    private string $melipayamakBodyId;
    private string $melipayamakExamBodyId;
    private string $melipayamakAppointmentBodyId;
    private ?int $overrideAppointmentBodyId = null;
    private ?int $overrideExamBodyId = null;
    private ?int $requestTimeoutOverride = null;
    private ?string $lastError = null;

    public function __construct()
    {
        $this->apiKey = self::env('SMS_API_KEY');
        $this->sender = self::env('SMS_SENDER') ?: '100001';
        $this->melipayamakUsername = self::env('MELIPAYAMAK_USERNAME');
        $this->melipayamakPassword = self::env('MELIPAYAMAK_PASSWORD');
        $this->melipayamakBodyId = self::env('MELIPAYAMAK_PATTERN_BODY_ID');
        // Never fall back appointment or exam pattern IDs to the 2FA login pattern ID.
        $this->melipayamakExamBodyId = self::env('MELIPAYAMAK_EXAM_BODY_ID');
        $this->melipayamakAppointmentBodyId = self::env('MELIPAYAMAK_APPOINTMENT_BODY_ID');
    }

    public function setAppointmentBodyId(?int $bodyId): void
    {
        $this->overrideAppointmentBodyId = ($bodyId !== null && $bodyId > 0) ? $bodyId : null;
    }

    public function setExamBodyId(?int $bodyId): void
    {
        $this->overrideExamBodyId = ($bodyId !== null && $bodyId > 0) ? $bodyId : null;
    }

    public function setRequestTimeoutOverride(?int $timeoutSeconds): void
    {
        $this->requestTimeoutOverride = ($timeoutSeconds !== null && $timeoutSeconds > 0) ? $timeoutSeconds : null;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private static function env(string $key): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($val) && !is_numeric($val)) {
            return '';
        }
        return trim((string) $val);
    }

    public function send(string $phone, string $message): bool
    {
        $this->lastError = null;
        $phone = preg_replace('/[^0-9]/', '', $phone);

        $url = "https://api.kavenegar.com/v1/{$this->apiKey}/sms/send.json";

        $postData = http_build_query([
            'receptor' => $phone,
            'sender'   => $this->sender,
            'message'  => $message,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("SmsService cURL error: {$error}");
            return false;
        }

        if ($httpCode !== 200) {
            error_log("SmsService HTTP {$httpCode}: {$response}");
            return false;
        }

        return true;
    }

    /**
     * Dispatches a weekly appointment reminder to the primary parent via Melipayamak pattern API.
     * Pattern variables are semicolon-delimited: "{studentName};{dayAndDate};{time}".
     */
    public function sendAppointmentReminder(string $mobile, string $studentName, string $dayAndDate, string $time): bool
    {
        $this->lastError = null;
        $logPath = __DIR__ . '/../../storage/logs/app.log';

        $bodyId = $this->overrideAppointmentBodyId
            ?? SmsPatternSettings::resolveBodyId(SmsPatternSettings::KEY_APPOINTMENT_REMINDER)
            ?? SmsPatternSettings::parsePositiveInt($this->melipayamakAppointmentBodyId);

        if ($bodyId === null || $bodyId <= 0) {
            $this->lastError = 'کد پترن پیامک نوبت تنظیم نشده است (تنظیمات پنل ادمین یا MELIPAYAMAK_APPOINTMENT_BODY_ID).';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Appointment pattern bodyId is not configured (refusing 2FA fallback).' . PHP_EOL, 3, $logPath);
            return false;
        }

        if (empty($this->melipayamakUsername) || empty($this->melipayamakPassword)) {
            $this->lastError = 'اطلاعات اتصال درگاه ملی‌پیامک (نام کاربری/رمز عبور) تنظیم نشده است.';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Melipayamak credentials not configured for appointment reminder.' . PHP_EOL, 3, $logPath);
            return false;
        }

        $normalized = PhoneNumber::normalize($mobile);
        if ($normalized === null || !preg_match('/^09[0-9]{9}$/', $normalized)) {
            $this->lastError = 'فرمت شماره موبایل والد معتبر نیست.';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Invalid parent mobile format for appointment reminder.' . PHP_EOL, 3, $logPath);
            return false;
        }

        // Sanitize semicolon delimiters in parameters to prevent pattern parameter injection
        $safeName       = str_replace([';', "\r", "\n"], ' ', trim($studentName));
        $safeDayAndDate = str_replace([';', "\r", "\n"], ' ', trim($dayAndDate));
        $safeTime       = str_replace([';', "\r", "\n"], '', trim($time));
        $patternText    = "{$safeName};{$safeDayAndDate};{$safeTime}";

        return $this->sendMelipayamakPattern($normalized, (int) $bodyId, $patternText, 'Appointment reminder');
    }

    public function sendAbsenceReminder(string $phone, string $studentName, string $parentName): bool
    {
        $message = "سلام {$parentName} عزیز، فرزند شما {$studentName} هنوز برای هفته جاری نوبت رزرو نکرده است. لطفاً هرچه سریعتر اقدام کنید.";
        return $this->send($phone, $message);
    }

    public function sendVerificationCode(string $mobile, string $code): bool
    {
        $this->lastError = null;
        $bodyId = SmsPatternSettings::resolveBodyId(SmsPatternSettings::KEY_AUTH_2FA)
            ?? SmsPatternSettings::parsePositiveInt($this->melipayamakBodyId);

        if (empty($this->melipayamakUsername) || empty($this->melipayamakPassword) || $bodyId === null || $bodyId <= 0) {
            $this->lastError = 'تنظیمات درگاه پیامک برای ارسال کد تایید کامل نیست.';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Melipayamak not fully configured.' . PHP_EOL, 3, __DIR__ . '/../../storage/logs/app.log');
            return false;
        }

        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
            $this->lastError = 'فرمت شماره موبایل معتبر نیست.';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Invalid mobile format for 2FA.' . PHP_EOL, 3, __DIR__ . '/../../storage/logs/app.log');
            return false;
        }

        return $this->sendMelipayamakPattern($mobile, (int) $bodyId, $code, '2FA code', $this->requestTimeoutOverride);
    }

    /**
     * Dispatches a parent shareable exam result link via Melipayamak pattern API.
     * Pattern variables are semicolon-delimited: "{studentName};{examDate};{shareUrl}".
     */
    public function sendExamResultLink(string $mobile, string $studentName, string $examDate, string $shareUrl): bool
    {
        $this->lastError = null;
        $logPath = __DIR__ . '/../../storage/logs/app.log';
        $bodyId = $this->overrideExamBodyId
            ?? SmsPatternSettings::resolveBodyId(SmsPatternSettings::KEY_EXAM_RESULT)
            ?? SmsPatternSettings::parsePositiveInt($this->melipayamakExamBodyId);

        if ($bodyId === null || $bodyId <= 0) {
            $this->lastError = 'کد پترن پیامک کارنامه آزمون تنظیم نشده است (تنظیمات پنل ادمین یا MELIPAYAMAK_EXAM_BODY_ID).';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Exam pattern bodyId is not configured (refusing 2FA fallback).' . PHP_EOL, 3, $logPath);
            return false;
        }

        if (empty($this->melipayamakUsername) || empty($this->melipayamakPassword)) {
            $this->lastError = 'اطلاعات اتصال درگاه ملی‌پیامک (نام کاربری/رمز عبور) تنظیم نشده است.';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Melipayamak credentials not configured for exam result link.' . PHP_EOL, 3, $logPath);
            return false;
        }

        $normalized = PhoneNumber::normalize($mobile);
        if ($normalized === null || !preg_match('/^09[0-9]{9}$/', $normalized)) {
            $this->lastError = 'فرمت شماره موبایل والد معتبر نیست.';
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Invalid parent mobile format for exam result link.' . PHP_EOL, 3, $logPath);
            return false;
        }

        // Sanitize semicolon delimiters in parameters to prevent pattern parameter injection
        $safeName = str_replace([';', "\r", "\n"], ' ', trim($studentName));
        $safeDate = str_replace([';', "\r", "\n"], '', trim($examDate));
        $safeUrl  = str_replace([';', "\r", "\n"], '', trim($shareUrl));
        $patternText = "{$safeName};{$safeDate};{$safeUrl}";

        return $this->sendMelipayamakPattern($normalized, (int) $bodyId, $patternText, 'Exam result link');
    }

    private function sendMelipayamakPattern(string $mobile, int $bodyId, string $text, string $contextLabel, ?int $timeoutSeconds = null): bool
    {
        $url = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
        $jsonData = json_encode([
            'username' => $this->melipayamakUsername,
            'password' => $this->melipayamakPassword,
            'to'       => $mobile,
            'bodyId'   => $bodyId,
            'text'     => $text,
        ], JSON_UNESCAPED_UNICODE);

        $timeout = $timeoutSeconds !== null ? max(1, $timeoutSeconds) : 15;
        $connectTimeout = $timeoutSeconds !== null ? min($timeout, 4) : 10;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => $jsonData,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $logPath = __DIR__ . '/../../storage/logs/app.log';

        if ($curlError) {
            $this->lastError = 'خطا در برقراری ارتباط با ملی‌پیامک: ' . $curlError;
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService cURL error (' . $contextLabel . '): ' . $curlError . PHP_EOL, 3, $logPath);
            return false;
        }

        if ($httpCode !== 200) {
            $this->lastError = "خطای HTTP {$httpCode} از درگاه ملی‌پیامک";
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService HTTP ' . $httpCode . ' (' . $contextLabel . ')' . PHP_EOL, 3, $logPath);
            return false;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || !isset($decoded['RetStatus']) || (int) $decoded['RetStatus'] !== 1) {
            $errMsg = is_array($decoded) ? ($decoded['StrRetStatus'] ?? 'unknown') : (string) $response;
            $this->lastError = 'درگاه ملی‌پیامک ارسال را رد کرد: ' . $errMsg;
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService Melipayamak rejected (' . $contextLabel . '): ' . $errMsg . PHP_EOL, 3, $logPath);
            return false;
        }

        error_log('[' . date('Y-m-d H:i:s') . '] SmsService: ' . $contextLabel . ' (bodyId=' . $bodyId . ') sent to ' . substr($mobile, 0, 4) . '***' . PHP_EOL, 3, $logPath);
        return true;
    }
}