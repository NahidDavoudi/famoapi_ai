<?php

namespace App\Modules\Exams;

use App\Core\EnvValidator;
use App\Core\Pagination;
use App\Core\PhoneNumber;
use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\SmsService;
use App\Core\Sorting;
use App\Modules\ParentContacts\ParentContact;

class ExamService
{
    private SmsService $sms;
    /** @var callable|null */
    private $shareLinkLogger;

    public function __construct(?SmsService $sms = null, ?callable $shareLinkLogger = null)
    {
        $this->sms = $sms ?? new SmsService();
        $this->shareLinkLogger = $shareLinkLogger;
    }

    public function getDates(int $page, int $perPage, ?int $studentId = null): array
    {
        $total = ExamResult::countDates($studentId);
        $pagination = Pagination::build($page, $perPage, $total);
        $dates = ExamResult::findDates($studentId, $pagination['page'], $pagination['per_page']);
        return ['dates' => $dates, 'pagination' => $pagination];
    }

    public function getStudentsByDate(string $examDate, int $page, int $perPage, array $sortParams = []): array
    {
        $total = ExamResult::countStudentsByDate($examDate);
        $pagination = Pagination::build($page, $perPage, $total);
        $sort = ExamResult::resolveStudentsByDateSort($sortParams);
        $students = ExamResult::findStudentsByDate($examDate, $pagination['page'], $pagination['per_page'], $sort['order_by']);
        return [
            'students'   => $students,
            'pagination' => Sorting::attachToPagination($pagination, $sort),
        ];
    }

    public function getDetails(string $examDate, int $studentId): array
    {
        $result = ExamResult::findDetails($examDate, $studentId);
        if (!$result['student']) throw new \App\Core\ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        return $result;
    }

    public function getAll(array $filters, int $page, int $perPage, array $sortParams = []): array
    {
        $total = ExamResult::countAll($filters);
        $pagination = Pagination::build($page, $perPage, $total);
        $sort = ExamResult::resolveListSort($sortParams);
        $exams = ExamResult::findAll($filters, $pagination['page'], $pagination['per_page'], $sort['order_by']);
        return [
            'exams'      => $exams,
            'pagination' => Sorting::attachToPagination($pagination, $sort),
        ];
    }

