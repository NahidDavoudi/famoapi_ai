<?php
declare(strict_types=1);

namespace App\Modules\Testimonials;

use App\Core\ApiException;
use App\Core\ClientIp;
use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TestimonialController
{
    public function __construct(private TestimonialService $service)
    {
    }

    /**
     * GET /api/v1/public/testimonials
     */
    public function listPublic(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? $params['perPage'] ?? 20);

        try {
            $result = $this->service->listPublic($params, $page, $perPage);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $result['data'], $result['pagination']);
    }

    /**
     * POST /api/v1/public/testimonials
     */
    public function submitPublic(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $clientIp = ClientIp::fromRequest($request) ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        try {
            $created = $this->service->submitPublic($data, $clientIp);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $created, null, 201);
    }

    /**
     * GET /api/v1/testimonials (Admin)
     */
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? $params['perPage'] ?? 20);

        try {
            $result = $this->service->listAdmin($params, $page, $perPage, $params);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $result['items'], $result['pagination']);
    }

    /**
     * GET /api/v1/testimonials/{id} (Admin)
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
     * POST /api/v1/testimonials (Admin direct create, defaults to approved)
     */
    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        try {
            $created = $this->service->createByAdmin($data, $this->actorUserId($request));
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $created, null, 201);
    }

    /**
     * PUT / PATCH /api/v1/testimonials/{id} (Admin)
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        try {
            $updated = $this->service->update((int) $args['id'], $data, $this->actorUserId($request));
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $updated);
    }

    /**
     * PATCH / PUT /api/v1/testimonials/{id}/moderate (Admin)
     */
    public function moderate(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        try {
            $updated = $this->service->moderate((int) $args['id'], $data, $this->actorUserId($request));
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $updated);
    }

    /**
     * POST /api/v1/testimonials/bulk-reject (Admin)
     */
    public function bulkReject(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        try {
            $result = $this->service->bulkReject(
                $data['ids'] ?? null,
                $data['rejection_reason'] ?? null,
                $this->actorUserId($request)
            );
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }

        return ResponseHelper::json($response, $result);
    }

    /**
     * DELETE /api/v1/testimonials/{id} (Admin)
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

    private function actorUserId(Request $request): ?int
    {
        $user = $request->getAttribute('user');
        if (is_object($user) && isset($user->sub)) {
            return (int) $user->sub;
        }
        return null;
    }
}
