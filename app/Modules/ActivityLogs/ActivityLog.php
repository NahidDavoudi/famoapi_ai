<?php

declare(strict_types=1);

namespace App\Modules\ActivityLogs;

use App\Core\ClientIp;
use App\Core\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ActivityLog
{
    private static bool $tableEnsured = false;

    /** @var array<int, array{name: ?string, role: string, linked_id: ?int}> */
    private static array $actorCache = [];

    private static function ensureTable(PDO $db): void
    {
        if (self::$tableEnsured) {
            return;
        }

        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS staff_activity_logs (
                    id BIGSERIAL PRIMARY KEY,
                    actor_user_id INT NOT NULL,
                    actor_role VARCHAR(32) NOT NULL,
                    actor_linked_id INT NULL,
                    actor_name VARCHAR(150) NULL,
                    module VARCHAR(64) NOT NULL,
                    action VARCHAR(64) NOT NULL,
                    target_type VARCHAR(64) NULL,
                    target_id VARCHAR(64) NULL,
                    summary VARCHAR(500) NOT NULL,
                    http_method VARCHAR(10) NOT NULL DEFAULT 'POST',
                    endpoint VARCHAR(255) NOT NULL,
                    status_code SMALLINT NOT NULL DEFAULT 200,
                    ip_address VARCHAR(64) NULL,
                    details JSONB NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );
        } else {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `staff_activity_logs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `actor_user_id` INT UNSIGNED NOT NULL,
                    `actor_role` VARCHAR(32) NOT NULL COMMENT 'admin | supporter | teacher',
                    `actor_linked_id` INT UNSIGNED NULL DEFAULT NULL,
                    `actor_name` VARCHAR(150) NULL DEFAULT NULL,
                    `module` VARCHAR(64) NOT NULL,
                    `action` VARCHAR(64) NOT NULL,
                    `target_type` VARCHAR(64) NULL DEFAULT NULL,
                    `target_id` VARCHAR(64) NULL DEFAULT NULL,
                    `summary` VARCHAR(500) NOT NULL,
                    `http_method` VARCHAR(10) NOT NULL DEFAULT 'POST',
                    `endpoint` VARCHAR(255) NOT NULL,
                    `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
                    `details` JSON NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_staff_activity_actor` (`actor_user_id`, `actor_role`),
                    KEY `idx_staff_activity_role_date` (`actor_role`, `created_at`),
                    KEY `idx_staff_activity_module_date` (`module`, `created_at`),
                    KEY `idx_staff_activity_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        self::$tableEnsured = true;
    }

    /**
     * Resolve display name and linked profile ID for an actor user.
     *
     * @return array{name: ?string, role: string, linked_id: ?int}
     */
    public static function resolveActorProfile(int $userId, string $role = '', ?int $linkedId = null): array
    {
        if ($userId > 0 && isset(self::$actorCache[$userId])) {
            return self::$actorCache[$userId];
        }

        $resolvedName = null;
        $resolvedRole = strtolower(trim($role));
        $resolvedLinkedId = $linkedId;

        if ($userId > 0) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare('SELECT id, username, role, linked_id FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $userId]);
                $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($userRow)) {
                    if ($resolvedRole === '' && !empty($userRow['role'])) {
                        $resolvedRole = strtolower((string) $userRow['role']);
                    }
                    if ($resolvedLinkedId === null && isset($userRow['linked_id']) && $userRow['linked_id'] !== null) {
                        $resolvedLinkedId = (int) $userRow['linked_id'];
                    }
                    $resolvedName = (string) ($userRow['username'] ?? '');
                }

                if ($resolvedLinkedId !== null && $resolvedLinkedId > 0) {
                    if ($resolvedRole === 'admin') {
                        $pStmt = $db->prepare('SELECT name FROM admins WHERE id = :id LIMIT 1');
                        $pStmt->execute([':id' => $resolvedLinkedId]);
                        $name = $pStmt->fetchColumn();
                        if (is_string($name) && trim($name) !== '') {
                            $resolvedName = trim($name);
                        }
                    } elseif ($resolvedRole === 'supporter') {
                        $pStmt = $db->prepare('SELECT name FROM supporters WHERE id = :id LIMIT 1');
                        $pStmt->execute([':id' => $resolvedLinkedId]);
                        $name = $pStmt->fetchColumn();
                        if (is_string($name) && trim($name) !== '') {
                            $resolvedName = trim($name);
                        }
                    } elseif ($resolvedRole === 'teacher') {
                        $pStmt = $db->prepare('SELECT name FROM instructors WHERE id = :id LIMIT 1');
                        $pStmt->execute([':id' => $resolvedLinkedId]);
                        $name = $pStmt->fetchColumn();
                        if (is_string($name) && trim($name) !== '') {
                            $resolvedName = trim($name);
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore lookup failure
            }
        }

        $result = [
            'name'      => ($resolvedName !== null && $resolvedName !== '') ? $resolvedName : null,
            'role'      => $resolvedRole !== '' ? $resolvedRole : 'staff',
            'linked_id' => $resolvedLinkedId,
        ];

        if ($userId > 0) {
            self::$actorCache[$userId] = $result;
        }

        return $result;
    }

    /**
     * Record a staff activity entry directly.
     */
    public static function record(array $entry): array
    {
        $db = Database::getConnection();
        self::ensureTable($db);

        $actorUserId = isset($entry['actor_user_id']) ? (int) $entry['actor_user_id'] : 0;
        $actorRole = strtolower(trim((string) ($entry['actor_role'] ?? 'admin')));
        $actorLinkedId = isset($entry['actor_linked_id']) && $entry['actor_linked_id'] !== null
            ? (int) $entry['actor_linked_id']
            : null;
        $actorName = isset($entry['actor_name']) && trim((string) $entry['actor_name']) !== ''
            ? trim((string) $entry['actor_name'])
            : null;

        if ($actorName === null && $actorUserId > 0) {
            $profile = self::resolveActorProfile($actorUserId, $actorRole, $actorLinkedId);
            $actorName = $profile['name'];
            if ($actorLinkedId === null) {
                $actorLinkedId = $profile['linked_id'];
            }
        }

        $module = strtolower(trim((string) ($entry['module'] ?? 'system')));
        $action = strtolower(trim((string) ($entry['action'] ?? 'action')));
        $targetType = isset($entry['target_type']) && $entry['target_type'] !== null
            ? (string) $entry['target_type']
            : null;
        $targetId = isset($entry['target_id']) && $entry['target_id'] !== null && $entry['target_id'] !== ''
            ? (string) $entry['target_id']
            : null;
        $summary = trim((string) ($entry['summary'] ?? 'ثبت فعالیت در سیستم'));
        $httpMethod = strtoupper(trim((string) ($entry['http_method'] ?? 'POST')));
        $endpoint = trim((string) ($entry['endpoint'] ?? '/api/v1'));
        $statusCode = isset($entry['status_code']) ? (int) $entry['status_code'] : 200;
        $ipAddress = isset($entry['ip_address']) && $entry['ip_address'] !== null
            ? (string) $entry['ip_address']
            : null;
        $details = $entry['details'] ?? null;
        $createdAt = gmdate('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO staff_activity_logs
                (actor_user_id, actor_role, actor_linked_id, actor_name, module, action, target_type, target_id, summary, http_method, endpoint, status_code, ip_address, details, created_at)
             VALUES
                (:actor_user_id, :actor_role, :actor_linked_id, :actor_name, :module, :action, :target_type, :target_id, :summary, :http_method, :endpoint, :status_code, :ip_address, :details, :created_at)'
        );
        $stmt->execute([
            ':actor_user_id'   => $actorUserId,
            ':actor_role'      => $actorRole,
            ':actor_linked_id' => $actorLinkedId,
            ':actor_name'      => $actorName,
            ':module'          => $module,
            ':action'          => $action,
            ':target_type'     => $targetType,
            ':target_id'       => $targetId,
            ':summary'         => mb_substr($summary, 0, 500),
            ':http_method'     => substr($httpMethod, 0, 10),
            ':endpoint'        => substr($endpoint, 0, 255),
            ':status_code'     => $statusCode,
            ':ip_address'     => $ipAddress !== null ? substr($ipAddress, 0, 64) : null,
            ':details'         => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':created_at'      => $createdAt,
        ]);

        $id = (int) $db->lastInsertId();

        return self::formatRow([
            'id'              => $id,
            'actor_user_id'   => $actorUserId,
            'actor_role'      => $actorRole,
            'actor_linked_id' => $actorLinkedId,
            'actor_name'      => $actorName,
            'module'          => $module,
            'action'          => $action,
            'target_type'     => $targetType,
            'target_id'       => $targetId,
            'summary'         => $summary,
            'http_method'     => $httpMethod,
            'endpoint'        => $endpoint,
            'status_code'     => $statusCode,
            'ip_address'      => $ipAddress,
            'details'         => $details,
            'created_at'      => $createdAt,
        ]);
    }

    /**
     * Automatically inspect an authenticated HTTP request/response and log state-changing
     * actions performed by admins, supporters, or teachers.
     */
    public static function recordFromRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        object $user
    ): void {
        $role = strtolower((string) ($user->role ?? ''));
        if (!in_array($role, ['admin', 'supporter', 'teacher'], true)) {
            return;
        }

        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            return;
        }

        $path = $request->getUri()->getPath();
        if ($path === '/api/v1/auth/logout') {
            return;
        }

        $rawBody = $request->getParsedBody();
        $body = is_array($rawBody) ? self::sanitizePayload($rawBody) : [];

        $responseData = null;
        try {
            $bodyStream = $response->getBody();
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
                $contents = $bodyStream->getContents();
                $bodyStream->rewind();
                $decodedResp = json_decode($contents, true);
                if (is_array($decodedResp) && isset($decodedResp['data']) && is_array($decodedResp['data'])) {
                    $responseData = self::sanitizePayload($decodedResp['data']);
                }
            }
        } catch (\Throwable) {
            $responseData = null;
        }

        $descriptor = self::describeEndpoint($method, $path, $body, $responseData);

        $userId = isset($user->sub) ? (int) $user->sub : (isset($user->id) ? (int) $user->id : 0);
        $linkedId = isset($user->linked_id) && $user->linked_id !== null ? (int) $user->linked_id : null;
        $actorProfile = self::resolveActorProfile($userId, $role, $linkedId);

        $details = [];
        if (!empty($body)) {
            $details['request'] = $body;
        }
        if (is_array($responseData) && !empty($responseData)) {
            $details['result'] = array_intersect_key(
                $responseData,
                array_flip(['id', 'name', 'class_name', 'title', 'status', 'student_id', 'supporter_id', 'instructor_id', 'classroom_id', 'date', 'start_time', 'end_time', 'sent_count'])
            );
        }

        self::record([
            'actor_user_id'   => $userId,
            'actor_role'      => $role,
            'actor_linked_id' => $actorProfile['linked_id'],
            'actor_name'      => $actorProfile['name'],
            'module'          => $descriptor['module'],
            'action'          => $descriptor['action'],
            'target_type'     => $descriptor['target_type'],
            'target_id'       => $descriptor['target_id'],
            'summary'         => $descriptor['summary'],
            'http_method'     => $method,
            'endpoint'        => $path,
            'status_code'     => $statusCode,
            'ip_address'      => ClientIp::fromRequest($request),
            'details'         => !empty($details) ? $details : null,
        ]);
    }

    /**
     * Map an HTTP method + path + payload to module, action, target_type, target_id, and Persian summary.
     *
     * @return array{module: string, action: string, target_type: ?string, target_id: ?string, summary: string}
     */
    public static function describeEndpoint(
        string $method,
        string $path,
        array $body = [],
        ?array $responseData = null
    ): array {
        $cleanPath = preg_replace('#^/api/v1/?#', '', trim($path)) ?? '';
        $segments = array_values(array_filter(explode('/', $cleanPath), static fn($s) => $s !== ''));
        $first = $segments[0] ?? 'system';
        $second = $segments[1] ?? null;
        $third = $segments[2] ?? null;

        $extractId = static function (?string $segment) use ($body, $responseData): ?string {
            if ($segment !== null && ctype_digit($segment)) {
                return $segment;
            }
            if (isset($responseData['id']) && is_scalar($responseData['id'])) {
                return (string) $responseData['id'];
            }
            if (isset($body['id']) && is_scalar($body['id'])) {
                return (string) $body['id'];
            }
            return null;
        };

        // 1. Appointments
        if ($first === 'appointments') {
            if ($second === 'sms' && $third === 'send') {
                return [
                    'module'      => 'appointments',
                    'action'      => 'send_sms_reminders',
                    'target_type' => 'appointment_sms',
                    'target_id'   => null,
                    'summary'     => 'ارسال پیامک یادآوری هفتگی نوبت‌ها',
                ];
            }
            if ($second !== null && ctype_digit($second) && $third === 'status') {
                $newStatus = (string) ($body['status'] ?? $responseData['status'] ?? '');
                return [
                    'module'      => 'appointments',
                    'action'      => 'update_status',
                    'target_type' => 'appointment',
                    'target_id'   => $second,
                    'summary'     => sprintf('تغییر وضعیت نوبت #%s%s', $second, $newStatus !== '' ? " به «{$newStatus}»" : ''),
                ];
            }
            if ($method === 'POST') {
                $id = $extractId(null);
                $studentId = $body['student_id'] ?? $responseData['student_id'] ?? null;
                return [
                    'module'      => 'appointments',
                    'action'      => 'create',
                    'target_type' => 'appointment',
                    'target_id'   => $id,
                    'summary'     => sprintf(
                        'ثبت نوبت مشاوره جدید%s%s',
                        $id !== null ? " (#{$id})" : '',
                        $studentId !== null ? " برای دانش‌آموز #{$studentId}" : ''
                    ),
                ];
            }
            if ($method === 'PUT' || $method === 'PATCH') {
                return [
                    'module'      => 'appointments',
                    'action'      => 'update',
                    'target_type' => 'appointment',
                    'target_id'   => $second,
                    'summary'     => sprintf('ویرایش نوبت مشاوره #%s', $second ?? ''),
                ];
            }
            if ($method === 'DELETE') {
                return [
                    'module'      => 'appointments',
                    'action'      => 'delete',
                    'target_type' => 'appointment',
                    'target_id'   => $second,
                    'summary'     => sprintf('حذف نوبت مشاوره #%s', $second ?? ''),
                ];
            }
        }

        // 2. Tutoring
        if ($first === 'tutoring') {
            if ($second === 'status') {
                $statusVal = (string) ($body['status'] ?? $responseData['status'] ?? '');
                return [
                    'module'      => 'tutoring',
                    'action'      => 'update_instructor_status',
                    'target_type' => 'instructor_status',
                    'target_id'   => isset($body['instructor_id']) ? (string) $body['instructor_id'] : null,
                    'summary'     => sprintf('تغییر وضعیت حضور در کلاس رفع اشکال%s', $statusVal !== '' ? " به «{$statusVal}»" : ''),
                ];
            }
            if ($second === 'sessions' && $method === 'POST' && $third === null) {
                $studentId = $body['student_id'] ?? $responseData['student_id'] ?? null;
                $sessionId = $extractId(null);
                return [
                    'module'      => 'tutoring',
                    'action'      => 'start_session',
                    'target_type' => 'tutoring_session',
                    'target_id'   => $sessionId,
                    'summary'     => sprintf('ثبت ورود دانش‌آموز%s به جلسه رفع اشکال', $studentId !== null ? " #{$studentId}" : ''),
                ];
            }
            if ($second === 'sessions' && $third !== null && ($segments[3] ?? '') === 'end') {
                return [
                    'module'      => 'tutoring',
                    'action'      => 'end_session',
                    'target_type' => 'tutoring_session',
                    'target_id'   => $third,
                    'summary'     => sprintf('ثبت خروج و پایان جلسه رفع اشکال #%s', $third),
                ];
            }
            if ($second === 'classrooms') {
                $classTitle = (string) ($body['class_name'] ?? $body['name'] ?? $responseData['class_name'] ?? $responseData['name'] ?? '');
                if ($method === 'POST') {
                    return [
                        'module'      => 'tutoring',
                        'action'      => 'create_classroom',
                        'target_type' => 'tutoring_classroom',
                        'target_id'   => $extractId(null),
                        'summary'     => sprintf('ایجاد ستون کلاس رفع اشکال جدید%s', $classTitle !== '' ? " «{$classTitle}»" : ''),
                    ];
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    return [
                        'module'      => 'tutoring',
                        'action'      => 'update_classroom',
                        'target_type' => 'tutoring_classroom',
                        'target_id'   => $third,
                        'summary'     => sprintf('ویرایش پیکربندی کلاس رفع اشکال #%s%s', $third ?? '', $classTitle !== '' ? " «{$classTitle}»" : ''),
                    ];
                }
                if ($method === 'DELETE') {
                    return [
                        'module'      => 'tutoring',
                        'action'      => 'delete_classroom',
                        'target_type' => 'tutoring_classroom',
                        'target_id'   => $third,
                        'summary'     => sprintf('حذف ستون کلاس رفع اشکال #%s', $third ?? ''),
                    ];
                }
            }
        }

        // 3. Attendance
        if ($first === 'attendance') {
            if ($second === 'entries') {
                $studentId = $body['student_id'] ?? null;
                return [
                    'module'      => 'attendance',
                    'action'      => 'upsert_entry',
                    'target_type' => 'attendance_entry',
                    'target_id'   => $studentId !== null ? (string) $studentId : $extractId(null),
                    'summary'     => sprintf('ثبت/ویرایش حضور و غیاب دانش‌آموز%s', $studentId !== null ? " #{$studentId}" : ''),
                ];
            }
            if ($second !== null && ctype_digit($second) && $third === 'times') {
                return [
                    'module'      => 'attendance',
                    'action'      => 'set_times',
                    'target_type' => 'attendance_entry',
                    'target_id'   => $second,
                    'summary'     => sprintf('ثبت ساعت ورود/خروج حضور و غیاب ردیف #%s', $second),
                ];
            }
            if ($second === 'guests') {
                $guestName = (string) ($body['name'] ?? '');
                return [
                    'module'      => 'attendance',
                    'action'      => 'add_guest',
                    'target_type' => 'attendance_guest',
                    'target_id'   => $extractId(null),
                    'summary'     => sprintf('افزودن مهمان به لیست حضور و غیاب%s', $guestName !== '' ? " ({$guestName})" : ''),
                ];
            }
        }

        // 4. Students
        if ($first === 'students') {
            if ($second !== null && ctype_digit($second) && $third === 'create-account') {
                return [
                    'module'      => 'students',
                    'action'      => 'create_account',
                    'target_type' => 'student',
                    'target_id'   => $second,
                    'summary'     => sprintf('ایجاد حساب کاربری برای دانش‌آموز #%s', $second),
                ];
            }
            if ($second !== null && ctype_digit($second) && $third === 'reset-password') {
                return [
                    'module'      => 'students',
                    'action'      => 'reset_password',
                    'target_type' => 'student',
                    'target_id'   => $second,
                    'summary'     => sprintf('بازنشانی رمز عبور دانش‌آموز #%s', $second),
                ];
            }
            if ($second !== null && ctype_digit($second) && $third === 'toggle-status') {
                return [
                    'module'      => 'students',
                    'action'      => 'toggle_status',
                    'target_type' => 'student',
                    'target_id'   => $second,
                    'summary'     => sprintf('تغییر وضعیت فعال/غیرفعال دانش‌آموز #%s', $second),
                ];
            }
            if ($second !== null && ctype_digit($second) && $third === 'parent-contacts') {
                $contactId = $segments[3] ?? $extractId(null);
                $actionMap = ['POST' => 'create_parent_contact', 'PUT' => 'update_parent_contact', 'DELETE' => 'delete_parent_contact'];
                $labelMap = ['POST' => 'ثبت شماره والدین', 'PUT' => 'ویرایش شماره والدین', 'DELETE' => 'حذف شماره والدین'];
                return [
                    'module'      => 'students',
                    'action'      => $actionMap[$method] ?? 'manage_parent_contact',
                    'target_type' => 'parent_contact',
                    'target_id'   => $contactId,
                    'summary'     => sprintf('%s برای دانش‌آموز #%s', $labelMap[$method] ?? 'مدیریت شماره والدین', $second),
                ];
            }
            $studentName = (string) ($body['name'] ?? $responseData['name'] ?? '');
            if ($method === 'POST') {
                return [
                    'module'      => 'students',
                    'action'      => 'create',
                    'target_type' => 'student',
                    'target_id'   => $extractId(null),
                    'summary'     => sprintf('ثبت دانش‌آموز جدید%s', $studentName !== '' ? " «{$studentName}»" : ''),
                ];
            }
            if ($method === 'PUT' || $method === 'PATCH') {
                return [
                    'module'      => 'students',
                    'action'      => 'update',
                    'target_type' => 'student',
                    'target_id'   => $second,
                    'summary'     => sprintf('ویرایش اطلاعات دانش‌آموز #%s%s', $second ?? '', $studentName !== '' ? " ({$studentName})" : ''),
                ];
            }
            if ($method === 'DELETE') {
                return [
                    'module'      => 'students',
                    'action'      => 'delete',
                    'target_type' => 'student',
                    'target_id'   => $second,
                    'summary'     => sprintf('حذف دانش‌آموز #%s', $second ?? ''),
                ];
            }
        }

        // 5. Exams
        if ($first === 'exams') {
            if ($second === 'comments') {
                $studentId = $body['student_id'] ?? null;
                return [
                    'module'      => 'exams',
                    'action'      => 'upsert_comment',
                    'target_type' => 'exam_comment',
                    'target_id'   => $studentId !== null ? (string) $studentId : null,
                    'summary'     => sprintf('ثبت/ویرایش نظر مشاور در کارنامه آزمون%s', $studentId !== null ? " دانش‌آموز #{$studentId}" : ''),
                ];
            }
            if ($second === 'share-links' && $third === 'send-sms') {
                return [
                    'module'      => 'exams',
                    'action'      => 'send_share_link_sms',
                    'target_type' => 'exam_share_link',
                    'target_id'   => isset($body['student_id']) ? (string) $body['student_id'] : null,
                    'summary'     => 'ارسال پیامک لینک کارنامه آزمون به والدین',
                ];
            }
            if ($second === 'share-links' && $method === 'POST' && $third === null) {
                return [
                    'module'      => 'exams',
                    'action'      => 'create_share_link',
                    'target_type' => 'exam_share_link',
                    'target_id'   => $extractId(null),
                    'summary'     => 'ایجاد لینک اشتراک‌گذاری کارنامه آزمون',
                ];
            }
            if ($second === 'share-links' && ($method === 'DELETE' || ($segments[3] ?? '') === 'revoke')) {
                return [
                    'module'      => 'exams',
                    'action'      => 'revoke_share_link',
                    'target_type' => 'exam_share_link',
                    'target_id'   => $third,
                    'summary'     => sprintf('لغو لینک اشتراک‌گذاری کارنامه #%s', $third ?? ''),
                ];
            }
            return [
                'module'      => 'exams',
                'action'      => 'save_exam',
                'target_type' => 'exam',
                'target_id'   => $extractId($second),
                'summary'     => 'ثبت/بروزرسانی نتایج آزمون',
            ];
        }

        // 6. Assignments (Student -> Supporter)
        if ($first === 'assignments') {
            if ($second === 'initial-fill') {
                return [
                    'module'      => 'assignments',
                    'action'      => 'initial_fill',
                    'target_type' => 'supporter_assignment',
                    'target_id'   => null,
                    'summary'     => 'تخصیص اولیه خودکار دانش‌آموزان به پشتیبان‌ها',
                ];
            }
            if ($method === 'POST') {
                $stId = $body['student_id'] ?? null;
                $spId = $body['supporter_id'] ?? null;
                return [
                    'module'      => 'assignments',
                    'action'      => 'assign_student',
                    'target_type' => 'supporter_assignment',
                    'target_id'   => $stId !== null ? (string) $stId : null,
                    'summary'     => sprintf(
                        'تخصیص دانش‌آموز%s به پشتیبان%s',
                        $stId !== null ? " #{$stId}" : '',
                        $spId !== null ? " #{$spId}" : ''
                    ),
                ];
            }
            if ($method === 'DELETE') {
                return [
                    'module'      => 'assignments',
                    'action'      => 'unassign_student',
                    'target_type' => 'supporter_assignment',
                    'target_id'   => $second,
                    'summary'     => sprintf('لغو تخصیص پشتیبان برای دانش‌آموز #%s', $second ?? ''),
                ];
            }
        }

        // 7. Supporters, Instructors, Courses, Subscriptions, Plans, Blog, Files, Auth
        $moduleLabels = [
            'supporters'    => ['type' => 'supporter', 'label' => 'پشتیبان'],
            'instructors'   => ['type' => 'instructor', 'label' => 'استاد'],
            'courses'       => ['type' => 'course', 'label' => 'دوره آموزشی'],
            'subscriptions' => ['type' => 'subscription', 'label' => 'اشتراک دوره'],
            'plans'         => ['type' => 'weekly_plan', 'label' => 'برنامه هفتگی'],
            'blog'          => ['type' => 'blog_post', 'label' => 'مقاله وبلاگ'],
            'files'         => ['type' => 'file', 'label' => 'فایل'],
        ];

        if (isset($moduleLabels[$first])) {
            $meta = $moduleLabels[$first];
            $targetId = $extractId($second !== null && ctype_digit($second) ? $second : ($third !== null && ctype_digit($third) ? $third : null));
            $title = (string) ($body['name'] ?? $body['title'] ?? $responseData['name'] ?? $responseData['title'] ?? '');

            $verb = match ($method) {
                'POST' => 'ایجاد/ثبت',
                'PUT', 'PATCH' => 'ویرایش',
                'DELETE' => 'حذف',
                default => 'مدیریت',
            };
            $act = match ($method) {
                'POST' => 'create',
                'PUT', 'PATCH' => 'update',
                'DELETE' => 'delete',
                default => 'action',
            };

            return [
                'module'      => $first,
                'action'      => $act,
                'target_type' => $meta['type'],
                'target_id'   => $targetId,
                'summary'     => sprintf(
                    '%s %s%s%s',
                    $verb,
                    $meta['label'],
                    $targetId !== null ? " #{$targetId}" : '',
                    $title !== '' ? " «{$title}»" : ''
                ),
            ];
        }

        if ($first === 'auth') {
            if ($second === 'change-password') {
                return [
                    'module'      => 'auth',
                    'action'      => 'change_password',
                    'target_type' => 'user',
                    'target_id'   => null,
                    'summary'     => 'تغییر رمز عبور حساب کاربری',
                ];
            }
            if ($second === 'profile') {
                return [
                    'module'      => 'auth',
                    'action'      => 'update_profile',
                    'target_type' => 'user',
                    'target_id'   => null,
                    'summary'     => 'بروزرسانی اطلاعات پروفایل کاربری',
                ];
            }
        }

        return [
            'module'      => $first,
            'action'      => strtolower($method),
            'target_type' => $second,
            'target_id'   => $extractId($second),
            'summary'     => sprintf('اجرای عملیات %s روی %s', $method, $cleanPath),
        ];
    }

    /**
     * List staff activity logs with rich filtering and pagination.
     *
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public static function list(array $filters = []): array
    {
        $db = Database::getConnection();
        self::ensureTable($db);

        $where = [];
        $params = [];

        if (!empty($filters['role']) && is_string($filters['role'])) {
            $role = strtolower(trim($filters['role']));
            if (in_array($role, ['admin', 'supporter', 'teacher'], true)) {
                $where[] = 'actor_role = :actor_role';
                $params[':actor_role'] = $role;
            }
        }

        if (isset($filters['actor_user_id']) && $filters['actor_user_id'] !== '' && $filters['actor_user_id'] !== null) {
            $where[] = 'actor_user_id = :actor_user_id';
            $params[':actor_user_id'] = (int) $filters['actor_user_id'];
        }

        if (isset($filters['actor_linked_id']) && $filters['actor_linked_id'] !== '' && $filters['actor_linked_id'] !== null) {
            $where[] = 'actor_linked_id = :actor_linked_id';
            $params[':actor_linked_id'] = (int) $filters['actor_linked_id'];
        }

        if (!empty($filters['module']) && is_string($filters['module'])) {
            $where[] = 'module = :module';
            $params[':module'] = strtolower(trim($filters['module']));
        }

        if (!empty($filters['action']) && is_string($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = strtolower(trim($filters['action']));
        }

        $dateFrom = $filters['from'] ?? $filters['date_from'] ?? null;
        if (is_string($dateFrom) && trim($dateFrom) !== '') {
            $fromVal = trim($dateFrom);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromVal)) {
                $fromVal .= ' 00:00:00';
            }
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $fromVal;
        }

        $dateTo = $filters['to'] ?? $filters['date_to'] ?? null;
        if (is_string($dateTo) && trim($dateTo) !== '') {
            $toVal = trim($dateTo);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toVal)) {
                $toVal .= ' 23:59:59';
            }
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $toVal;
        }

        if (!empty($filters['q']) && is_string($filters['q'])) {
            $q = '%' . trim($filters['q']) . '%';
            $where[] = '(summary LIKE :q OR actor_name LIKE :q OR endpoint LIKE :q OR target_id LIKE :q)';
            $params[':q'] = $q;
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($filters['per_page'] ?? $filters['limit'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM staff_activity_logs {$whereSql}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT id, actor_user_id, actor_role, actor_linked_id, actor_name,
                       module, action, target_type, target_id, summary,
                       http_method, endpoint, status_code, ip_address, details, created_at
                FROM staff_activity_logs
                {$whereSql}
                ORDER BY id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map([self::class, 'formatRow'], $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Summary statistics for Admin Panel staff activity monitoring.
     */
    public static function stats(array $filters = []): array
    {
        $db = Database::getConnection();
        self::ensureTable($db);

        $total = (int) $db->query('SELECT COUNT(*) FROM staff_activity_logs')->fetchColumn();

        $todayStart = gmdate('Y-m-d 00:00:00');
        $todayStmt = $db->prepare('SELECT COUNT(*) FROM staff_activity_logs WHERE created_at >= :today');
        $todayStmt->execute([':today' => $todayStart]);
        $todayLogs = (int) $todayStmt->fetchColumn();

        $byRole = [
            'admin'     => 0,
            'supporter' => 0,
            'teacher'   => 0,
        ];
        $roleStmt = $db->query('SELECT actor_role, COUNT(*) AS cnt FROM staff_activity_logs GROUP BY actor_role');
        foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roleKey = (string) ($r['actor_role'] ?? '');
            $byRole[$roleKey] = (int) ($r['cnt'] ?? 0);
        }

        $byModule = [];
        $modStmt = $db->query('SELECT module, COUNT(*) AS cnt FROM staff_activity_logs GROUP BY module ORDER BY cnt DESC');
        foreach ($modStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $byModule[] = [
                'module' => (string) ($m['module'] ?? 'system'),
                'count'  => (int) ($m['cnt'] ?? 0),
            ];
        }

        $actorStmt = $db->query(
            'SELECT actor_user_id, actor_role, actor_linked_id, MAX(actor_name) AS actor_name,
                    COUNT(*) AS actions_count, MAX(created_at) AS last_activity_at
             FROM staff_activity_logs
             GROUP BY actor_user_id, actor_role, actor_linked_id
             ORDER BY actions_count DESC, last_activity_at DESC
             LIMIT 30'
        );
        $activeActors = [];
        foreach ($actorStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $activeActors[] = [
                'actor_user_id'    => (int) $a['actor_user_id'],
                'actor_role'       => (string) $a['actor_role'],
                'actor_linked_id'  => $a['actor_linked_id'] !== null ? (int) $a['actor_linked_id'] : null,
                'actor_name'       => $a['actor_name'] !== null ? (string) $a['actor_name'] : null,
                'actions_count'    => (int) $a['actions_count'],
                'last_activity_at' => (string) $a['last_activity_at'],
            ];
        }

        return [
            'total_logs'    => $total,
            'today_logs'    => $todayLogs,
            'by_role'       => $byRole,
            'by_module'     => $byModule,
            'active_actors' => $activeActors,
        ];
    }

    private static function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = ['password', 'current_password', 'new_password', 'token', 'code', 'two_factor_code', 'secret'];
        $clean = [];
        foreach ($payload as $k => $v) {
            $lowerKey = strtolower((string) $k);
            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $clean[$k] = '***';
            } elseif (is_array($v)) {
                $clean[$k] = self::sanitizePayload($v);
            } elseif (is_string($v) && mb_strlen($v) > 300) {
                $clean[$k] = mb_substr($v, 0, 300) . '…';
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }

    private static function formatRow(array $row): array
    {
        $details = $row['details'] ?? null;
        if (is_string($details) && $details !== '') {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : null;
        }

        $role = (string) ($row['actor_role'] ?? 'admin');
        $roleLabel = match ($role) {
            'admin'     => 'ادمین',
            'supporter' => 'پشتیبان',
            'teacher'   => 'استاد / مدرس',
            default     => $role,
        };

        return [
            'id'               => (int) $row['id'],
            'actor_user_id'    => (int) $row['actor_user_id'],
            'actor_role'       => $role,
            'actor_role_label' => $roleLabel,
            'actor_linked_id'  => isset($row['actor_linked_id']) && $row['actor_linked_id'] !== null ? (int) $row['actor_linked_id'] : null,
            'actor_name'       => isset($row['actor_name']) && $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
            'module'           => (string) ($row['module'] ?? 'system'),
            'action'           => (string) ($row['action'] ?? 'action'),
            'target_type'      => isset($row['target_type']) && $row['target_type'] !== null ? (string) $row['target_type'] : null,
            'target_id'        => isset($row['target_id']) && $row['target_id'] !== null ? (string) $row['target_id'] : null,
            'summary'          => (string) ($row['summary'] ?? ''),
            'http_method'      => (string) ($row['http_method'] ?? 'POST'),
            'endpoint'         => (string) ($row['endpoint'] ?? ''),
            'status_code'      => isset($row['status_code']) ? (int) $row['status_code'] : 200,
            'ip_address'       => isset($row['ip_address']) && $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            'details'          => $details,
            'timestamp'        => (string) ($row['created_at'] ?? ''),
            'created_at'       => (string) ($row['created_at'] ?? ''),
        ];
    }
}
