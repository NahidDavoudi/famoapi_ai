<?php

declare(strict_types=1);

namespace App\Modules\Curriculum;

use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Endpoints for browsing and smart keyboard autocomplete over `curriculum_nodes`.
 */
final class CurriculumController
{
    private ?CurriculumRepository $repo;

    public function __construct(?CurriculumRepository $repo = null)
    {
        $this->repo = $repo;
    }

    /**
     * GET /api/v1/curriculum/autocomplete
     * Query params: q, student_id, subject_id, parent_id, node_types (comma-separated), limit
     */
    public function autocomplete(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $nodeTypes = [];
        if (isset($q['node_types']) && is_string($q['node_types']) && trim($q['node_types']) !== '') {
            $nodeTypes = array_values(array_filter(
                array_map('trim', explode(',', $q['node_types'])),
                static fn (string $v): bool => $v !== ''
            ));
        }

        $items = $this->repository()->autocomplete([
            'q'          => isset($q['q']) ? (string) $q['q'] : '',
            'student_id' => isset($q['student_id']) && ctype_digit((string) $q['student_id']) ? (int) $q['student_id'] : null,
            'subject_id' => isset($q['subject_id']) && ctype_digit((string) $q['subject_id']) ? (int) $q['subject_id'] : null,
            'parent_id'  => isset($q['parent_id']) && ctype_digit((string) $q['parent_id']) ? (int) $q['parent_id'] : null,
            'node_types' => $nodeTypes,
            'limit'      => isset($q['limit']) && ctype_digit((string) $q['limit']) ? (int) $q['limit'] : 25,
        ]);

        return ResponseHelper::json($response, ['items' => $items]);
    }

    /**
     * GET /api/v1/curriculum/nodes
     * Browse children of a parent node (`parent_id=0` returns top-level segments).
     */
    public function listNodes(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $parentId = isset($q['parent_id']) && ctype_digit((string) $q['parent_id']) ? (int) $q['parent_id'] : 0;
        $limit = isset($q['limit']) && ctype_digit((string) $q['limit']) ? (int) $q['limit'] : 50;

        $items = $this->repository()->autocomplete([
            'parent_id'  => $parentId,
            'student_id' => isset($q['student_id']) && ctype_digit((string) $q['student_id']) ? (int) $q['student_id'] : null,
            'limit'      => $limit,
        ]);

        return ResponseHelper::json($response, ['nodes' => $items]);
    }

    /**
     * GET /api/v1/curriculum/nodes/{id}/path
     */
    public function getNodePath(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', 'شناسه نود مبحث نامعتبر است.', 422);
        }

        $path = $this->repository()->getPath($id);
        if ($path === []) {
            return ResponseHelper::error($response, 'NOT_FOUND', 'نود مبحث یافت نشد.', 404);
        }

        return ResponseHelper::json($response, ['path' => $path]);
    }

    private function repository(): CurriculumRepository
    {
        return $this->repo ?? new CurriculumRepository();
    }
}
