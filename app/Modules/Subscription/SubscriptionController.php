<?php

namespace App\Modules\Subscription;

use App\Core\ApiException;
use App\Core\ResponseHelper;
use App\Core\StudentScope;
use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SubscriptionController
{
    public function __construct(private SubscriptionService $service)
    {
    }

    /**
     * GET /api/v1/subscriptions
     * Staff/Admin can list all subscriptions with filters; students see only their own.
     */
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 20);

        $requestedStudentId = isset($params['student_id']) && $params['student_id'] !== ''
            ? (int) $params['student_id']
            : null;

        try {
            $scopedStudentId = StudentScope::studentId($request, $requestedStudentId, false);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        $filters = [
            'student_id' => $scopedStudentId,
            'course_id'  => $params['course_id'] ?? null,
            'status'     => $params['status'] ?? null,
            'is_active'  => isset($params['is_active']) && $params['is_active'] !== '' ? (int) $params['is_active'] : null,
            'search'     => $params['search'] ?? null,
        ];

        $result = $this->service->list($filters, $page, $perPage);

        return ResponseHelper::paginated($response, $result['items'], $result['pagination']);
    }

    /**
     * GET /api/v1/subscriptions/me
     * Returns subscriptions for the currently authenticated student.
     */
    public function mySubscriptions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $onlyActive = isset($params['only_active']) && in_array((string) $params['only_active'], ['1', 'true'], true);

        try {
            $studentId = (int) StudentScope::studentId($request, null, true);
            $items = $this->service->listForStudent($studentId, $onlyActive);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, $items);
    }

    /**
     * GET /api/v1/students/{id}/subscriptions
     * Returns all subscriptions for a specific student (student self-scope enforced).
     */
    public function studentSubscriptions(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $onlyActive = isset($params['only_active']) && in_array((string) $params['only_active'], ['1', 'true'], true);

        try {
            $studentId = (int) StudentScope::studentId($request, (int) $args['id'], true);
            $items = $this->service->listForStudent($studentId, $onlyActive);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, $items);
    }

    /**
     * GET /api/v1/subscriptions/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $scopedStudentId = StudentScope::isStudent($request)
                ? (int) StudentScope::studentId($request, null, true)
                : null;

            $result = $this->service->get((int) $args['id'], $scopedStudentId);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, $result);
    }

    /**
     * POST /api/v1/subscriptions
     * Students can subscribe themselves (status = 'pending'); staff/admin can create for any student.
     */
    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $isStudent = StudentScope::isStudent($request);

        $requestedStudentId = isset($body['student_id']) && $body['student_id'] !== ''
            ? (int) $body['student_id']
            : null;

        try {
            $studentId = $isStudent
                ? (int) StudentScope::studentId($request, $requestedStudentId, true)
                : $requestedStudentId;
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        $validator = new Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $studentId)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $studentId)
            ->required('course_id', 'شناسه دوره', $body['course_id'] ?? null)
            ->positiveInt('course_id', 'شناسه دوره', $body['course_id'] ?? null)
            ->string('note', 'یادداشت', $body['note'] ?? null)
            ->maxLength('note', 'یادداشت', $body['note'] ?? null, 1000);

        if (!$isStudent && isset($body['status']) && $body['status'] !== '') {
            $validator
                ->string('status', 'وضعیت اشتراک', $body['status'])
                ->inArray('status', 'وضعیت اشتراک', $body['status'], SubscriptionService::VALID_STATUSES);
        }

        if (!$isStudent && isset($body['is_active']) && $body['is_active'] !== '') {
            $validator->inArray('is_active', 'وضعیت فعال بودن', (int) $body['is_active'], [0, 1]);
        }

        if (!$validator->passes()) {
            return $this->validationErrorResponse($response, $validator->firstError());
        }

        try {
            $payload = [
                'student_id' => (int) $studentId,
                'course_id'  => (int) $body['course_id'],
                'note'       => $body['note'] ?? null,
                'status'     => !$isStudent && !empty($body['status']) ? (string) $body['status'] : 'pending',
                'is_active'  => !$isStudent && isset($body['is_active']) && $body['is_active'] !== ''
                    ? (int) $body['is_active']
                    : null,
                'started_at' => !$isStudent ? ($body['started_at'] ?? null) : null,
                'end_at'     => !$isStudent ? ($body['end_at'] ?? null) : null,
            ];

            $result = $this->service->create($payload);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, $result, null, 201);
    }

    /**
     * PUT /api/v1/subscriptions/{id}
     * Staff/Admin update of subscription details.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $validator = new Validator();
        $data = [];

        if (array_key_exists('student_id', $body) && $body['student_id'] !== null && $body['student_id'] !== '') {
            $validator->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id']);
            $data['student_id'] = (int) $body['student_id'];
        }

        if (array_key_exists('course_id', $body) && $body['course_id'] !== null && $body['course_id'] !== '') {
            $validator->positiveInt('course_id', 'شناسه دوره', $body['course_id']);
            $data['course_id'] = (int) $body['course_id'];
        }

        if (array_key_exists('status', $body) && $body['status'] !== null && $body['status'] !== '') {
            $validator
                ->string('status', 'وضعیت اشتراک', $body['status'])
                ->inArray('status', 'وضعیت اشتراک', $body['status'], SubscriptionService::VALID_STATUSES);
            $data['status'] = (string) $body['status'];
        }

        if (array_key_exists('is_active', $body) && $body['is_active'] !== null && $body['is_active'] !== '') {
            $validator->inArray('is_active', 'وضعیت فعال بودن', (int) $body['is_active'], [0, 1]);
            $data['is_active'] = (int) $body['is_active'];
        }

        if (array_key_exists('note', $body)) {
            $validator
                ->string('note', 'یادداشت', $body['note'])
                ->maxLength('note', 'یادداشت', $body['note'], 1000);
            $data['note'] = $body['note'];
        }

        if (array_key_exists('started_at', $body)) {
            $validator->string('started_at', 'تاریخ شروع', $body['started_at']);
            $data['started_at'] = $body['started_at'];
        }

        if (array_key_exists('end_at', $body)) {
            $validator->string('end_at', 'تاریخ پایان', $body['end_at']);
            $data['end_at'] = $body['end_at'];
        }

        if (empty($data)) {
            $validator->addError('حداقل یک فیلد معتبر برای ویرایش اشتراک الزامی است');
        }

        if (!$validator->passes()) {
            return $this->validationErrorResponse($response, $validator->firstError());
        }

        try {
            $result = $this->service->update((int) $args['id'], $data);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, $result);
    }

    /**
     * PATCH|PUT /api/v1/subscriptions/{id}/status
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $validator = new Validator();
        $validator
            ->required('status', 'وضعیت اشتراک', $body['status'] ?? null)
            ->string('status', 'وضعیت اشتراک', $body['status'] ?? null)
            ->inArray('status', 'وضعیت اشتراک', $body['status'] ?? null, SubscriptionService::VALID_STATUSES)
            ->string('note', 'یادداشت', $body['note'] ?? null)
            ->maxLength('note', 'یادداشت', $body['note'] ?? null, 1000);

        if (!$validator->passes()) {
            return $this->validationErrorResponse($response, $validator->firstError());
        }

        try {
            $note = array_key_exists('note', $body) && $body['note'] !== null ? (string) $body['note'] : null;
            $result = $this->service->updateStatus((int) $args['id'], (string) $body['status'], $note);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, $result);
    }

    /**
     * DELETE /api/v1/subscriptions/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $scopedStudentId = StudentScope::isStudent($request)
                ? (int) StudentScope::studentId($request, null, true)
                : null;

            $this->service->delete((int) $args['id'], $scopedStudentId);
        } catch (ApiException $e) {
            return $this->errorResponse($response, $e);
        }

        return ResponseHelper::json($response, null);
    }

    private function validationErrorResponse(Response $response, ?string $message): Response
    {
        $response->getBody()->write(json_encode([
            'success'    => false,
            'data'       => null,
            'pagination' => null,
            'error'      => [
                'code'    => 'VALIDATION_ERROR',
                'message' => $message ?? 'اطلاعات ارسالی نامعتبر است',
            ],
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(422)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function errorResponse(Response $response, ApiException $e): Response
    {
        $response->getBody()->write(json_encode([
            'success'    => false,
            'data'       => null,
            'pagination' => null,
            'error'      => [
                'code'    => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ],
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($e->getHttpStatus())
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
