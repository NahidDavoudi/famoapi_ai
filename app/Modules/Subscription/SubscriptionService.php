<?php

namespace App\Modules\Subscription;

use App\Core\ApiException;
use App\Modules\Courses\Course;
use App\Modules\Students\Student;

class SubscriptionService
{
    public const VALID_STATUSES = [
        'pending',
        'active',
        'expired',
        'cancelled',
        'canceled',
        'rejected',
    ];

    public const VALID_TUITION_STATUSES = [
        'unpaid',
        'paid',
        'pending',
        'exempt',
        'partial',
    ];

    /**
     * Format a raw subscription row joined with student & course metadata.
     */
    private function formatRow(array $row): array
    {
        $tuitionStatus = (string) ($row['tuition_status'] ?? 'unpaid');
        $tuitionLabels = [
            'unpaid'  => 'پرداخت نشده',
            'paid'    => 'پرداخت شده',
            'pending' => 'در انتظار بررسی',
            'exempt'  => 'معاف از شهریه',
            'partial' => 'پرداخت ناقص / بیعانه',
        ];

        return [
            'id'                   => (int) $row['id'],
            'course_id'            => (int) $row['course_id'],
            'student_id'           => (int) $row['student_id'],
            'status'               => (string) $row['status'],
            'is_active'            => (int) $row['is_active'],
            'monthly_fee'          => isset($row['monthly_fee']) ? (int) $row['monthly_fee'] : 0,
            'tuition_status'       => $tuitionStatus,
            'tuition_status_label' => $tuitionLabels[$tuitionStatus] ?? $tuitionStatus,
            'tuition_due_date'     => $row['tuition_due_date'] ?? null,
            'last_payment_date'    => $row['last_payment_date'] ?? null,
            'tuition_notes'        => $row['tuition_notes'] ?? null,
            'note'                 => $row['note'],
            'started_at'           => $row['started_at'],
            'end_at'               => $row['end_at'],
            'created_at'           => $row['created_at'],
            'updated_at'           => $row['updated_at'],
            'course'               => [
                'id'          => (int) $row['course_id'],
                'name'        => $row['course_name'] ?? null,
                'price'       => isset($row['course_price']) ? (int) $row['course_price'] : 0,
                'icon'        => $row['course_icon'] ?? null,
                'description' => $row['course_description'] ?? null,
            ],
            'student'              => [
                'id'    => (int) $row['student_id'],
                'name'  => $row['student_name'] ?? null,
                'phone' => $row['student_phone'] ?? null,
                'grade' => isset($row['student_grade']) ? (int) $row['student_grade'] : null,
                'field' => $row['student_field'] ?? null,
            ],
        ];
    }

