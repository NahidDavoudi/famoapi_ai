<?php

namespace App\Modules\Appointments;

use App\Core\ApiException;
use App\Core\RateLimitedException;
use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AppointmentController
{
    public function __construct(private AppointmentService $service)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 20);

        $result = $this->service->list($page, $perPage);

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result['items'],
            'pagination' => $result['pagination'],
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->required('appointment_date', 'تاریخ قرار ملاقات', $body['appointment_date'] ?? null)
            ->date('appointment_date', 'تاریخ قرار ملاقات', $body['appointment_date'] ?? null)
            ->required('start_time', 'ساعت شروع', $body['start_time'] ?? null)
            ->time('start_time', 'ساعت شروع', $body['start_time'] ?? null)
            ->time('end_time', 'ساعت پایان', $body['end_time'] ?? null)
            ->string('type', 'نوع جلسه', $body['type'] ?? null)
            ->maxLength('type', 'نوع جلسه', $body['type'] ?? null, 100)
            ->string('description', 'توضیحات', $body['description'] ?? null)
            ->maxLength('description', 'توضیحات', $body['description'] ?? null, 1000);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $endTime = isset($body['end_time']) && trim((string) $body['end_time']) !== ''
            ? trim((string) $body['end_time'])
            : null;

        try {
            $result = $this->service->createOrUpdate([
                'student_id'       => (int) $body['student_id'],
                'appointment_date' => (string) $body['appointment_date'],
                'start_time'       => (string) $body['start_time'],
                'end_time'         => $endTime,
                'type'             => (string) ($body['type'] ?? ''),
                'description'      => (string) ($body['description'] ?? ''),
            ]);
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->date('appointment_date', 'تاریخ قرار ملاقات', $body['appointment_date'] ?? null)
            ->time('start_time', 'ساعت شروع', $body['start_time'] ?? null)
            ->time('end_time', 'ساعت پایان', $body['end_time'] ?? null)
            ->string('status', 'وضعیت قرار ملاقات', $body['status'] ?? null)
            ->inArray('status', 'وضعیت قرار ملاقات', $body['status'] ?? null, [
                'pending', 'confirmed', 'completed', 'cancelled', 'canceled', 'absent', 'done', 'scheduled', 'accepted', 'rejected',
            ])
            ->string('type', 'نوع جلسه', $body['type'] ?? null)
            ->maxLength('type', 'نوع جلسه', $body['type'] ?? null, 100)
            ->string('description', 'توضیحات', $body['description'] ?? null)
            ->maxLength('description', 'توضیحات', $body['description'] ?? null, 1000);

        if (array_key_exists('appointment_date', $body)) {
            $validator->required('appointment_date', 'تاریخ قرار ملاقات', $body['appointment_date']);
        }
        if (array_key_exists('start_time', $body)) {
            $validator->required('start_time', 'ساعت شروع', $body['start_time']);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $updateData = [];
        if (array_key_exists('student_id', $body) && $body['student_id'] !== null && $body['student_id'] !== '') {
            $updateData['student_id'] = (int) $body['student_id'];
        }
        if (array_key_exists('appointment_date', $body)) {
            $updateData['appointment_date'] = trim((string) $body['appointment_date']);
        }
        if (array_key_exists('start_time', $body)) {
            $updateData['start_time'] = trim((string) $body['start_time']);
        }
        if (array_key_exists('end_time', $body)) {
            $updateData['end_time'] = ($body['end_time'] !== null && trim((string) $body['end_time']) !== '')
                ? trim((string) $body['end_time'])
                : null;
        }
        if (array_key_exists('status', $body) && $body['status'] !== null && trim((string) $body['status']) !== '') {
            $updateData['status'] = trim((string) $body['status']);
        }
        if (array_key_exists('type', $body)) {
            $updateData['type'] = (string) ($body['type'] ?? '');
        }
        if (array_key_exists('description', $body)) {
            $updateData['description'] = (string) ($body['description'] ?? '');
        }

        try {
            $result = $this->service->update((int) $args['id'], $updateData);
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('status', 'وضعیت قرار ملاقات', $body['status'] ?? null)
            ->string('status', 'وضعیت قرار ملاقات', $body['status'] ?? null)
            ->inArray('status', 'وضعیت قرار ملاقات', $body['status'] ?? null, [
                'pending', 'confirmed', 'completed', 'cancelled', 'canceled', 'absent', 'done', 'scheduled',
            ]);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->updateStatus((int) $args['id'], (string) $body['status']);
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->delete((int) $args['id']);
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => null,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * GET /api/v1/appointments/sms/preview?week_date=YYYY-MM-DD (admin only)
     */
    public function previewSms(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $weekDate = (string) ($params['week_date'] ?? '');

        $validator = new Validator();
        $validator
            ->required('week_date', 'تاریخ هفته', $weekDate !== '' ? $weekDate : null)
            ->date('week_date', 'تاریخ هفته', $weekDate !== '' ? $weekDate : null);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->previewSms($weekDate);
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * POST /api/v1/appointments/sms/send (admin only)
     * Body: { week_date, mode, student_ids?, operation_id? }
     */
    public function sendSms(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $body = $request->getParsedBody() ?? [];

        $weekDate = $body['week_date'] ?? null;
        $mode     = $body['mode'] ?? 'unsent';

        $validator = new Validator();
        $validator
            ->required('week_date', 'تاریخ هفته', $weekDate)
            ->date('week_date', 'تاریخ هفته', $weekDate)
            ->required('mode', 'حالت ارسال', $mode)
            ->string('mode', 'حالت ارسال', $mode)
            ->inArray('mode', 'حالت ارسال', $mode, AppointmentService::VALID_SMS_MODES);

        if (array_key_exists('student_ids', $body) && $body['student_ids'] !== null) {
            $validator->arrayList('student_ids', 'لیست شناسه دانش‌آموزان', $body['student_ids'], true);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $studentIds = isset($body['student_ids']) && is_array($body['student_ids'])
            ? $body['student_ids']
            : null;
        $operationId = isset($body['operation_id']) && is_string($body['operation_id'])
            ? $body['operation_id']
            : (isset($body['idempotency_key']) && is_string($body['idempotency_key']) ? $body['idempotency_key'] : null);

        try {
            $result = $this->service->sendSms(
                (string) $weekDate,
                (string) $mode,
                $studentIds,
                isset($decoded->sub) ? (int) $decoded->sub : null,
                $operationId
            );
        } catch (RateLimitedException $e) {
            $response->getBody()->write(json_encode([
                'success'     => false,
                'data'        => null,
                'pagination'  => null,
                'error'       => ['code' => 'RATE_LIMITED', 'message' => $e->getMessage()],
                'retry_after' => $e->getRetryAfter(),
            ], JSON_UNESCAPED_UNICODE));
            return $response
                ->withStatus(429)
                ->withHeader('Retry-After', (string) $e->getRetryAfter())
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * GET /api/v1/appointments/sms/log?week_date=&student_id=&status=&page=&per_page= (admin only)
     */
    public function smsLog(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $page    = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? $params['perPage'] ?? 50);

        $filters = [
            'week_date'  => $params['week_date'] ?? null,
            'student_id' => $params['student_id'] ?? null,
            'status'     => $params['status'] ?? null,
        ];

        try {
            $result = $this->service->getSmsLogs($filters, $page, $perPage);
        } catch (ApiException $e) {
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

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => ['items' => $result['items']],
            'pagination' => $result['pagination'],
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
