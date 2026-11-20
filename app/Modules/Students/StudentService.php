<?php

namespace App\Modules\Students;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\JwtRevocation;
use App\Core\Pagination;
use App\Core\PhoneNumber;
use App\Modules\Supporters\Supporter;

class StudentService
{
    public function list(array $filters, int $page, int $perPage): array
    {
        $total      = Student::countAll($filters);
        $pagination = Pagination::build($page, $perPage, $total);

        $items = Student::findAll($filters, $pagination['page'], $pagination['per_page']);

        return [
            'items'      => $items,
            'pagination' => $pagination,
        ];
    }

    public function create(array $data): array
    {
        $data['phone'] = PhoneNumber::normalizeOrFail($data['phone'] ?? null, 'شماره تلفن دانش‌آموز معتبر نیست');
        $isPlaceholderPhone = ($data['phone'] === '09000000000');

        if (!$isPlaceholderPhone && \App\Modules\Auth\User::findByPhone($data['phone'])) {
            throw new ApiException('این شماره تلفن قبلاً ثبت شده است', 409, 'DUPLICATE_PHONE');
        }

        // پشتیبان رو بر اساس (رشته، پایه) از supporter_scopes پیدا کن
        $supporterId = Supporter::findForStudent(
            (string) ($data['field'] ?? ''),
            (int) ($data['grade'] ?? 0)
        );

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $data['supporter_id'] = $supporterId;

            $studentId = Student::create($data);

            if (!$isPlaceholderPhone) {
                $stmt = $db->prepare(
                    'INSERT INTO users (username, phone, full_name, password_hash, role, linked_id)
                     VALUES (:username, :phone, :full_name, :password_hash, :role, :linked_id)'
                );
                $stmt->execute([
                    'username'      => $data['phone'],
                    'phone'         => $data['phone'],
                    'full_name'     => $data['name'],
                    'password_hash' => password_hash('1234', PASSWORD_DEFAULT),
                    'role'          => 'student',
                    'linked_id'     => $studentId,
                ]);
            }

            $db->commit();
        } catch (ApiException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new ApiException('خطا در ثبت دانش‌آموز', 500, 'REGISTER_FAILED', $e);
        }

