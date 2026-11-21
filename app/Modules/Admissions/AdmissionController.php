<?php
declare(strict_types=1);

namespace App\Modules\Admissions;

use App\Core\ApiException;
use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

class AdmissionController
{
    public function __construct(private AdmissionService $service)
    {
    }

    /**
     * GET /api/v1/public/admissions
     */
    public function listPublic(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? $params['perPage'] ?? 50);
        $groupByYear = isset($params['group_by']) && strtolower(trim((string) $params['group_by'])) === 'year';

        try {
            $result = $this->service->listPublic($params, $page, $perPage, $groupByYear);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $result['data'], $result['pagination']);
    }

    /**
     * GET /api/v1/admissions (Admin)
     */
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? $params['perPage'] ?? 20);

        try {
            $result = $this->service->list($params, $page, $perPage, $params);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $result['items'], $result['pagination']);
    }

    /**
     * GET /api/v1/admissions/{id} (Admin)
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $item = $this->service->get((int) $args['id']);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $item);
    }

    /**
     * POST /api/v1/admissions (Admin)
     */
    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $image = $this->uploadedImageFromRequest($request);
        if ($image !== null) {
            $data['image'] = $image;
        }

        try {
            $created = $this->service->create($data, $this->actorUserId($request));
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $created, null, 201);
    }

    /**
     * PUT / PATCH / POST /api/v1/admissions/{id} (Admin)
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $image = $this->uploadedImageFromRequest($request);
        if ($image !== null) {
            $data['image'] = $image;
        }

        try {
            $updated = $this->service->update((int) $args['id'], $data, $this->actorUserId($request));
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $updated);
    }

    /**
     * PATCH / PUT /api/v1/admissions/{id}/status (Admin)
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        if (!isset($data['status']) || !is_string($data['status']) || trim($data['status']) === '') {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', 'فیلد وضعیت (status) الزامی است', 422);
        }

        $consent = null;
        if (array_key_exists('consent_confirmed', $data)) {
            $raw = $data['consent_confirmed'];
            if (is_bool($raw)) {
                $consent = $raw;
            } elseif ($raw === 1 || $raw === '1' || $raw === 'true') {
                $consent = true;
            } elseif ($raw === 0 || $raw === '0' || $raw === 'false') {
                $consent = false;
            } else {
                return ResponseHelper::error($response, 'VALIDATION_ERROR', 'مقدار consent_confirmed معتبر نیست', 422);
            }
        }

        try {
            $updated = $this->service->updateStatus(
                (int) $args['id'],
                $data['status'],
                $consent,
                $this->actorUserId($request)
            );
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $updated);
    }

    /**
     * DELETE /api/v1/admissions/{id} (Admin)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->delete((int) $args['id']);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, null);
    }

    private function uploadedImageFromRequest(Request $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        foreach (['image', 'photo', 'avatar'] as $key) {
            if (($files[$key] ?? null) instanceof UploadedFileInterface && $files[$key]->getError() !== UPLOAD_ERR_NO_FILE) {
                return $files[$key];
            }
        }
        return null;
    }

    private function actorUserId(Request $request): ?int
    {
        $user = $request->getAttribute('user');
        if (is_object($user) && isset($user->sub)) {
            return (int) $user->sub;
        }
        return null;
    }
}
