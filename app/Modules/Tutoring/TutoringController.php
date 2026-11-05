<?php

namespace App\Modules\Tutoring;

use App\Core\ApiException;
use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TutoringController
{
    public function __construct(private TutoringService $service)
    {
    }

    private function ok(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $data,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function fail(Response $response, ApiException $e): Response
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

        return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function me(Request $request, Response $response): Response
    {
        try {
            return $this->ok($response, $this->service->me($this->caller($request), $request->getQueryParams()));
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function setStatus(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('status', 'وضعیت', $body['status'] ?? null)
            ->inArray('status', 'وضعیت', $body['status'] ?? null, TutoringService::STATUSES)
            ->positiveInt('instructor_id', 'شناسه مدرس', $body['instructor_id'] ?? null)
            ->positiveInt('classroom_id', 'شناسه کلاس', $body['classroom_id'] ?? null);

        if (!$validator->passes()) {
            return $this->fail($response, new ApiException($validator->firstError(), 422, 'VALIDATION_ERROR'));
        }

        try {
            return $this->ok($response, $this->service->setStatus($this->caller($request), $body['status'], $body));
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function students(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        try {
            return $this->ok($response, $this->service->students($params['q'] ?? null));
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function startSession(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('classroom_id', 'شناسه کلاس', $body['classroom_id'] ?? null)
            ->positiveInt('instructor_id', 'شناسه مدرس', $body['instructor_id'] ?? null);

        if (isset($body['started_at']) && $body['started_at'] !== null) {
            $validator->string('started_at', 'زمان شروع', $body['started_at']);
        }
        if (isset($body['entered_at']) && $body['entered_at'] !== null) {
            $validator->string('entered_at', 'زمان ورود', $body['entered_at']);
        }

        if (!$validator->passes()) {
            return $this->fail($response, new ApiException($validator->firstError(), 422, 'VALIDATION_ERROR'));
        }

        try {
            $result = $this->service->startSession($this->caller($request), $body);

            return $this->ok($response, $result['session'], $result['created'] ? 201 : 200);
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function endSession(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        if (isset($body['ended_at']) && $body['ended_at'] !== null) {
            $validator->string('ended_at', 'زمان پایان', $body['ended_at']);
        }
        if (!$validator->passes()) {
            return $this->fail($response, new ApiException($validator->firstError(), 422, 'VALIDATION_ERROR'));
        }

        try {
            return $this->ok($response, $this->service->endSession(
                $this->caller($request),
                (int) $args['id'],
                $body['ended_at'] ?? null
            ));
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function board(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        try {
            return $this->ok($response, $this->service->board($params['since'] ?? null));
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function listClassrooms(Request $request, Response $response): Response
    {
        try {
            return $this->ok($response, $this->service->listClassrooms());
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function createClassroom(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $name = $body['name'] ?? $body['class_name'] ?? $body['classroom_name'] ?? $body['teacher_name'] ?? null;

        $validator = new Validator();
        $validator
            ->required('name', 'نام کلاس', $name)
            ->string('name', 'نام کلاس', $name)
            ->maxLength('name', 'نام کلاس', $name, 100)
            ->positiveInt('instructor_id', 'شناسه مدرس', $body['instructor_id'] ?? null)
            ->integer('sort_order', 'ترتیب نمایش', $body['sort_order'] ?? null, 0);

        if (isset($body['teacher_name']) && $body['teacher_name'] !== null) {
            $validator->string('teacher_name', 'نام مدرس', $body['teacher_name'])
                ->maxLength('teacher_name', 'نام مدرس', $body['teacher_name'], 255);
        }
        if (isset($body['field']) && $body['field'] !== null) {
            $validator->string('field', 'رشته', $body['field'])
                ->maxLength('field', 'رشته', $body['field'], 255);
        }

        if (!$validator->passes()) {
            return $this->fail($response, new ApiException($validator->firstError(), 422, 'VALIDATION_ERROR'));
        }

        try {
            return $this->ok($response, $this->service->createClassroom($body), 201);
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function updateClassroom(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];
        $name = $body['name'] ?? $body['class_name'] ?? $body['classroom_name'] ?? null;

        $validator = new Validator();
        $validator->requireBody($body);
        if ($name !== null) {
            $validator->string('name', 'نام کلاس', $name)->maxLength('name', 'نام کلاس', $name, 100);
        }
        $validator->integer('sort_order', 'ترتیب نمایش', $body['sort_order'] ?? null, 0);
        if (isset($body['teacher_name']) && $body['teacher_name'] !== null) {
            $validator->string('teacher_name', 'نام مدرس', $body['teacher_name'])
                ->maxLength('teacher_name', 'نام مدرس', $body['teacher_name'], 255);
        }
        if (isset($body['field']) && $body['field'] !== null) {
            $validator->string('field', 'رشته', $body['field'])
                ->maxLength('field', 'رشته', $body['field'], 255);
        }

        if (!$validator->passes()) {
            return $this->fail($response, new ApiException($validator->firstError(), 422, 'VALIDATION_ERROR'));
        }

        try {
            return $this->ok($response, $this->service->updateClassroom((int) $args['id'], $body));
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    public function deleteClassroom(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->deleteClassroom((int) $args['id']);

            return $this->ok($response, ['id' => (int) $args['id'], 'deleted' => true]);
        } catch (ApiException $e) {
            return $this->fail($response, $e);
        }
    }

    private function caller(Request $request): object
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            throw new ApiException('دسترسی غیرمجاز', 403, 'FORBIDDEN');
        }

        return $user;
    }
}
