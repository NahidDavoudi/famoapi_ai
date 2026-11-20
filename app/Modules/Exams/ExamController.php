<?php

namespace App\Modules\Exams;

use App\Core\StudentScope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ExamController
{
    public function __construct(private ExamService $service) {}

    public function getDates(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);
        $studentId = StudentScope::studentId(
            $request,
            isset($params['student_id']) ? (int) $params['student_id'] : null
        );
        $result = $this->service->getDates($page, $perPage, $studentId);

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => ['dates' => $result['dates']],
            'pagination' => $result['pagination'], 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getStudents(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $examDate = $params['exam_date'] ?? '';
        if (!$examDate) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'تاریخ الزامی است'],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400);
        }
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);
        $result = $this->service->getStudentsByDate($examDate, $page, $perPage, $params);

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => ['students' => $result['students']],
            'pagination' => $result['pagination'], 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getDetails(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $examDate = $params['exam_date'] ?? '';
        $studentId = StudentScope::studentId(
            $request,
            isset($params['student_id']) ? (int) $params['student_id'] : null
        );
        if (!$examDate || !$studentId) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'پارامترها ناقص است'],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400);
        }

        try {
            $result = $this->service->getDetails($examDate, (int) $studentId);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus());
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => $result, 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getAll(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $filters = [
            'student_id' => StudentScope::studentId(
                $request,
                isset($params['student_id']) ? (int) $params['student_id'] : null
            ),
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
        ];
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);
        $result = $this->service->getAll($filters, $page, $perPage, $params);

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => ['exams' => $result['exams']],
            'pagination' => $result['pagination'], 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function save(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->required('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null)
            ->string('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null)
            ->arrayList('subjects', 'لیست دروس', $body['subjects'] ?? null, true);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422);
        }

        $studentId = (int) $body['student_id'];
        $examDate = (string) $body['exam_date'];
        $subjects = $body['subjects'];

        try {
            $result = $this->service->save($studentId, $examDate, $subjects);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus());
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => $result, 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $body = $request->getParsedBody();

        $validator = new \App\Core\Validator();
        $validator->requireBody($body);

        $updatableKeys = ['student_id', 'exam_date', 'subject', 'chapter', 'total_q', 'correct', 'wrong', 'skipped'];
        $payload = [];

        if (is_array($body)) {
            if (array_key_exists('student_id', $body)) {
                $validator
                    ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'])
                    ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id']);
                $payload['student_id'] = $body['student_id'];
            }
            if (array_key_exists('exam_date', $body)) {
                $validator
                    ->required('exam_date', 'تاریخ آزمون', $body['exam_date'])
                    ->date('exam_date', 'تاریخ آزمون', $body['exam_date']);
                $payload['exam_date'] = $body['exam_date'];
            }
            if (array_key_exists('subject', $body)) {
                $validator
                    ->required('subject', 'نام درس', $body['subject'])
                    ->string('subject', 'نام درس', $body['subject'])
                    ->maxLength('subject', 'نام درس', $body['subject'], 50);
                $payload['subject'] = $body['subject'];
            }
            if (array_key_exists('chapter', $body)) {
                if ($body['chapter'] !== null) {
                    $validator
                        ->string('chapter', 'نام مبحث', $body['chapter'])
                        ->maxLength('chapter', 'نام مبحث', $body['chapter'], 100);
                }
                $payload['chapter'] = $body['chapter'];
            }
            if (array_key_exists('total_q', $body)) {
                $validator
                    ->required('total_q', 'تعداد کل سوالات', $body['total_q'])
                    ->integer('total_q', 'تعداد کل سوالات', $body['total_q'], 1);
                $payload['total_q'] = $body['total_q'];
            }
            if (array_key_exists('correct', $body)) {
                $validator
                    ->required('correct', 'تعداد پاسخ‌های صحیح', $body['correct'])
                    ->integer('correct', 'تعداد پاسخ‌های صحیح', $body['correct'], 0);
                $payload['correct'] = $body['correct'];
            }
            if (array_key_exists('wrong', $body)) {
                $validator
                    ->required('wrong', 'تعداد پاسخ‌های غلط', $body['wrong'])
                    ->integer('wrong', 'تعداد پاسخ‌های غلط', $body['wrong'], 0);
                $payload['wrong'] = $body['wrong'];
            }
            if (array_key_exists('skipped', $body)) {
                $validator
                    ->required('skipped', 'تعداد سوالات نزده', $body['skipped'])
                    ->integer('skipped', 'تعداد سوالات نزده', $body['skipped'], 0);
                $payload['skipped'] = $body['skipped'];
            }
            if (array_key_exists('percentage', $body) && $body['percentage'] !== null) {
                $validator->numeric('percentage', 'درصد', $body['percentage']);
                $payload['percentage'] = $body['percentage'];
            }

            $hasUpdatable = false;
            foreach ($updatableKeys as $key) {
                if (array_key_exists($key, $body)) {
                    $hasUpdatable = true;
                    break;
                }
            }
            if (!$hasUpdatable && $validator->passes()) {
                $validator->addError('حداقل یک فیلد معتبر برای ویرایش نتیجه آزمون الزامی است');
            }
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $updated = $this->service->update($id, $payload);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => ['exam' => $updated], 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function upsertComment(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $body = $request->getParsedBody() ?? [];
        $commentBody = $body['body'] ?? $body['comment'] ?? null;

        $validator = new \App\Core\Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->required('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null)
            ->date('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null)
            ->required('body', 'متن نظر', $commentBody)
            ->string('body', 'متن نظر', $commentBody)
            ->maxLength('body', 'متن نظر', $commentBody, 2000);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->upsertComment(
                (int) $body['student_id'],
                (string) $body['exam_date'],
                (int) $decoded->sub,
                (string) $commentBody
            );
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => $result, 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function createShareLink(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->required('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null)
            ->date('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $smsOptions = [
            'send_sms'          => !empty($body['send_sms']),
            'phone'             => $body['phone'] ?? null,
            'parent_contact_id' => $body['parent_contact_id'] ?? null,
            'ip'                => \App\Core\ClientIp::fromRequest($request),
        ];

        try {
            $result = $this->service->createShareLink(
                (int) $body['student_id'],
                (string) $body['exam_date'],
                isset($decoded->sub) ? (int) $decoded->sub : null,
                $smsOptions
            );
        } catch (\App\Core\RateLimitedException $e) {
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
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => $result, 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function sendShareLinkSms(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->required('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null)
            ->date('exam_date', 'تاریخ آزمون', $body['exam_date'] ?? null);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->sendShareLinkSms(
                (int) $body['student_id'],
                (string) $body['exam_date'],
                isset($decoded->sub) ? (int) $decoded->sub : null,
                [
                    'phone'             => $body['phone'] ?? null,
                    'parent_contact_id' => $body['parent_contact_id'] ?? null,
                    'ip'                => \App\Core\ClientIp::fromRequest($request),
                ]
            );
        } catch (\App\Core\RateLimitedException $e) {
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
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => $result, 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function listShareLinks(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $studentId = isset($params['student_id']) && $params['student_id'] !== '' ? (int) $params['student_id'] : null;
        $examDate = isset($params['exam_date']) && $params['exam_date'] !== '' ? (string) $params['exam_date'] : null;

        try {
            $links = $this->service->listShareLinks($studentId, $examDate);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => ['links' => $links], 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function revokeShareLink(Request $request, Response $response, array $args): Response
    {
        $linkId = isset($args['id']) ? (int) $args['id'] : 0;

        try {
            $revoked = $this->service->revokeShareLink($linkId);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => ['link' => $revoked], 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function getPublicSharedResult(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');

        try {
            $result = $this->service->getPublicSharedResult($token);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false, 'data' => null, 'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response
                ->withStatus($e->getHttpStatus())
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                ->withHeader('Referrer-Policy', 'no-referrer');
        }

        $response->getBody()->write(json_encode([
            'success' => true, 'data' => $result, 'pagination' => null, 'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
