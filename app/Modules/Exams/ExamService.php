<?php

namespace App\Modules\Exams;

use App\Core\Pagination;

class ExamService
{
    public function getDates(int $page, int $perPage, ?int $studentId = null): array
    {
        $total = ExamResult::countDates($studentId);
        $pagination = Pagination::build($page, $perPage, $total);
        $dates = ExamResult::findDates($studentId, $pagination['page'], $pagination['per_page']);
        return ['dates' => $dates, 'pagination' => $pagination];
    }

    public function getStudentsByDate(string $examDate, int $page, int $perPage): array
    {
        $total = ExamResult::countStudentsByDate($examDate);
        $pagination = Pagination::build($page, $perPage, $total);
        $students = ExamResult::findStudentsByDate($examDate, $pagination['page'], $pagination['per_page']);
        return ['students' => $students, 'pagination' => $pagination];
    }

    public function getDetails(string $examDate, int $studentId): array
    {
        $result = ExamResult::findDetails($examDate, $studentId);
        if (!$result['student']) throw new \App\Core\ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        return $result;
    }

    public function getAll(array $filters, int $page, int $perPage): array
    {
        $total = ExamResult::countAll($filters);
        $pagination = Pagination::build($page, $perPage, $total);
        $exams = ExamResult::findAll($filters, $pagination['page'], $pagination['per_page']);
        return ['exams' => $exams, 'pagination' => $pagination];
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

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