    public function save(int $studentId, string $examDate, array $subjects): array
    {
        if ($studentId <= 0 || !$this->isValidDate($examDate) || $subjects === []) {
            throw new \App\Core\ApiException('داده‌های ناقص یا نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        foreach ($subjects as $subject) {
            if (!is_array($subject)
                || !isset($subject['subject'])
                || !is_string($subject['subject'])
                || trim($subject['subject']) === ''
                || !isset($subject['total_q'])
                || filter_var($subject['total_q'], FILTER_VALIDATE_INT) === false) {
                throw new \App\Core\ApiException('ردیف درس نامعتبر است', 422, 'VALIDATION_ERROR');
            }

            $total = (int) $subject['total_q'];
            $correct = $subject['correct'] ?? 0;
            $wrong = $subject['wrong'] ?? 0;
            $skipped = $subject['skipped'] ?? 0;
            foreach ([$correct, $wrong, $skipped] as $count) {
                if (filter_var($count, FILTER_VALIDATE_INT) === false || (int) $count < 0) {
                    throw new \App\Core\ApiException('تعداد پاسخ‌ها باید عدد صحیح نامنفی باشد', 422, 'VALIDATION_ERROR');
                }
            }

            if ($total < 0 || (int) $correct + (int) $wrong + (int) $skipped > $total) {
                throw new \App\Core\ApiException('مجموع پاسخ‌ها از تعداد سوالات بیشتر است', 422, 'VALIDATION_ERROR');
            }
        }
        $inserted = ExamResult::saveBulk($studentId, $examDate, $subjects);
        return ['inserted' => $inserted];
    }

    public function update(int $id, array $data): array
    {
        if ($id <= 0) {
            throw new \App\Core\ApiException('شناسه نتیجه آزمون نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $existing = ExamResult::findById($id);
        if (!$existing) {
            throw new \App\Core\ApiException('نتیجه آزمون یافت نشد', 404, 'NOT_FOUND');
        }

        $updates = [];

        if (array_key_exists('student_id', $data)) {
            $studentId = $this->parseStrictInt($data['student_id'], 'شناسه دانش‌آموز', 1);
            if ($studentId !== (int) $existing['student_id'] && !ExamResult::studentExists($studentId)) {
                throw new \App\Core\ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
            }
            $updates['student_id'] = $studentId;
        }

        if (array_key_exists('exam_date', $data)) {
            if (!is_string($data['exam_date']) || !$this->isValidDate(trim($data['exam_date']))) {
                throw new \App\Core\ApiException('فرمت تاریخ آزمون باید به صورت YYYY-MM-DD باشد', 422, 'VALIDATION_ERROR');
            }
            $updates['exam_date'] = trim($data['exam_date']);
        }

        if (array_key_exists('subject', $data)) {
            if (!is_string($data['subject']) || trim($data['subject']) === '') {
                throw new \App\Core\ApiException('نام درس الزامی است', 422, 'VALIDATION_ERROR');
            }
            $subject = trim($data['subject']);
            if (mb_strlen($subject) > 50) {
                throw new \App\Core\ApiException('نام درس نمی‌تواند بیشتر از ۵۰ کاراکتر باشد', 422, 'VALIDATION_ERROR');
            }
            $updates['subject'] = $subject;
        }

        if (array_key_exists('chapter', $data)) {
            if ($data['chapter'] === null) {
                $updates['chapter'] = null;
            } elseif (!is_string($data['chapter'])) {
                throw new \App\Core\ApiException('نام مبحث باید رشته متنی باشد', 422, 'VALIDATION_ERROR');
            } else {
                $chapter = trim($data['chapter']);
                if ($chapter !== '' && mb_strlen($chapter) > 100) {
                    throw new \App\Core\ApiException('نام مبحث نمی‌تواند بیشتر از ۱۰۰ کاراکتر باشد', 422, 'VALIDATION_ERROR');
                }
                $updates['chapter'] = $chapter === '' ? null : $chapter;
            }
        }

        if (array_key_exists('total_q', $data)) {
            $updates['total_q'] = $this->parseStrictInt($data['total_q'], 'تعداد کل سوالات', 1);
        }

        if (array_key_exists('correct', $data)) {
            $updates['correct'] = $this->parseStrictInt($data['correct'], 'تعداد پاسخ‌های صحیح', 0);
        }

        if (array_key_exists('wrong', $data)) {
            $updates['wrong'] = $this->parseStrictInt($data['wrong'], 'تعداد پاسخ‌های غلط', 0);
        }

        if (array_key_exists('skipped', $data)) {
            $updates['skipped'] = $this->parseStrictInt($data['skipped'], 'تعداد سوالات نزده', 0);
        }

        if (array_key_exists('percentage', $data) && $data['percentage'] !== null) {
            if (!is_scalar($data['percentage']) || is_bool($data['percentage']) || !is_numeric($data['percentage'])) {
                throw new \App\Core\ApiException('درصد باید مقدار عددی بین ۰ تا ۱۰۰ باشد', 422, 'VALIDATION_ERROR');
            }
            $pct = (float) $data['percentage'];
            if (!is_finite($pct) || $pct < 0 || $pct > 100) {
                throw new \App\Core\ApiException('درصد باید بین ۰ تا ۱۰۰ باشد', 422, 'VALIDATION_ERROR');
            }
        }

        if ($updates === []) {
            throw new \App\Core\ApiException('حداقل یک فیلد معتبر برای ویرایش نتیجه آزمون الزامی است', 422, 'VALIDATION_ERROR');
        }

        $effectiveTotal = $updates['total_q'] ?? (int) $existing['total_q'];
        $effectiveCorrect = $updates['correct'] ?? (int) $existing['correct'];
        $effectiveWrong = $updates['wrong'] ?? (int) $existing['wrong'];
        $effectiveSkipped = $updates['skipped'] ?? (int) $existing['skipped'];

        if ($effectiveTotal < 1) {
            throw new \App\Core\ApiException('تعداد کل سوالات باید حداقل ۱ باشد', 422, 'VALIDATION_ERROR');
        }

        if ($effectiveCorrect < 0 || $effectiveWrong < 0 || $effectiveSkipped < 0) {
            throw new \App\Core\ApiException('تعداد پاسخ‌ها باید عدد صحیح نامنفی باشد', 422, 'VALIDATION_ERROR');
        }

        if ($effectiveCorrect + $effectiveWrong + $effectiveSkipped > $effectiveTotal) {
            throw new \App\Core\ApiException('مجموع پاسخ‌های صحیح، غلط و نزده نمی‌تواند از تعداد کل سوالات بیشتر باشد', 422, 'VALIDATION_ERROR');
        }

        $updated = ExamResult::updateById($id, $updates);
        if (!$updated) {
            throw new \App\Core\ApiException('نتیجه آزمون یافت نشد', 404, 'NOT_FOUND');
        }

        $computedPercentage = round(($effectiveCorrect / $effectiveTotal) * 100, 2);

        return [
            'id'         => (int) $updated['id'],
            'student_id' => (int) $updated['student_id'],
            'file_id'    => isset($updated['file_id']) && $updated['file_id'] !== null ? (int) $updated['file_id'] : null,
            'exam_date'  => (string) $updated['exam_date'],
            'subject'    => (string) $updated['subject'],
            'chapter'    => $updated['chapter'] !== null ? (string) $updated['chapter'] : null,
            'total_q'    => (int) $updated['total_q'],
            'correct'    => (int) $updated['correct'],
            'wrong'      => (int) $updated['wrong'],
            'skipped'    => (int) $updated['skipped'],
            'percentage' => isset($updated['percentage']) ? round((float) $updated['percentage'], 2) : $computedPercentage,
            'created_at' => $updated['created_at'] ?? null,
        ];
    }

    private function parseStrictInt(mixed $value, string $label, int $min): int
    {
        if (is_bool($value) || !is_scalar($value) || preg_match('/^-?\d+$/', trim((string) $value)) !== 1) {
            throw new \App\Core\ApiException("{$label} باید عدد صحیح باشد", 422, 'VALIDATION_ERROR');
        }
        $intVal = (int) trim((string) $value);
        if ($intVal < $min) {
            throw new \App\Core\ApiException("{$label} باید حداقل {$min} باشد", 422, 'VALIDATION_ERROR');
        }
        return $intVal;
    }

    public function upsertComment(int $studentId, string $examDate, int $teacherUserId, string $body): array
    {
        $trimmedBody = trim($body);
        if ($studentId <= 0 || $teacherUserId <= 0 || !$this->isValidDate($examDate) || $trimmedBody === '') {
            throw new \App\Core\ApiException('داده‌های ناقص یا نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        if (mb_strlen($trimmedBody) > 2000) {
            throw new \App\Core\ApiException('متن نظر نمی‌تواند بیشتر از ۲۰۰۰ کاراکتر باشد', 422, 'VALIDATION_ERROR');
        }

        if (!ExamResult::studentExists($studentId)) {
            throw new \App\Core\ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        if (!ExamResult::hasExamOnDate($studentId, $examDate)) {
            throw new \App\Core\ApiException('نتیجه آزمونی برای این دانش‌آموز در این تاریخ ثبت نشده است', 404, 'NOT_FOUND');
        }

        $comment = ExamResult::upsertComment($studentId, $examDate, $teacherUserId, $trimmedBody);
        $comments = ExamResult::findComments($examDate, $studentId);

        return [
            'comment'  => $comment,
            'comments' => $comments,
        ];
    }

    public function createShareLink(int $studentId, string $examDate, ?int $createdBy = null, array $smsOptions = []): array
    {
        if ($studentId <= 0 || !$this->isValidDate($examDate)) {
            throw new \App\Core\ApiException('داده‌های ناقص یا نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $student = ExamResult::getStudentBasicInfo($studentId);
        if (!$student) {
            throw new \App\Core\ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        if (!ExamResult::hasExamOnDate($studentId, $examDate)) {
            throw new \App\Core\ApiException('نتیجه آزمونی برای این دانش‌آموز در این تاریخ ثبت نشده است', 404, 'NOT_FOUND');
        }

        $sendSms = !empty($smsOptions['send_sms']);
        $recipientMobile = null;

        if ($sendSms) {
            $recipientMobile = $this->resolveParentMobile($studentId, $smsOptions);
            $this->enforceSmsRateLimits(
                $recipientMobile,
                $studentId,
                $examDate,
                isset($smsOptions['ip']) ? (string) $smsOptions['ip'] : null
            );
        }

        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 14 * 86400);

        $linkId = ExamResult::createShareLink($studentId, $examDate, $tokenHash, $expiresAt, $createdBy);

        $baseUrl = EnvValidator::requirePublicSiteUrl();
        $publicPath = '/r/' . $rawToken;
        $apiPath = '/api/v1/public/exam-results/' . $rawToken;
        $shareUrl = $baseUrl !== '' ? $baseUrl . $publicPath : $publicPath;

        $smsSent = false;
        $smsSentAt = null;
        $recipientMask = null;

        if ($sendSms && $recipientMobile !== null) {
            $sent = $this->sms->sendExamResultLink(
                $recipientMobile,
                (string) $student['name'],
                $examDate,
                $shareUrl
            );

            if (!$sent) {
                $smsError = $this->sms->getLastError();
                throw new \App\Core\ApiException(
                    $smsError ?: 'ارسال پیامک نتیجه آزمون با مشکل مواجه شد. لطفاً دقایقی بعد تلاش کنید.',
                    502,
                    'SMS_SEND_FAILED'
                );
            }

            $smsSent = true;
            $smsSentAt = ExamResult::markShareLinkSmsSent($linkId);
            $recipientMask = PhoneNumber::mask($recipientMobile);
        }

        return [
            'id'                   => $linkId,
            'token'                => $rawToken,
            'url'                  => $shareUrl,
            'share_path'           => $publicPath,
            'api_path'             => $apiPath,
            'share_url'            => $shareUrl,
            'student_id'           => $studentId,
            'exam_date'            => $examDate,
            'expires_at'           => $expiresAt,
            'sms_sent'             => $smsSent,
            'sms_sent_at'          => $smsSentAt,
            'recipient_phone_mask' => $recipientMask,
        ];
    }

    public function sendShareLinkSms(int $studentId, string $examDate, ?int $createdBy = null, array $options = []): array
    {
        return $this->createShareLink(
            $studentId,
            $examDate,
            $createdBy,
            array_merge($options, ['send_sms' => true])
        );
    }

    public function listShareLinks(?int $studentId = null, ?string $examDate = null): array
    {
        if ($examDate !== null && $examDate !== '' && !$this->isValidDate($examDate)) {
            throw new \App\Core\ApiException('فرمت تاریخ آزمون نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $rows = ExamResult::listShareLinks($studentId, $examDate);
        $now = time();

        return array_map(function (array $row) use ($now): array {
            $expiresTs = strtotime((string) $row['expires_at'] . ' UTC') ?: 0;
            $status = 'active';
            if (!empty($row['revoked_at'])) {
                $status = 'revoked';
            } elseif ($expiresTs > 0 && $expiresTs <= $now) {
                $status = 'expired';
            }

            return [
                'id'             => (int) $row['id'],
                'student_id'     => (int) $row['student_id'],
                'student_name'   => $row['student_name'] ?? '',
                'exam_date'      => $row['exam_date'],
                'status'         => $status,
                'expires_at'     => $row['expires_at'],
                'revoked_at'     => $row['revoked_at'],
                'sms_sent_at'    => $row['sms_sent_at'],
                'last_viewed_at' => $row['last_viewed_at'],
                'created_by'     => isset($row['created_by']) ? (int) $row['created_by'] : null,
                'created_at'     => $row['created_at'],
            ];
        }, $rows);
    }

    public function revokeShareLink(int $linkId): array
    {
        if ($linkId <= 0) {
            throw new \App\Core\ApiException('شناسه لینک نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        $existing = ExamResult::findShareLinkById($linkId);
        if (!$existing) {
            throw new \App\Core\ApiException('لینک نتیجه آزمون یافت نشد', 404, 'NOT_FOUND');
        }

        ExamResult::revokeShareLink($linkId);
        $updated = ExamResult::findShareLinkById($linkId) ?? $existing;

        return [
            'id'             => (int) $updated['id'],
            'student_id'     => (int) $updated['student_id'],
            'student_name'   => $updated['student_name'] ?? '',
            'exam_date'      => $updated['exam_date'],
            'status'         => 'revoked',
            'expires_at'     => $updated['expires_at'],
            'revoked_at'     => $updated['revoked_at'],
            'sms_sent_at'    => $updated['sms_sent_at'],
            'last_viewed_at' => $updated['last_viewed_at'],
            'created_by'     => isset($updated['created_by']) ? (int) $updated['created_by'] : null,
            'created_at'     => $updated['created_at'],
        ];
    }

    public function getPublicSharedResult(string $rawToken): array
    {
        $uniformNotFound = new \App\Core\ApiException(
            'لینک نتیجه آزمون معتبر نیست یا منقضی شده است',
            404,
            'NOT_FOUND'
        );

        $token = trim($rawToken);
        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) {
            $this->logShareLinkRejection('malformed', [
                'token_length' => strlen($token),
            ]);
            throw $uniformNotFound;
        }

        $tokenHash = hash('sha256', $token);
        $link = ExamResult::findShareLinkByHash($tokenHash);
        if (!$link) {
            $this->logShareLinkRejection('unknown_token', [
                'token_hash_prefix' => substr($tokenHash, 0, 12),
            ]);
            throw $uniformNotFound;
        }

        if (!empty($link['revoked_at'])) {
            $this->logShareLinkRejection('revoked', [
                'link_id'    => (int) $link['id'],
                'student_id' => (int) $link['student_id'],
                'revoked_at' => (string) $link['revoked_at'],
            ]);
            throw $uniformNotFound;
        }

        $isExpired = !empty($link['is_expired']);
        if (!$isExpired && !empty($link['expires_at'])) {
            $expiresTs = strtotime((string) $link['expires_at'] . ' UTC');
            if ($expiresTs !== false && $expiresTs <= time()) {
                $isExpired = true;
            }
        }
        if ($isExpired) {
            $this->logShareLinkRejection('expired', [
                'link_id'    => (int) $link['id'],
                'student_id' => (int) $link['student_id'],
                'expires_at' => (string) ($link['expires_at'] ?? ''),
            ]);
            throw $uniformNotFound;
        }

        $result = ExamResult::findPublicResult((int) $link['student_id'], (string) $link['exam_date']);
        if (!$result) {
            $this->logShareLinkRejection('result_missing', [
                'link_id'    => (int) $link['id'],
                'student_id' => (int) $link['student_id'],
                'exam_date'  => (string) $link['exam_date'],
            ]);
            throw $uniformNotFound;
        }

        // Links are multi-use within their 14-day validity window; only record last_viewed_at.
        ExamResult::touchShareLinkViewed((int) $link['id']);

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logShareLinkRejection(string $reason, array $context = []): void
    {
        if ($this->shareLinkLogger !== null) {
            ($this->shareLinkLogger)($reason, $context);
            return;
        }

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = '[' . gmdate('Y-m-d H:i:s') . '] [exam_share_link_404] reason=' . $reason . ' context=' . ($encoded ?: '{}');
        $logPath = __DIR__ . '/../../storage/logs/app.log';
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        if (@file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }

    private function resolveParentMobile(int $studentId, array $options = []): string
    {
        if (isset($options['phone']) && trim((string) $options['phone']) !== '') {
            return PhoneNumber::normalizeOrFail((string) $options['phone'], 'شماره تماس والد معتبر نیست');
        }

        if (!empty($options['parent_contact_id'])) {
            $contact = ParentContact::findForStudent($studentId, (int) $options['parent_contact_id']);
            if (!$contact) {
                throw new \App\Core\ApiException('مخاطب والد یافت نشد', 404, 'NOT_FOUND');
            }
            return PhoneNumber::normalizeOrFail($contact['phone'] ?? null, 'شماره تماس والد معتبر نیست');
        }

        $contacts = ParentContact::findAllForStudent($studentId);
        if ($contacts === []) {
            throw new \App\Core\ApiException('شماره تماس والد برای این دانش‌آموز ثبت نشده است', 422, 'VALIDATION_ERROR');
        }

        return PhoneNumber::normalizeOrFail($contacts[0]['phone'] ?? null, 'شماره تماس والد معتبر نیست');
    }

    private function enforceSmsRateLimits(string $mobile, int $studentId, string $examDate, ?string $ip = null): void
    {
        $cfg = RateLimiter::config();
        $fwPhone = RateLimiter::attemptFixedWindow(
            RateLimiter::key('sms:phone_win', $mobile),
            (int) ($cfg['sms_phone_max'] ?? 3),
            (int) ($cfg['sms_phone_window'] ?? 600)
        );
        if (!$fwPhone['allowed']) {
            throw new RateLimitedException($fwPhone['retry_after'] > 0 ? $fwPhone['retry_after'] : (int) ($cfg['sms_phone_window'] ?? 600));
        }

        $cooldownWindow = 120;
        $cooldownKey = RateLimiter::key('exam_sms:cooldown', "{$studentId}:{$examDate}:{$mobile}");
        $cooldownStatus = RateLimiter::status($cooldownKey, 1);
        if (!$cooldownStatus['allowed']) {
            throw new RateLimitedException($cooldownStatus['retry_after'] > 0 ? $cooldownStatus['retry_after'] : $cooldownWindow);
        }

        $smsWindow = 3600;
        $phoneMax = max(1, (int) ($_ENV['EXAM_SMS_PHONE_HOURLY_MAX'] ?? $_ENV['AUTH_SMS_PHONE_HOURLY_MAX'] ?? 5));
        $phoneKey = RateLimiter::key('exam_sms:phone', $mobile);
        $phoneStatus = RateLimiter::status($phoneKey, $phoneMax);
        if (!$phoneStatus['allowed']) {
            throw new RateLimitedException($phoneStatus['retry_after'] > 0 ? $phoneStatus['retry_after'] : $smsWindow);
        }

        $ipMax = max(1, (int) ($_ENV['EXAM_SMS_IP_HOURLY_MAX'] ?? $_ENV['AUTH_SMS_IP_HOURLY_MAX'] ?? 20));
        $ipKey = null;
        if ($ip !== null && $ip !== '') {
            $ipKey = RateLimiter::key('exam_sms:ip', $ip);
            $ipStatus = RateLimiter::status($ipKey, $ipMax);
            if (!$ipStatus['allowed']) {
                throw new RateLimitedException($ipStatus['retry_after'] > 0 ? $ipStatus['retry_after'] : $smsWindow);
            }
        }

        RateLimiter::attempt($cooldownKey, 1, $cooldownWindow, $cooldownWindow);
        RateLimiter::attempt($phoneKey, $phoneMax, $smsWindow, $smsWindow);
        if ($ipKey !== null) {
            RateLimiter::attempt($ipKey, $ipMax, $smsWindow, $smsWindow);
        }
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