        return $this->get($studentId);
    }

    public function get(int $id): array
    {
        $student = Student::findById($id);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }
        return $student;
    }

    public function update(int $id, array $data): array
    {
        $student = Student::findById($id);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = PhoneNumber::normalizeOrFail($data['phone'], 'شماره تلفن دانش‌آموز معتبر نیست');
        }

        Student::update($id, $data);

        if (isset($data['phone']) && $data['phone'] !== $student['phone']) {
            $stmt = Database::getConnection()->prepare(
                'UPDATE users SET username = :username, phone = :phone WHERE linked_id = :linked_id AND role = :role'
            );
            $stmt->execute([
                'username'  => $data['phone'],
                'phone'     => $data['phone'],
                'linked_id' => $id,
                'role'      => 'student',
            ]);
        }

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $student = Student::findById($id);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        Student::delete($id);
    }

    public function createUserAccount(int $studentId): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        if (!empty($student['user_id'])) {
            throw new ApiException('این دانش‌آموز قبلاً حساب کاربری دارد', 409, 'ACCOUNT_ERROR');
        }

        $phone = PhoneNumber::normalizeOrFail($student['phone'] ?? null, 'شماره تلفن دانش‌آموز معتبر نیست');

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO users (username, phone, full_name, password_hash, role, linked_id)
             VALUES (:username, :phone, :full_name, :password_hash, :role, :linked_id)'
        );
        $stmt->execute([
            'username'      => $phone,
            'phone'         => $phone,
            'full_name'     => $student['name'],
            'password_hash' => password_hash('1234', PASSWORD_DEFAULT),
            'role'          => 'student',
            'linked_id'     => $studentId,
        ]);

        return $this->get($studentId);
    }

    public function resetPassword(int $studentId): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        if (empty($student['user_id'])) {
            throw new ApiException('این دانش‌آموز حساب کاربری ندارد', 400, 'RESET_ERROR');
        }

        $tempPassword = self::generateTemporaryPassword(10);
        $userId = (int) $student['user_id'];

        $stmt = Database::getConnection()->prepare(
            'UPDATE users SET password_hash = :password WHERE id = :id'
        );
        $stmt->execute([
            'password' => password_hash($tempPassword, PASSWORD_DEFAULT),
            'id'       => $userId,
        ]);

        JwtRevocation::revokeAllForUser($userId);

        $updated = $this->get($studentId);
        $updated['temporary_password'] = $tempPassword;

        return $updated;
    }

    public function getList(): array
    {
        return Student::getList();
    }

    public function getPlannerOverview(): array
    {
        return Student::plannerOverview();
    }

    public function toggleStatus(int $id): array
    {
        $student = Student::findById($id);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        $newActive = ((int) ($student['is_active'] ?? 1) === 1) ? 0 : 1;

        $db = Database::getConnection();
        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmtStudent = $db->prepare('UPDATE students SET is_active = :is_active WHERE id = :id');
            $stmtStudent->execute([
                'is_active' => $newActive,
                'id'        => $id,
            ]);

            $stmtUser = $db->prepare(
                'UPDATE users SET is_active = :is_active WHERE linked_id = :linked_id AND role = :role'
            );
            $stmtUser->execute([
                'is_active' => $newActive,
                'linked_id' => $id,
                'role'      => 'student',
            ]);

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($newActive === 0 && !empty($student['user_id'])) {
            JwtRevocation::revokeAllForUser((int) $student['user_id']);
        }

        return $this->get($id);
    }

    private static function generateTemporaryPassword(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $maxIndex = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $maxIndex)];
        }
        return $out;
    }

    public function getAnalyticsSummary(int $studentId): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT COUNT(*) FROM events WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $planCount = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM exam_results WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $examCount = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT ROUND(AVG(percentage), 1) FROM exam_results WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $avgPercentage = (float) ($stmt->fetchColumn() ?: 0);

        $stmt = $db->prepare(
            'SELECT er.*, s.name AS student_name
             FROM exam_results er
             JOIN students s ON er.student_id = s.id
             WHERE er.student_id = ?
             ORDER BY er.exam_date DESC
             LIMIT 5'
        );
        $stmt->execute([$studentId]);
        $recentExams = $stmt->fetchAll();

        $stmt = $db->prepare(
            'SELECT subject, ROUND(AVG(percentage), 1) AS avg_percentage, COUNT(*) AS total_exams
             FROM exam_results
             WHERE student_id = ?
             GROUP BY subject
             ORDER BY avg_percentage ASC
             LIMIT 1'
        );
        $stmt->execute([$studentId]);
        $weakest = $stmt->fetch();

        $stmt = $db->prepare(
            'SELECT subject, ROUND(AVG(percentage), 1) AS avg_percentage, COUNT(*) AS total_exams
             FROM exam_results
             WHERE student_id = ?
             GROUP BY subject
             ORDER BY avg_percentage DESC
             LIMIT 1'
        );
        $stmt->execute([$studentId]);
        $strongest = $stmt->fetch();

        return [
            'student' => [
                'id'    => $student['id'],
                'name'  => $student['name'],
                'grade' => $student['grade'],
                'field' => $student['field'],
            ],
            'plan_count'        => $planCount,
            'exam_count'        => $examCount,
            'avg_percentage'    => $avgPercentage,
            'recent_exams'      => $recentExams,
            'weakest_subject'   => $weakest ?: null,
            'strongest_subject' => $strongest ?: null,
        ];
    }

    public function getAnalyticsWeekDetail(int $studentId): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM events WHERE student_id = ? ORDER BY day_index ASC, time_index ASC'
        );
        $stmt->execute([$studentId]);
        $events = $stmt->fetchAll();

        $DAY_NAMES = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
        $schedule = [];
        foreach ($DAY_NAMES as $day) {
            $schedule[$day] = [];
        }
        foreach ($events as $ev) {
            $dayName = $ev['day_name'];
            if (!isset($schedule[$dayName])) {
                continue;
            }
            $schedule[$dayName][] = [
                'time'     => $ev['time_label'],
                'subject'  => $ev['subject_name'],
                'topic'    => $ev['topic_label'],
                'activity' => $ev['title'],
                'notes'    => $ev['notes'],
                'color'    => $ev['color'],
            ];
        }

        return [
            'student'  => ['id' => $student['id'], 'name' => $student['name']],
            'schedule' => $schedule,
            'total'    => count($events),
        ];
    }

    public function getAnalyticsSubjectStats(int $studentId): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT subject, ROUND(AVG(percentage), 1) AS avg_percentage, COUNT(*) AS total_exams
             FROM exam_results
             WHERE student_id = ?
             GROUP BY subject
             ORDER BY avg_percentage DESC'
        );
        $stmt->execute([$studentId]);
        $subjects = $stmt->fetchAll();

        return [
            'student'  => ['id' => $student['id'], 'name' => $student['name']],
            'subjects' => $subjects,
        ];
    }

    public function getAnalyticsExamTrend(int $studentId): array
    {
        $student = Student::findById($studentId);
        if (!$student) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT exam_date, ROUND(AVG(percentage), 1) AS avg_percentage
             FROM exam_results
             WHERE student_id = ?
             GROUP BY exam_date
             ORDER BY exam_date ASC'
        );
        $stmt->execute([$studentId]);
        $trend = $stmt->fetchAll();

        return [
            'student' => ['id' => $student['id'], 'name' => $student['name']],
            'trend'   => $trend,
        ];
    }
}