    /**
     * Normalize status string ('canceled' -> 'cancelled') and compute default is_active flag.
     *
     * @return array{0: string, 1: int}
     */
    private function normalizeStatusAndActive(string $status, ?int $explicitIsActive = null): array
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'canceled') {
            $normalized = 'cancelled';
        }

        if ($explicitIsActive !== null) {
            return [$normalized, $explicitIsActive ? 1 : 0];
        }

        $isActive = in_array($normalized, ['pending', 'active'], true) ? 1 : 0;
        return [$normalized, $isActive];
    }

    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if (!empty($filters['status']) && $filters['status'] === 'canceled') {
            $filters['status'] = 'cancelled';
        }

        $total = Subscription::countAll($filters);
        $rows = Subscription::findAll($filters, $page, $perPage);
        $items = array_map([$this, 'formatRow'], $rows);

        return [
            'items'      => $items,
            'pagination' => [
                'total'      => $total,
                'page'       => $page,
                'perPage'    => $perPage,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function get(int $id, ?int $scopedStudentId = null): array
    {
        $row = Subscription::findById($id);
        if (!$row) {
            throw new ApiException('اشتراک مورد نظر یافت نشد', 404, 'SUBSCRIPTION_NOT_FOUND');
        }

        if ($scopedStudentId !== null && (int) $row['student_id'] !== $scopedStudentId) {
            throw new ApiException('شما به این اشتراک دسترسی ندارید', 403, 'FORBIDDEN');
        }

        return $this->formatRow($row);
    }

    public function listForStudent(int $studentId, bool $onlyActive = false): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز مورد نظر یافت نشد', 404, 'STUDENT_NOT_FOUND');
        }

        $rows = Subscription::findByStudent($studentId, $onlyActive);
        return array_map([$this, 'formatRow'], $rows);
    }

    public function create(array $data): array
    {
        $studentId = (int) $data['student_id'];
        $courseId = (int) $data['course_id'];

        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز مورد نظر یافت نشد', 404, 'STUDENT_NOT_FOUND');
        }

        $course = Course::findById($courseId);
        if (!$course) {
            throw new ApiException('دوره مورد نظر یافت نشد', 404, 'COURSE_NOT_FOUND');
        }

        $rawStatus = isset($data['status']) && $data['status'] !== '' ? (string) $data['status'] : 'pending';
        $explicitActive = isset($data['is_active']) && $data['is_active'] !== '' ? (int) $data['is_active'] : null;
        [$status, $isActive] = $this->normalizeStatusAndActive($rawStatus, $explicitActive);

        if ($isActive === 1 && in_array($status, ['pending', 'active'], true)) {
            $existing = Subscription::findActiveOrPendingByStudentAndCourse($studentId, $courseId);
            if ($existing) {
                throw new ApiException(
                    'این دانش‌آموز در حال حاضر اشتراک فعال یا در انتظار تایید برای این دوره دارد',
                    409,
                    'ALREADY_SUBSCRIBED'
                );
            }
        }

        $coursePrice = isset($course['price']) ? (int) preg_replace('/[^0-9]/', '', (string)$course['price']) : 0;
        $monthlyFee = isset($data['monthly_fee']) && $data['monthly_fee'] !== ''
            ? max(0, (int) $data['monthly_fee'])
            : $coursePrice;

        $tuitionStatus = isset($data['tuition_status']) && $data['tuition_status'] !== ''
            ? strtolower(trim((string) $data['tuition_status']))
            : 'unpaid';

        if (!in_array($tuitionStatus, self::VALID_TUITION_STATUSES, true)) {
            $tuitionStatus = 'unpaid';
        }

        $tuitionDueDate = !empty($data['tuition_due_date'])
            ? (string) $data['tuition_due_date']
            : date('Y-m-d', strtotime('+30 days'));

        $lastPaymentDate = !empty($data['last_payment_date'])
            ? (string) $data['last_payment_date']
            : ($tuitionStatus === 'paid' ? date('Y-m-d') : null);

        $tuitionNotes = isset($data['tuition_notes']) && $data['tuition_notes'] !== ''
            ? trim((string) $data['tuition_notes'])
            : null;

        $id = Subscription::create([
            'course_id'          => $courseId,
            'student_id'         => $studentId,
            'status'             => $status,
            'is_active'          => $isActive,
            'monthly_fee'        => $monthlyFee,
            'tuition_status'     => $tuitionStatus,
            'tuition_due_date'   => $tuitionDueDate,
            'last_payment_date'  => $lastPaymentDate,
            'tuition_notes'      => $tuitionNotes,
            'note'               => isset($data['note']) && $data['note'] !== '' ? trim((string) $data['note']) : null,
            'started_at'         => !empty($data['started_at']) ? (string) $data['started_at'] : null,
            'end_at'             => !empty($data['end_at']) ? (string) $data['end_at'] : null,
        ]);

        return $this->get($id);
    }

    /**
     * Backward-compatible alias for create().
     */
    public function set(array $data): array
    {
        return $this->create($data);
    }

    public function update(int $id, array $data): array
    {
        $existing = Subscription::findById($id);
        if (!$existing) {
            throw new ApiException('اشتراک مورد نظر یافت نشد', 404, 'SUBSCRIPTION_NOT_FOUND');
        }

        $updatePayload = [];

        $targetStudentId = (int) $existing['student_id'];
        if (array_key_exists('student_id', $data)) {
            $targetStudentId = (int) $data['student_id'];
            if (!Student::findById($targetStudentId)) {
                throw new ApiException('دانش‌آموز مورد نظر یافت نشد', 404, 'STUDENT_NOT_FOUND');
            }
            $updatePayload['student_id'] = $targetStudentId;
        }

        $targetCourseId = (int) $existing['course_id'];
        if (array_key_exists('course_id', $data)) {
            $targetCourseId = (int) $data['course_id'];
            if (!Course::findById($targetCourseId)) {
                throw new ApiException('دوره مورد نظر یافت نشد', 404, 'COURSE_NOT_FOUND');
            }
            $updatePayload['course_id'] = $targetCourseId;
        }

        $targetStatus = (string) $existing['status'];
        $targetIsActive = (int) $existing['is_active'];

        if (array_key_exists('status', $data) || array_key_exists('is_active', $data)) {
            $rawStatus = array_key_exists('status', $data) ? (string) $data['status'] : $targetStatus;
            $explicitActive = array_key_exists('is_active', $data) && $data['is_active'] !== null
                ? (int) $data['is_active']
                : null;
            [$targetStatus, $targetIsActive] = $this->normalizeStatusAndActive($rawStatus, $explicitActive);
            $updatePayload['status'] = $targetStatus;
            $updatePayload['is_active'] = $targetIsActive;
        }

        if ($targetIsActive === 1 && in_array($targetStatus, ['pending', 'active'], true)) {
            $duplicate = Subscription::findActiveOrPendingByStudentAndCourse($targetStudentId, $targetCourseId, $id);
            if ($duplicate) {
                throw new ApiException(
                    'این دانش‌آموز در حال حاضر اشتراک فعال یا در انتظار تایید دیگری برای این دوره دارد',
                    409,
                    'ALREADY_SUBSCRIBED'
                );
            }
        }

        if (array_key_exists('note', $data)) {
            $updatePayload['note'] = $data['note'] !== null && $data['note'] !== ''
                ? trim((string) $data['note'])
                : null;
        }

        if (array_key_exists('monthly_fee', $data) && $data['monthly_fee'] !== null && $data['monthly_fee'] !== '') {
            $updatePayload['monthly_fee'] = max(0, (int) $data['monthly_fee']);
        }

        if (array_key_exists('tuition_status', $data) && $data['tuition_status'] !== null && $data['tuition_status'] !== '') {
            $ts = strtolower(trim((string) $data['tuition_status']));
            if (!in_array($ts, self::VALID_TUITION_STATUSES, true)) {
                throw new ApiException('وضعیت شهریه نامعتبر است', 422, 'INVALID_TUITION_STATUS');
            }
            $updatePayload['tuition_status'] = $ts;
            if ($ts === 'paid' && empty($data['last_payment_date']) && empty($existing['last_payment_date'])) {
                $updatePayload['last_payment_date'] = date('Y-m-d');
            }
        }

        if (array_key_exists('tuition_due_date', $data)) {
            $updatePayload['tuition_due_date'] = !empty($data['tuition_due_date']) ? (string) $data['tuition_due_date'] : null;
        }

        if (array_key_exists('last_payment_date', $data)) {
            $updatePayload['last_payment_date'] = !empty($data['last_payment_date']) ? (string) $data['last_payment_date'] : null;
        }

        if (array_key_exists('tuition_notes', $data)) {
            $updatePayload['tuition_notes'] = $data['tuition_notes'] !== null && $data['tuition_notes'] !== ''
                ? trim((string) $data['tuition_notes'])
                : null;
        }

        if (array_key_exists('started_at', $data) && $data['started_at'] !== null && $data['started_at'] !== '') {
            $updatePayload['started_at'] = (string) $data['started_at'];
        }

        if (array_key_exists('end_at', $data)) {
            $updatePayload['end_at'] = $data['end_at'] !== null && $data['end_at'] !== ''
                ? (string) $data['end_at']
                : null;
        }

        Subscription::update($id, $updatePayload);

        return $this->get($id);
    }

    public function updateTuition(int $id, array $data): array
    {
        $existing = Subscription::findById($id);
        if (!$existing) {
            throw new ApiException('اشتراک مورد نظر یافت نشد', 404, 'SUBSCRIPTION_NOT_FOUND');
        }

        $payload = [];
        if (array_key_exists('tuition_status', $data) && $data['tuition_status'] !== null && $data['tuition_status'] !== '') {
            $ts = strtolower(trim((string) $data['tuition_status']));
            if (!in_array($ts, self::VALID_TUITION_STATUSES, true)) {
                throw new ApiException('وضعیت شهریه نامعتبر است', 422, 'INVALID_TUITION_STATUS');
            }
            $payload['tuition_status'] = $ts;
            if ($ts === 'paid' && empty($data['last_payment_date']) && empty($existing['last_payment_date'])) {
                $payload['last_payment_date'] = date('Y-m-d');
            }
        }

        if (array_key_exists('monthly_fee', $data) && $data['monthly_fee'] !== null && $data['monthly_fee'] !== '') {
            $payload['monthly_fee'] = max(0, (int) $data['monthly_fee']);
        }

        if (array_key_exists('tuition_due_date', $data)) {
            $payload['tuition_due_date'] = !empty($data['tuition_due_date']) ? (string) $data['tuition_due_date'] : null;
        }

        if (array_key_exists('last_payment_date', $data)) {
            $payload['last_payment_date'] = !empty($data['last_payment_date']) ? (string) $data['last_payment_date'] : null;
        }

        if (array_key_exists('tuition_notes', $data)) {
            $payload['tuition_notes'] = $data['tuition_notes'] !== null && $data['tuition_notes'] !== ''
                ? trim((string) $data['tuition_notes'])
                : null;
        }

        if (!empty($payload)) {
            Subscription::updateTuition($id, $payload);
        }

        return $this->get($id);
    }

    public function updateStatus(int $id, string $status, ?string $note = null): array
    {
        $existing = Subscription::findById($id);
        if (!$existing) {
            throw new ApiException('اشتراک مورد نظر یافت نشد', 404, 'SUBSCRIPTION_NOT_FOUND');
        }

        [$normalizedStatus, $isActive] = $this->normalizeStatusAndActive($status);

        if ($isActive === 1 && in_array($normalizedStatus, ['pending', 'active'], true)) {
            $duplicate = Subscription::findActiveOrPendingByStudentAndCourse(
                (int) $existing['student_id'],
                (int) $existing['course_id'],
                $id
            );
            if ($duplicate) {
                throw new ApiException(
                    'این دانش‌آموز در حال حاضر اشتراک فعال یا در انتظار تایید دیگری برای این دوره دارد',
                    409,
                    'ALREADY_SUBSCRIBED'
                );
            }
        }

        Subscription::updateStatus($id, $normalizedStatus, $isActive, $note);

        return $this->get($id);
    }

    public function delete(int $id, ?int $scopedStudentId = null): void
    {
        $existing = Subscription::findById($id);
        if (!$existing) {
            throw new ApiException('اشتراک مورد نظر یافت نشد', 404, 'SUBSCRIPTION_NOT_FOUND');
        }

        if ($scopedStudentId !== null) {
            if ((int) $existing['student_id'] !== $scopedStudentId) {
                throw new ApiException('شما به این اشتراک دسترسی ندارید', 403, 'FORBIDDEN');
            }

            if ((string) $existing['status'] !== 'pending') {
                throw new ApiException(
                    'فقط اشتراک‌های در انتظار تایید توسط دانش‌آموز قابل لغو یا حذف هستند',
                    400,
                    'CANNOT_DELETE_SUBSCRIPTION'
                );
            }
        }

        Subscription::delete($id);
    }
}