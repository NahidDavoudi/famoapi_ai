<?php

namespace App\Modules\Assignments;

use App\Core\ApiException;
use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Module 2: admin endpoints for canonical student→supporter assignments.
 * Protected by the existing admin JWT + role middleware.
 */
class AssignmentController
{
    public function __construct(private AssignmentService $service)
    {
    }

    public function listStudents(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = [
            'status'           => $query['status'] ?? 'all',
            'search'           => $query['search'] ?? null,
            'field'            => $query['field'] ?? null,
            'grade'            => $query['grade'] ?? null,
            'supporter_id'     => $query['supporter_id'] ?? null,
            'include_inactive' => filter_var($query['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['perPage'] ?? 20);

        $result = $this->service->listStudents($filters, $page, $perPage);

        return ResponseHelper::paginated($response, $result['items'], $result['pagination']);
    }

    public function assign(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->positiveInt('student_id', 'شناسه دانش‌آموز', $body['student_id'] ?? null)
            ->required('supporter_id', 'شناسه پشتیبان', $body['supporter_id'] ?? null)
            ->positiveInt('supporter_id', 'شناسه پشتیبان', $body['supporter_id'] ?? null)
            ->string('note', 'یادداشت', $body['note'] ?? null)
            ->maxLength('note', 'یادداشت', $body['note'] ?? null, 1000);
        $validator->validateOrThrow();

        $studentId = (int) $body['student_id'];
        $supporterId = (int) $body['supporter_id'];
        $note = isset($body['note']) ? (string) $body['note'] : null;

        $result = $this->service->assign($studentId, $supporterId, $this->actorId($request), $note);

        return ResponseHelper::json($response, $result);
    }

    public function unassign(Request $request, Response $response, array $args): Response
    {
        $result = $this->service->unassign((int) $args['studentId'], $this->actorId($request));

        return ResponseHelper::json($response, $result);
    }

    public function supporterStudents(Request $request, Response $response, array $args): Response
    {
        return ResponseHelper::json($response, $this->service->supporterStudents((int) $args['supporterId']));
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        return ResponseHelper::json($response, $this->service->history((int) $args['studentId']));
    }

    public function initialFill(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        if (array_key_exists('dry_run', $body)) {
            $validator->boolean('dry_run', 'اجرای آزمایشی', $body['dry_run']);
        }
        if (array_key_exists('only_unassigned', $body)) {
            $validator->boolean('only_unassigned', 'فقط بدون پشتیبان', $body['only_unassigned']);
        }
        if (array_key_exists('student_ids', $body) && $body['student_ids'] !== null) {
            if (!is_array($body['student_ids'])) {
                $validator->addError('student_ids', 'لیست شناسه دانش‌آموزان باید آرایه باشد');
            }
        }
        $validator->validateOrThrow();

        $dryRun = array_key_exists('dry_run', $body)
            ? filter_var($body['dry_run'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $onlyUnassigned = array_key_exists('only_unassigned', $body)
            ? filter_var($body['only_unassigned'], FILTER_VALIDATE_BOOLEAN)
            : true;

        // Optional scoping: apply only to the given student ids.
        $studentIds = [];
        if (isset($body['student_ids']) && is_array($body['student_ids'])) {
            $studentIds = $body['student_ids'];
        }

        $result = $this->service->initialFill($dryRun, $onlyUnassigned, $this->actorId($request), $studentIds);

        return ResponseHelper::json($response, $result);
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->getAttribute('user');
        $id = $user->sub ?? null;

        return $id !== null ? (int) $id : null;
    }
}
