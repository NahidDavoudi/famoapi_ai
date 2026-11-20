<?php

namespace App\Modules\Appointments;

use App\Core\ApiException;
use App\Core\Pagination;
use App\Core\PhoneNumber;
use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\RedisClient;
use App\Core\SmsPatternSettings;
use App\Core\SmsService;
use App\Core\Sorting;
use App\Modules\Bot\IranDay;

class AppointmentService
{
    public const VALID_SMS_MODES = ['unsent', 'failed', 'changed'];
    public const VALID_LOG_STATUSES = ['sent', 'failed', 'skipped_no_phone'];

    public function __construct(private ?SmsService $sms = null)
    {
        $this->sms ??= new SmsService();
    }

    public function list(int $page, int $perPage, array $sortParams = []): array
    {
        $total = Appointment::countAll();
        $pagination = Pagination::build($page, $perPage, $total);
        $sort = Appointment::resolveSort($sortParams);
        $items = Appointment::findAll($pagination['page'], $pagination['per_page'], $sort['order_by']);

        return [
            'items'      => $items,
            'pagination' => Sorting::attachToPagination($pagination, $sort),
        ];
    }

    public function createOrUpdate(array $data): array
    {
        $existing = Appointment::findByStudentAndDate($data['student_id'], $data['appointment_date']);

        if ($existing) {
            Appointment::update($existing['id'], $data);
            $appointment = Appointment::findById($existing['id']);
        } else {
            $id = Appointment::create($data);
            $appointment = Appointment::findById($id);
        }

        return $appointment;
    }

    public function update(int $id, array $data): array
    {
        $appointment = Appointment::findById($id);
        if (!$appointment) {
            throw new ApiException('نوبت یافت نشد', 404, 'NOT_FOUND');
        }

        Appointment::update($id, $data);
        return Appointment::findById($id);
    }

    public function updateStatus(int $id, string $status): array
    {
        $appointment = Appointment::findById($id);
        if (!$appointment) {
            throw new ApiException('نوبت یافت نشد', 404, 'NOT_FOUND');
        }

        Appointment::updateStatus($id, $status);
        return Appointment::findById($id);
    }

    public function delete(int $id): void
    {
        $appointment = Appointment::findById($id);
        if (!$appointment) {
            throw new ApiException('نوبت یافت نشد', 404, 'NOT_FOUND');
        }

        Appointment::delete($id);
    }

