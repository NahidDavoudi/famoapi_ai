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

    public function __construct()
    {
        $this->apiKey = $_ENV['SMS_API_KEY'] ?? '';
        $this->sender = $_ENV['SMS_SENDER'] ?? '100001';
        $this->melipayamakUsername = $_ENV['MELIPAYAMAK_USERNAME'] ?? '';
        $this->melipayamakPassword = $_ENV['MELIPAYAMAK_PASSWORD'] ?? '';
        $this->melipayamakBodyId = $_ENV['MELIPAYAMAK_PATTERN_BODY_ID'] ?? '';
        $this->melipayamakExamBodyId = $_ENV['MELIPAYAMAK_EXAM_BODY_ID'] ?? $this->melipayamakBodyId;
        $this->melipayamakAppointmentBodyId = $_ENV['MELIPAYAMAK_APPOINTMENT_BODY_ID'] ?? $this->melipayamakBodyId;
    }

    public function send(string $phone, string $message): bool
    {
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
        $logPath = __DIR__ . '/../../storage/logs/app.log';
        $bodyId = $this->melipayamakAppointmentBodyId !== '' ? $this->melipayamakAppointmentBodyId : $this->melipayamakBodyId;

        if (empty($this->melipayamakUsername) || empty($this->melipayamakPassword) || empty($bodyId)) {
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Melipayamak not configured for appointment reminder.' . PHP_EOL, 3, $logPath);
            return false;
        }

        $normalized = PhoneNumber::normalize($mobile);
        if ($normalized === null || !preg_match('/^09[0-9]{9}$/', $normalized)) {
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
        if (empty($this->melipayamakUsername) || empty($this->melipayamakPassword) || empty($this->melipayamakBodyId)) {
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Melipayamak not fully configured.' . PHP_EOL, 3, __DIR__ . '/../../storage/logs/app.log');
            return false;
        }

        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Invalid mobile format for 2FA.' . PHP_EOL, 3, __DIR__ . '/../../storage/logs/app.log');
            return false;
        }

        return $this->sendMelipayamakPattern($mobile, (int) $this->melipayamakBodyId, $code, '2FA code');
    }

    /**
     * Dispatches a parent shareable exam result link via Melipayamak pattern API.
     * Pattern variables are semicolon-delimited: "{studentName};{examDate};{shareUrl}".
     */
    public function sendExamResultLink(string $mobile, string $studentName, string $examDate, string $shareUrl): bool
    {
        $logPath = __DIR__ . '/../../storage/logs/app.log';
        $bodyId = $this->melipayamakExamBodyId !== '' ? $this->melipayamakExamBodyId : $this->melipayamakBodyId;

        if (empty($this->melipayamakUsername) || empty($this->melipayamakPassword) || empty($bodyId)) {
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService: Melipayamak not configured for exam result link.' . PHP_EOL, 3, $logPath);
            return false;
        }

        $normalized = PhoneNumber::normalize($mobile);
        if ($normalized === null || !preg_match('/^09[0-9]{9}$/', $normalized)) {
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

    private function sendMelipayamakPattern(string $mobile, int $bodyId, string $text, string $contextLabel): bool
    {
        $url = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
        $jsonData = json_encode([
            'username' => $this->melipayamakUsername,
            'password' => $this->melipayamakPassword,
            'to'       => $mobile,
            'bodyId'   => $bodyId,
            'text'     => $text,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => $jsonData,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $logPath = __DIR__ . '/../../storage/logs/app.log';

        if ($curlError) {
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService cURL error (' . $contextLabel . '): ' . $curlError . PHP_EOL, 3, $logPath);
            return false;
        }

        if ($httpCode !== 200) {
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService HTTP ' . $httpCode . ' (' . $contextLabel . ')' . PHP_EOL, 3, $logPath);
            return false;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || !isset($decoded['RetStatus']) || (int) $decoded['RetStatus'] !== 1) {
            $errMsg = is_array($decoded) ? ($decoded['StrRetStatus'] ?? 'unknown') : (string) $response;
            error_log('[' . date('Y-m-d H:i:s') . '] SmsService Melipayamak rejected (' . $contextLabel . '): ' . $errMsg . PHP_EOL, 3, $logPath);
            return false;
        }

        error_log('[' . date('Y-m-d H:i:s') . '] SmsService: ' . $contextLabel . ' sent to ' . substr($mobile, 0, 4) . '***' . PHP_EOL, 3, $logPath);
        return true;
    }
}