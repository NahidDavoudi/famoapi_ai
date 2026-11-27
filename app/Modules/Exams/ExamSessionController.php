<?php

declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\ApiException;
use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP endpoints for Lernito shelf browsing, student-shelf assignment, and Exam Sessions (Phase 2).
 */
final class ExamSessionController
{
    private ?ExamSessionService $service;

    public function __construct(?ExamSessionService $service = null)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/admin/lernito/shelves
     */
    public function listShelves(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 30)));

        $activeOnly = true;
        if (isset($q['active_only']) && in_array(strtolower((string) $q['active_only']), ['0', 'false'], true)) {
            $activeOnly = false;
        }

        $result = $this->service()->listShelves([
            'account'     => isset($q['account']) ? (string) $q['account'] : null,
            'q'           => isset($q['q']) ? (string) $q['q'] : null,
            'active_only' => $activeOnly,
            'student_id'  => isset($q['student_id']) && ctype_digit((string) $q['student_id']) ? (int) $q['student_id'] : null,
        ], $page, $perPage);

        return ResponseHelper::paginated($response, ['shelves' => $result['shelves']], $result['pagination']);
    }

    /**
     * GET /api/v1/students/{id}/shelves
     */
    public function listStudentShelves(Request $request, Response $response, array $args): Response
    {
        $studentId = (int) ($args['id'] ?? 0);
        try {
            $shelves = $this->service()->listStudentShelves($studentId);
            return ResponseHelper::json($response, ['shelves' => $shelves]);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    /**
     * POST /api/v1/students/{id}/shelves
     */
    public function assignStudentShelf(Request $request, Response $response, array $args): Response
    {
        $studentId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $shelfId = isset($body['shelf_id']) && is_numeric($body['shelf_id']) ? (int) $body['shelf_id'] : 0;
        if ($shelfId <= 0) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', 'فیلد shelf_id الزامی و باید عدد صحیح مثبت باشد.', 422);
        }

        $user = $request->getAttribute('user');
        $actorUserId = isset($user->sub) ? (int) $user->sub : (isset($user->id) ? (int) $user->id : null);
        $notes = isset($body['notes']) && is_string($body['notes']) ? $body['notes'] : null;

        try {
            $shelves = $this->service()->assignStudentShelf($studentId, $shelfId, $actorUserId, $notes);
            return ResponseHelper::json($response, ['shelves' => $shelves], null, 201);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    /**
     * DELETE /api/v1/students/{id}/shelves/{shelfId}
     */
    public function unassignStudentShelf(Request $request, Response $response, array $args): Response
    {
        $studentId = (int) ($args['id'] ?? 0);
        $shelfId = (int) ($args['shelfId'] ?? 0);

        try {
            $removed = $this->service()->unassignStudentShelf($studentId, $shelfId);
            return ResponseHelper::json($response, ['removed' => $removed]);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    /**
     * GET /api/v1/exam-sessions
     */
    public function listSessions(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 20)));

        $result = $this->service()->listSessions([
            'student_id' => isset($q['student_id']) && ctype_digit((string) $q['student_id']) ? (int) $q['student_id'] : null,
            'exam_date'  => isset($q['exam_date']) && is_string($q['exam_date']) ? trim($q['exam_date']) : null,
            'shelf_id'   => isset($q['shelf_id']) && ctype_digit((string) $q['shelf_id']) ? (int) $q['shelf_id'] : null,
        ], $page, $perPage);

        return ResponseHelper::paginated($response, ['sessions' => $result['sessions']], $result['pagination']);
    }

    /**
     * POST /api/v1/exam-sessions
     */
    public function createSession(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $studentId = isset($body['student_id']) && is_numeric($body['student_id']) ? (int) $body['student_id'] : 0;
        $shelfId = isset($body['shelf_id']) && is_numeric($body['shelf_id']) ? (int) $body['shelf_id'] : 0;
        $examDate = isset($body['exam_date']) && is_string($body['exam_date']) ? trim($body['exam_date']) : '';

        if ($studentId <= 0 || $shelfId <= 0 || $examDate === '') {
            return ResponseHelper::error(
                $response,
                'VALIDATION_ERROR',
                'فیلدهای student_id، shelf_id و exam_date (به فرمت YYYY-MM-DD) الزامی هستند.',
                422
            );
        }

        $user = $request->getAttribute('user');
        $actorUserId = isset($user->sub) ? (int) $user->sub : (isset($user->id) ? (int) $user->id : null);

        try {
            $result = $this->service()->createOrGetSession($studentId, $shelfId, $examDate, $actorUserId);
            $status = !empty($result['created']) ? 201 : 200;
            return ResponseHelper::json($response, $result, null, $status);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    /**
     * GET /api/v1/exam-sessions/{id}
     */
    public function getSession(Request $request, Response $response, array $args): Response
    {
        $sessionId = (int) ($args['id'] ?? 0);
        try {
            $result = $this->service()->getSession($sessionId);
            return ResponseHelper::json($response, $result);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    /**
     * PUT /api/v1/exam-sessions/{id}/topics
     */
    public function updateTopics(Request $request, Response $response, array $args): Response
    {
        $sessionId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $topics = $body['topics'] ?? null;

        if (!is_array($topics)) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', 'فیلد topics باید آرایه باشد.', 422);
        }

        try {
            $result = $this->service()->updateSessionTopics($sessionId, $topics);
            return ResponseHelper::json($response, $result);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    /**
     * POST /api/v1/exam-sessions/{id}/recalculate
     */
    public function recalculateTopics(Request $request, Response $response, array $args): Response
    {
        $sessionId = (int) ($args['id'] ?? 0);
        try {
            $result = $this->service()->recalculateSessionTopics($sessionId);
            return ResponseHelper::json($response, $result);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
    }

    private function service(): ExamSessionService
    {
        return $this->service ?? new ExamSessionService();
    }
}
