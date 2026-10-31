<?php

namespace App\Modules\Appointments;

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
            ->required('end_time', 'ساعت پایان', $body['end_time'] ?? null)
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

        try {
            $result = $this->service->createOrUpdate([
                'student_id'       => (int) $body['student_id'],
                'appointment_date' => (string) $body['appointment_date'],
                'start_time'       => (string) $body['start_time'],
                'end_time'         => (string) $body['end_time'],
                'type'             => (string) ($body['type'] ?? ''),
                'description'      => (string) ($body['description'] ?? ''),
            ]);
        } catch (\App\Core\ApiException $e) {
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
        } catch (\App\Core\ApiException $e) {
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
        } catch (\App\Core\ApiException $e) {
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
}
