<?php

namespace App\Modules\Tutoring;

use App\Core\ApiException;
use App\Modules\Auth\User;
use App\Modules\Bot\IranDay;
use DateTimeImmutable;
use DateTimeZone;

class TutoringService
{
    public const STATUSES = ['absent', 'ready', 'break'];

    /**
     * @param array<string, mixed> $params
     * @return array{classroom: array<string, mixed>, status: ?array<string, mixed>, open_session: ?array<string, mixed>}
     */
    public function me(object $user, array $params = []): array
    {
        $classroom = $this->resolveClassroom($user, $params);
        $instructorId = (int) ($classroom['instructor_id'] ?? $this->resolveInstructorId($user, $params));

        $status = Tutoring::currentStatus($instructorId, IranDay::today());
        unset($classroom['instructor_id']);

        return [
            'classroom'    => $classroom,
            'status'       => $status === null ? null : [
                'status'      => $status['status'],
                'started_at'  => $status['started_at'],
                'status_date' => $status['status_date'],
            ],
            'open_session' => Tutoring::openSession($instructorId),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function setStatus(object $user, mixed $status, array $data = []): array
    {
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new ApiException('وضعیت نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        return Tutoring::setStatus($this->resolveInstructorId($user, $data), $status, IranDay::today());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function students(?string $query): array
    {
        return Tutoring::searchStudents(trim((string) $query));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{session: array<string, mixed>, created: bool}
     */
    public function startSession(object $user, array $data): array
    {
        $classroom = $this->resolveClassroom($user, $data);

        $studentId = (int) ($data['student_id'] ?? 0);
        if ($studentId <= 0) {
            throw new ApiException('شناسه دانش‌آموز الزامی است', 422, 'VALIDATION_ERROR');
        }

        $clientUuid = $data['client_uuid'] ?? null;
        if ($clientUuid !== null && (!is_string($clientUuid) || $clientUuid === '')) {
            throw new ApiException('شناسه یکتا نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        if ($clientUuid !== null) {
            $existing = Tutoring::findSessionByClientUuid($clientUuid);
            if ($existing !== null) {
                return ['session' => $existing, 'created' => false];
            }
        }

        $enteredAt = $this->normalizeTime($data['entered_at'] ?? $data['started_at'] ?? null) ?? IranDay::nowUtc();

        $session = Tutoring::startSession(
            $classroom['id'],
            $studentId,
            IranDay::today(),
            $enteredAt,
            (int) ($user->sub ?? 0),
            $clientUuid
        );

        return ['session' => $session, 'created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function endSession(object $user, int $sessionId, mixed $endedAt): array
    {
        $isAdmin = ($user->role ?? '') === 'admin';
        $instructorId = $isAdmin ? null : $this->resolveInstructorId($user);
        $value = $this->normalizeTime($endedAt) ?? IranDay::nowUtc();

        return Tutoring::endSession($instructorId, $sessionId, $value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function board(?string $since): array
    {
        $normalized = null;
        if ($since !== null && trim($since) !== '') {
            $normalized = $this->normalizeTime($since);
        }

        return Tutoring::board($normalized);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resolveClassroom(object $user, array $data = []): array
    {
        $isAdmin = ($user->role ?? '') === 'admin';

        if ($isAdmin) {
            if (!empty($data['classroom_id']) && (int) $data['classroom_id'] > 0) {
                $classroom = Tutoring::classroomById((int) $data['classroom_id']);
                if ($classroom === null) {
                    throw new ApiException('کلاس درس یافت نشد', 404, 'NOT_FOUND');
                }
                return $classroom;
            }

            if (!empty($data['instructor_id']) && (int) $data['instructor_id'] > 0) {
                $classroom = Tutoring::classroomForInstructor((int) $data['instructor_id']);
                if ($classroom === null) {
                    throw new ApiException('کلاس درس یافت نشد', 404, 'NOT_FOUND');
                }
                return $classroom;
            }

            $claim = $user->instructor_id ?? null;
            if ($claim !== null && (int) $claim > 0) {
                $classroom = Tutoring::classroomForInstructor((int) $claim);
                if ($classroom !== null) {
                    return $classroom;
                }
            }

            $classroom = Tutoring::firstActiveClassroom();
            if ($classroom !== null) {
                return $classroom;
            }

            throw new ApiException('کلاس درس یافت نشد', 404, 'NOT_FOUND');
        }

        $instructorId = $this->resolveInstructorId($user, $data);
        $classroom = Tutoring::classroomForInstructor($instructorId);
        if ($classroom === null) {
            throw new ApiException('کلاس درس یافت نشد', 404, 'NOT_FOUND');
        }

        return $classroom;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function resolveInstructorId(object $user, array $data = []): int
    {
        $isAdmin = ($user->role ?? '') === 'admin';

        if ($isAdmin) {
            if (!empty($data['instructor_id']) && (int) $data['instructor_id'] > 0) {
                return (int) $data['instructor_id'];
            }
            if (!empty($data['classroom_id']) && (int) $data['classroom_id'] > 0) {
                $classroom = Tutoring::classroomById((int) $data['classroom_id']);
                if ($classroom !== null && (int) $classroom['instructor_id'] > 0) {
                    return (int) $classroom['instructor_id'];
                }
            }
        }

        $claim = $user->instructor_id ?? null;
        if ($claim !== null && (int) $claim > 0) {
            return (int) $claim;
        }

        $userId = (int) ($user->sub ?? 0);
        $record = $userId > 0 ? User::findById($userId) : null;
        if ($record !== null && in_array($record['role'] ?? null, ['teacher', 'admin'], true) && (int) ($record['linked_id'] ?? 0) > 0) {
            return (int) $record['linked_id'];
        }

        if ($isAdmin) {
            $classroom = Tutoring::firstActiveClassroom();
            if ($classroom !== null && (int) $classroom['instructor_id'] > 0) {
                return (int) $classroom['instructor_id'];
            }
            throw new ApiException('کلاس درس یافت نشد', 404, 'NOT_FOUND');
        }

        throw new ApiException('دسترسی غیرمجاز', 403, 'FORBIDDEN');
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ApiException('مقدار زمان نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        try {
            $moment = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new ApiException('مقدار زمان نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
