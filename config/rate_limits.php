<?php
declare(strict_types=1);

$envInt = static function (string $key, int $default): int {
    $raw = $_ENV[$key] ?? getenv($key);
    if ($raw === false || $raw === null || $raw === '') {
        return $default;
    }
    return max(1, (int) $raw);
};

$envStr = static function (string $key, string $default): string {
    $raw = $_ENV[$key] ?? getenv($key);
    if ($raw === false || $raw === null || trim((string) $raw) === '') {
        return $default;
    }
    return strtolower(trim((string) $raw));
};

return [
    // Auth endpoints (login / verify-2fa / telegram link):
    // Strict per (IP + identifier) to block credential stuffing while keeping IP-only limits
    // generous for shared academy Wi-Fi and mobile CGNAT IPs.
    'auth_user_ip_max'       => $envInt('RATE_LIMIT_AUTH_USER_IP_MAX', 5),
    'auth_user_ip_window'    => $envInt('RATE_LIMIT_AUTH_USER_IP_WINDOW', 60),
    'auth_ip_max'            => $envInt('RATE_LIMIT_AUTH_IP_MAX', 100),
    'auth_ip_window'         => $envInt('RATE_LIMIT_AUTH_IP_WINDOW', 900),

    // SMS-sending endpoints: per-phone (3 / 10 min) and per-IP (10 / hour)
    'sms_phone_max'          => $envInt('RATE_LIMIT_SMS_PHONE_MAX', 3),
    'sms_phone_window'       => $envInt('RATE_LIMIT_SMS_PHONE_WINDOW', 600),
    'sms_ip_max'             => $envInt('RATE_LIMIT_SMS_IP_MAX', 10),
    'sms_ip_window'          => $envInt('RATE_LIMIT_SMS_IP_WINDOW', 3600),

    // General authenticated API per user id (by role)
    'api_student_max'        => $envInt('RATE_LIMIT_API_STUDENT_MAX', 120),
    'api_staff_max'          => $envInt('RATE_LIMIT_API_STAFF_MAX', 300),
    'api_auth_window'        => $envInt('RATE_LIMIT_API_AUTH_WINDOW', 60),

    // Unauthenticated public API per IP
    'public_ip_max'          => $envInt('RATE_LIMIT_PUBLIC_IP_MAX', $envInt('PUBLIC_RATE_LIMIT_MAX', 120)),
    'public_ip_window'       => $envInt('RATE_LIMIT_PUBLIC_IP_WINDOW', $envInt('PUBLIC_RATE_LIMIT_WINDOW', 60)),

    // Public shared exam result link per IP
    'public_exam_ip_max'     => $envInt('RATE_LIMIT_PUBLIC_EXAM_IP_MAX', 20),
    'public_exam_ip_window'  => $envInt('RATE_LIMIT_PUBLIC_EXAM_IP_WINDOW', 60),

    // Public testimonial submission per IP (5 submissions per hour by default)
    'public_testimonial_ip_max'    => $envInt('RATE_LIMIT_PUBLIC_TESTIMONIAL_IP_MAX', 5),
    'public_testimonial_ip_window' => $envInt('RATE_LIMIT_PUBLIC_TESTIMONIAL_IP_WINDOW', 3600),

    // Path prefixes exempt from all per-IP limits (server-to-server bot gateway)
    'exempt_prefixes'        => ['/api/v1/bot'],

    // Revocation check behavior when Redis is unavailable/disabled
    'revocation_fail_mode'       => $envStr('AUTH_REVOCATION_FAIL_MODE', 'open'),
    'revocation_admin_fail_mode' => $envStr('AUTH_REVOCATION_ADMIN_FAIL_MODE', 'closed'),
];