    /**
     * Preview weekly appointment SMS status for each student without sending any SMS.
     * Statuses: will_send, already_sent, changed_since_sent, failed, no_phone, no_appointment.
     */
    public function previewSms(string $weekDate, ?array $studentIds = null): array
    {
        $trimmedWeekDate = trim($weekDate);
        if ($trimmedWeekDate === '' || !IranDay::isValidDate($trimmedWeekDate)) {
            throw new ApiException('تاریخ هفته نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $normalizedStudentIds = $this->normalizeStudentIds($studentIds);
        $weekStart = IranDay::weekStart($trimmedWeekDate);
        $weekEnd   = IranDay::addDays($weekStart, 6);

        $patternMeta  = SmsPatternSettings::resolve(SmsPatternSettings::KEY_APPOINTMENT_REMINDER);
        $students     = Appointment::findTargetStudents($weekStart, $weekEnd, $normalizedStudentIds);
        $studentIdSet = array_map(static fn (array $s): int => (int) $s['id'], $students);
        $activeApps   = Appointment::findActiveInWeek($weekStart, $weekEnd, $studentIdSet);
        $parentsBySid = Appointment::findPrimaryParentsForStudents($studentIdSet);
        $logsBySid    = Appointment::findSmsLogsByWeek($weekStart, $studentIdSet);

        $appsByStudent = [];
        foreach ($activeApps as $app) {
            $sid = (int) $app['student_id'];
            $appsByStudent[$sid][] = $app;
        }

        $summary = [
            'total'                 => 0,
            'will_send'             => 0,
            'already_sent'          => 0,
            'changed_since_sent'    => 0,
            'failed'                => 0,
            'no_phone'              => 0,
            'no_appointment'        => 0,
            'multiple_appointments' => 0,
        ];

        $items = [];

        foreach ($students as $student) {
            $sid = (int) $student['id'];
            $studentApps = $appsByStudent[$sid] ?? [];
            $nearest = $studentApps[0] ?? null;
            $multipleAppointments = count($studentApps) > 1;

            if ($multipleAppointments) {
                $summary['multiple_appointments']++;
            }

            $parent = $parentsBySid[$sid] ?? null;
            $parentMobile = $parent !== null ? PhoneNumber::normalize($parent['phone'] ?? null) : null;
            $log = $logsBySid[$sid] ?? null;

            $appointmentAt = null;
            $appointmentDate = null;
            $appointmentJalali = null;
            $weekday = null;
            $startTime = null;
            $endTime = null;
            $appointmentId = null;
            $appointmentStatus = null;
            $dayAndDateFa = null;
            $smsPreviewText = null;

            if ($nearest !== null) {
                $appointmentId     = (int) $nearest['id'];
                $appointmentDate   = (string) $nearest['appointment_date'];
                $appointmentJalali = IranDay::jalali($appointmentDate);
                $weekday           = IranDay::weekdayName($appointmentDate);
                $startTime         = self::formatTimeShort($nearest['start_time'] ?? null);
                $endTime           = self::formatTimeShort($nearest['end_time'] ?? null);
                $appointmentStatus = (string) $nearest['status'];
                $appointmentAt     = self::buildAppointmentAt($appointmentDate, $nearest['start_time'] ?? null);
                $dayAndDateFa      = "{$weekday} {$appointmentJalali}";
                $smsPreviewText    = SmsPatternSettings::renderAppointmentMessage(
                    (string) $student['name'],
                    $dayAndDateFa,
                    $startTime,
                    (string) $patternMeta['pattern_text']
                );
            }

            if ($nearest === null) {
                $status = 'no_appointment';
            } elseif ($parentMobile === null) {
                $status = 'no_phone';
            } elseif ($log !== null && $log['status'] === 'sent') {
                $loggedAt = self::normalizeTimestamp($log['appointment_at'] ?? null);
                $status = ($loggedAt === $appointmentAt) ? 'already_sent' : 'changed_since_sent';
            } elseif ($log !== null && $log['status'] === 'failed') {
                $status = 'failed';
            } else {
                $status = 'will_send';
            }

            $summary['total']++;
            $summary[$status]++;

            $items[] = [
                'student_id'            => $sid,
                'student_name'          => (string) $student['name'],
                'grade'                 => isset($student['grade']) ? (int) $student['grade'] : null,
                'field'                 => $student['field'] ?? null,
                'status'                => $status,
                'recipient_type'        => 'parent',
                'parent_name'           => $parent['parent_name'] ?? null,
                'mobile_mask'           => $parentMobile !== null ? PhoneNumber::mask($parentMobile) : null,
                'appointment_id'        => $appointmentId,
                'appointment_date'      => $appointmentDate,
                'appointment_jalali'    => $appointmentJalali,
                'weekday'               => $weekday,
                'day_and_date_fa'       => $dayAndDateFa,
                'start_time'            => $startTime,
                'end_time'              => $endTime,
                'appointment_status'    => $appointmentStatus,
                'appointment_at'        => $appointmentAt,
                'sms_preview_text'      => $smsPreviewText,
                'multiple_appointments' => $multipleAppointments,
                'active_appointments_count' => count($studentApps),
                'last_sent_at'          => $log['sent_at'] ?? null,
                'last_appointment_at'   => isset($log['appointment_at']) ? self::normalizeTimestamp($log['appointment_at']) : null,
                'attempts'              => isset($log['attempts']) ? (int) $log['attempts'] : 0,
                'last_error'            => $log['error'] ?? null,
            ];
        }

        return [
            'week_date' => $weekStart,
            'week_end'  => $weekEnd,
            'pattern'   => [
                'key'           => $patternMeta['pattern_key'],
                'body_id'       => $patternMeta['body_id'],
                'source'        => $patternMeta['source'],
                'is_configured' => $patternMeta['is_configured'],
                'template'      => $patternMeta['pattern_text'],
            ],
            'summary'   => $summary,
            'items'     => $items,
            'students'  => $items,
        ];
    }

    /**
     * Dispatch weekly appointment reminder SMS in bulk with per-student error isolation,
     * deduplication, mode filtering (unsent, failed, changed), double-click lock, and separate rate limits.
     */
    public function sendSms(
        string $weekDate,
        string $mode = 'unsent',
        ?array $studentIds = null,
        ?int $createdBy = null,
        ?string $operationId = null,
        ?int $bodyId = null,
        bool $savePattern = false
    ): array {
        $trimmedWeekDate = trim($weekDate);
        if ($trimmedWeekDate === '' || !IranDay::isValidDate($trimmedWeekDate)) {
            throw new ApiException('تاریخ هفته نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $trimmedMode = trim($mode);
        if (!in_array($trimmedMode, self::VALID_SMS_MODES, true)) {
            throw new ApiException('حالت ارسال (mode) باید یکی از unsent، failed یا changed باشد', 422, 'VALIDATION_ERROR');
        }

        $patternMeta = SmsPatternSettings::resolve(SmsPatternSettings::KEY_APPOINTMENT_REMINDER, $bodyId);
        if (!$patternMeta['is_configured']) {
            throw new ApiException(
                'کد پترن پیامک نوبت تنظیم نشده است. لطفاً کد پترن یادآوری نوبت را در تنظیمات پیامک پنل ادمین یا متغیر MELIPAYAMAK_APPOINTMENT_BODY_ID ثبت کنید.',
                422,
                'APPOINTMENT_PATTERN_NOT_CONFIGURED'
            );
        }

        $effectiveBodyId = (int) $patternMeta['body_id'];
        if ($savePattern && $bodyId !== null && $bodyId > 0) {
            SmsPatternSettings::updateMany([
                SmsPatternSettings::KEY_APPOINTMENT_REMINDER => ['body_id' => $effectiveBodyId],
            ], $createdBy);
        }
        $this->sms->setAppointmentBodyId($effectiveBodyId);

        $normalizedStudentIds = $this->normalizeStudentIds($studentIds);
        $weekStart = IranDay::weekStart($trimmedWeekDate);
        $weekEnd   = IranDay::addDays($weekStart, 6);

        // Enforce sender user hourly limit before acquiring batch lock
        $smsWindow = 3600;
        $userHourlyMax = max(1, (int) ($_ENV['APPOINTMENT_SMS_USER_HOURLY_MAX'] ?? 200));
        $phoneHourlyMax = max(1, (int) ($_ENV['APPOINTMENT_SMS_PHONE_HOURLY_MAX'] ?? 3));
        $userKey = RateLimiter::key('appointment_sms:user', (string) ($createdBy ?? 0));
        $userStatus = RateLimiter::status($userKey, $userHourlyMax);
        if (!$userStatus['allowed']) {
            throw new RateLimitedException($userStatus['retry_after'] > 0 ? $userStatus['retry_after'] : $smsWindow);
        }

        // Double-click / concurrent send lock for the same week_date (and optional operation_id)
        $lockKey = "appointment_sms:lock:{$weekStart}";
        $opKey = ($operationId !== null && trim($operationId) !== '')
            ? "appointment_sms:op:{$weekStart}:" . hash('sha256', trim($operationId))
            : null;

        if ($this->isLockActive($lockKey) || ($opKey !== null && $this->isLockActive($opKey))) {
            throw new ApiException(
                'ارسال پیامک نوبت برای این هفته در حال انجام است یا این عملیات قبلاً ثبت شده است',
                409,
                'CONCURRENT_SEND_LOCKED'
            );
        }

        $this->acquireLock($lockKey, 30);
        if ($opKey !== null) {
            $this->acquireLock($opKey, 60);
        }

        try {
            $students     = Appointment::findTargetStudents($weekStart, $weekEnd, $normalizedStudentIds);
            $studentIdSet = array_map(static fn (array $s): int => (int) $s['id'], $students);
            $activeApps   = Appointment::findActiveInWeek($weekStart, $weekEnd, $studentIdSet);
            $parentsBySid = Appointment::findPrimaryParentsForStudents($studentIdSet);
            $logsBySid    = Appointment::findSmsLogsByWeek($weekStart, $studentIdSet);

            $appsByStudent = [];
            foreach ($activeApps as $app) {
                $sid = (int) $app['student_id'];
                $appsByStudent[$sid][] = $app;
            }

            $counts = [
                'sent'             => 0,
                'failed'           => 0,
                'skipped'          => 0,
                'skipped_no_phone' => 0,
                'already_sent'     => 0,
                'no_appointment'   => 0,
            ];

            $items = [];

            foreach ($students as $student) {
                $sid = (int) $student['id'];
                $studentName = (string) $student['name'];

                try {
                    $studentApps = $appsByStudent[$sid] ?? [];
                    $nearest = $studentApps[0] ?? null;
                    $multipleAppointments = count($studentApps) > 1;

                    if ($nearest === null) {
                        $counts['skipped']++;
                        $counts['no_appointment']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => null,
                            'appointment_at'        => null,
                            'multiple_appointments' => false,
                            'status'                => 'no_appointment',
                            'error'                 => null,
                        ];
                        continue;
                    }

                    $appointmentDate = (string) $nearest['appointment_date'];
                    $appointmentAt   = self::buildAppointmentAt($appointmentDate, $nearest['start_time'] ?? null);
                    $parent          = $parentsBySid[$sid] ?? null;
                    $parentMobile    = $parent !== null ? PhoneNumber::normalize($parent['phone'] ?? null) : null;
                    $existingLog     = $logsBySid[$sid] ?? null;

                    // Student without primary parent or valid parent phone -> skipped_no_phone (never fall back to student phone)
                    if ($parentMobile === null) {
                        if ($trimmedMode === 'unsent' || $normalizedStudentIds !== null) {
                            Appointment::upsertSmsLog([
                                'student_id'     => $sid,
                                'week_date'      => $weekStart,
                                'mobile'         => '',
                                'recipient_type' => 'parent',
                                'appointment_at' => $appointmentAt,
                                'status'         => 'skipped_no_phone',
                                'error'          => 'شماره تماس والد ثبت نشده است',
                                'sent_at'        => null,
                                'created_by'     => $createdBy,
                            ]);
                        }
                        $counts['skipped']++;
                        $counts['skipped_no_phone']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => null,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'skipped_no_phone',
                            'error'                 => null,
                        ];
                        continue;
                    }

                    $mobileMask = PhoneNumber::mask($parentMobile);
                    $loggedAt   = $existingLog !== null ? self::normalizeTimestamp($existingLog['appointment_at'] ?? null) : null;
                    $logStatus  = $existingLog['status'] ?? null;

                    // Rule: Prevent duplicate send if already sent and appointment_at has not changed
                    if ($logStatus === 'sent' && $loggedAt === $appointmentAt) {
                        $counts['skipped']++;
                        $counts['already_sent']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'already_sent',
                            'error'                 => null,
                        ];
                        continue;
                    }

                    // Mode eligibility check
                    $eligibleForMode = false;
                    $skipReason = 'skipped';

                    if ($trimmedMode === 'unsent') {
                        if ($existingLog === null || $logStatus === 'skipped_no_phone') {
                            $eligibleForMode = true;
                        } else {
                            $skipReason = $logStatus === 'sent' ? 'changed_since_sent' : 'failed';
                        }
                    } elseif ($trimmedMode === 'failed') {
                        if ($logStatus === 'failed') {
                            $eligibleForMode = true;
                        }
                    } elseif ($trimmedMode === 'changed') {
                        if ($logStatus === 'sent' && $loggedAt !== $appointmentAt) {
                            $eligibleForMode = true;
                        }
                    }

                    if (!$eligibleForMode) {
                        $counts['skipped']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => $skipReason,
                            'error'                 => null,
                        ];
                        continue;
                    }

                    // Per-user & per-phone hourly rate limits (separate from exam_sms)
                    $currentUserStatus = RateLimiter::status($userKey, $userHourlyMax);
                    if (!$currentUserStatus['allowed']) {
                        $errMsg = 'RATE_LIMITED: سقف ارسال ساعتی کاربر تکمیل شده است';
                        Appointment::upsertSmsLog([
                            'student_id'     => $sid,
                            'week_date'      => $weekStart,
                            'mobile'         => $parentMobile,
                            'recipient_type' => 'parent',
                            'appointment_at' => $appointmentAt,
                            'status'         => 'failed',
                            'error'          => $errMsg,
                            'sent_at'        => null,
                            'created_by'     => $createdBy,
                        ]);
                        $counts['failed']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'failed',
                            'error'                 => $errMsg,
                        ];
                        continue;
                    }

                    $cfg = RateLimiter::config();
                    $fwPhone = RateLimiter::attemptFixedWindow(
                        RateLimiter::key('sms:phone_win', $parentMobile),
                        (int) ($cfg['sms_phone_max'] ?? 3),
                        (int) ($cfg['sms_phone_window'] ?? 600)
                    );
                    if (!$fwPhone['allowed']) {
                        $errMsg = 'RATE_LIMITED: سقف ارسال پیامک برای این شماره پر شده است';
                        Appointment::upsertSmsLog([
                            'student_id'     => $sid,
                            'week_date'      => $weekStart,
                            'mobile'         => $parentMobile,
                            'recipient_type' => 'parent',
                            'appointment_at' => $appointmentAt,
                            'status'         => 'failed',
                            'error'          => $errMsg,
                            'sent_at'        => null,
                            'created_by'     => $createdBy,
                        ]);
                        $counts['failed']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'failed',
                            'error'                 => $errMsg,
                        ];
                        continue;
                    }

                    $phoneKey = RateLimiter::key('appointment_sms:phone', $parentMobile);
                    $phoneStatus = RateLimiter::status($phoneKey, $phoneHourlyMax);
                    if (!$phoneStatus['allowed']) {
                        $errMsg = 'RATE_LIMITED: سقف ارسال پیامک ساعتی برای این شماره پر شده است';
                        Appointment::upsertSmsLog([
                            'student_id'     => $sid,
                            'week_date'      => $weekStart,
                            'mobile'         => $parentMobile,
                            'recipient_type' => 'parent',
                            'appointment_at' => $appointmentAt,
                            'status'         => 'failed',
                            'error'          => $errMsg,
                            'sent_at'        => null,
                            'created_by'     => $createdBy,
                        ]);
                        $counts['failed']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'failed',
                            'error'                 => $errMsg,
                        ];
                        continue;
                    }

                    // Format Persian weekday + Jalali date and Iran time
                    $dayAndDate = IranDay::weekdayName($appointmentDate) . ' ' . IranDay::jalali($appointmentDate);
                    $timeShort  = self::formatTimeShort($nearest['start_time'] ?? null);

                    RateLimiter::attempt($phoneKey, $phoneHourlyMax, $smsWindow, $smsWindow);
                    RateLimiter::attempt($userKey, $userHourlyMax, $smsWindow, $smsWindow);

                    $dispatched = $this->sms->sendAppointmentReminder(
                        $parentMobile,
                        $studentName,
                        $dayAndDate,
                        $timeShort
                    );

                    if ($dispatched) {
                        $nowUtc = gmdate('Y-m-d H:i:s');
                        Appointment::upsertSmsLog([
                            'student_id'     => $sid,
                            'week_date'      => $weekStart,
                            'mobile'         => $parentMobile,
                            'recipient_type' => 'parent',
                            'appointment_at' => $appointmentAt,
                            'status'         => 'sent',
                            'error'          => null,
                            'sent_at'        => $nowUtc,
                            'created_by'     => $createdBy,
                        ]);
                        $counts['sent']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'sent',
                            'error'                 => null,
                        ];
                    } else {
                        $errMsg = $this->sms->getLastError() ?: 'خطا در ارسال پیامک از طریق ملی‌پیامک';
                        Appointment::upsertSmsLog([
                            'student_id'     => $sid,
                            'week_date'      => $weekStart,
                            'mobile'         => $parentMobile,
                            'recipient_type' => 'parent',
                            'appointment_at' => $appointmentAt,
                            'status'         => 'failed',
                            'error'          => $errMsg,
                            'sent_at'        => null,
                            'created_by'     => $createdBy,
                        ]);
                        $counts['failed']++;
                        $items[] = [
                            'student_id'            => $sid,
                            'student_name'          => $studentName,
                            'recipient_type'        => 'parent',
                            'mobile_mask'           => $mobileMask,
                            'appointment_at'        => $appointmentAt,
                            'multiple_appointments' => $multipleAppointments,
                            'status'                => 'failed',
                            'error'                 => $errMsg,
                        ];
                    }
                } catch (\Throwable $e) {
                    $errMsg = $e->getMessage() ?: 'خطای غیرمنتظره در ارسال پیامک';
                    $counts['failed']++;
                    $items[] = [
                        'student_id'            => $sid,
                        'student_name'          => $studentName,
                        'recipient_type'        => 'parent',
                        'mobile_mask'           => null,
                        'appointment_at'        => null,
                        'multiple_appointments' => false,
                        'status'                => 'failed',
                        'error'                 => $errMsg,
                    ];
                }
            }

            return [
                'week_date'        => $weekStart,
                'week_end'         => $weekEnd,
                'mode'             => $trimmedMode,
                'body_id'          => $effectiveBodyId,
                'pattern_source'   => $patternMeta['source'],
                'sent'             => $counts['sent'],
                'failed'           => $counts['failed'],
                'skipped'          => $counts['skipped'],
                'skipped_no_phone' => $counts['skipped_no_phone'],
                'summary'          => $counts,
                'items'            => $items,
            ];
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Paginated list of appointment SMS logs filtered by week_date, student_id, and status.
     */
    public function getSmsLogs(array $filters, int $page, int $perPage): array
    {
        $normalizedFilters = [];

        if (isset($filters['week_date']) && trim((string) $filters['week_date']) !== '') {
            $rawWeek = trim((string) $filters['week_date']);
            if (!IranDay::isValidDate($rawWeek)) {
                throw new ApiException('فرمت تاریخ هفته نامعتبر است', 422, 'VALIDATION_ERROR');
            }
            $normalizedFilters['week_date'] = IranDay::weekStart($rawWeek);
        }

        if (isset($filters['student_id']) && $filters['student_id'] !== '' && $filters['student_id'] !== null) {
            if (filter_var($filters['student_id'], FILTER_VALIDATE_INT) === false || (int) $filters['student_id'] <= 0) {
                throw new ApiException('شناسه دانش‌آموز نامعتبر است', 422, 'VALIDATION_ERROR');
            }
            $normalizedFilters['student_id'] = (int) $filters['student_id'];
        }

        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            $rawStatus = trim((string) $filters['status']);
            if (!in_array($rawStatus, self::VALID_LOG_STATUSES, true)) {
                throw new ApiException('وضعیت لاگ نامعتبر است', 422, 'VALIDATION_ERROR');
            }
            $normalizedFilters['status'] = $rawStatus;
        }

        $total = Appointment::countSmsLogs($normalizedFilters);
        $pagination = Pagination::build($page, $perPage, $total);
        $rows = Appointment::findSmsLogs($normalizedFilters, $pagination['page'], $pagination['per_page']);

        $items = array_map(static function (array $row): array {
            $mobile = (string) ($row['mobile'] ?? '');
            return [
                'id'                  => (int) $row['id'],
                'student_id'          => (int) $row['student_id'],
                'student_name'        => $row['student_name'] ?? '',
                'grade'               => isset($row['grade']) ? (int) $row['grade'] : null,
                'field'               => $row['field'] ?? null,
                'week_date'           => $row['week_date'],
                'mobile_mask'         => $mobile !== '' ? PhoneNumber::mask($mobile) : null,
                'recipient_type'      => $row['recipient_type'] ?? 'parent',
                'appointment_at'      => isset($row['appointment_at']) ? self::normalizeTimestamp($row['appointment_at']) : null,
                'status'              => $row['status'],
                'error'               => $row['error'],
                'attempts'            => (int) ($row['attempts'] ?? 1),
                'sent_at'             => $row['sent_at'],
                'created_by'          => isset($row['created_by']) ? (int) $row['created_by'] : null,
                'created_by_username' => $row['created_by_username'] ?? null,
                'created_at'          => $row['created_at'],
                'updated_at'          => $row['updated_at'],
            ];
        }, $rows);

        return [
            'items'      => $items,
            'pagination' => $pagination,
        ];
    }

    public static function buildAppointmentAt(string $appointmentDate, ?string $startTime): string
    {
        $time = trim((string) ($startTime ?? ''));
        if ($time === '') {
            $time = '00:00:00';
        } elseif (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        return "{$appointmentDate} {$time}";
    }

    public static function formatTimeShort(?string $time): string
    {
        $trimmed = trim((string) ($time ?? ''));
        if ($trimmed === '') {
            return '00:00';
        }
        return substr($trimmed, 0, 5);
    }

    public static function normalizeTimestamp(?string $ts): ?string
    {
        if ($ts === null || trim($ts) === '') {
            return null;
        }
        $clean = str_replace('T', ' ', trim($ts));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $clean)) {
            return $clean . ':00';
        }
        return substr($clean, 0, 19);
    }

    private function normalizeStudentIds(?array $studentIds): ?array
    {
        if ($studentIds === null) {
            return null;
        }
        if ($studentIds === []) {
            throw new ApiException('لیست شناسه دانش‌آموزان نمی‌تواند خالی باشد', 422, 'VALIDATION_ERROR');
        }

        $normalized = [];
        foreach ($studentIds as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                throw new ApiException('شناسه دانش‌آموز نامعتبر است', 422, 'VALIDATION_ERROR');
            }
            $normalized[] = (int) $id;
        }

        return array_values(array_unique($normalized));
    }

    private function isLockActive(string $key): bool
    {
        if (RedisClient::isAvailable()) {
            return RedisClient::get($key) !== null;
        }
        $rlKey = RateLimiter::key('lock', $key);
        $status = RateLimiter::status($rlKey, 1);
        return !$status['allowed'];
    }

    private function acquireLock(string $key, int $ttlSeconds): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::setex($key, $ttlSeconds, '1');
            return;
        }
        $rlKey = RateLimiter::key('lock', $key);
        RateLimiter::attempt($rlKey, 1, $ttlSeconds, $ttlSeconds);
    }

    private function releaseLock(string $key): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del($key);
            return;
        }
        $rlKey = RateLimiter::key('lock', $key);
        RateLimiter::clear($rlKey);
    }
}
