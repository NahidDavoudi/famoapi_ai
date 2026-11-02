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
        $result = $this->service->getStudentsByDate($examDate, $page, $perPage);

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
        $result = $this->service->getAll($filters, $page, $perPage);

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
}
