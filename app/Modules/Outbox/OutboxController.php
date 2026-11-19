<?php

namespace App\Modules\Outbox;

use App\Core\ApiException;
use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Module 4: outbox pull/report endpoints for the bot host. Service-key
 * protected (the bot worker is a trusted transport, not an acting user).
 */
class OutboxController
{
    public function __construct(private OutboxService $service)
    {
    }

    public function claim(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        if (isset($body['limit']) && $body['limit'] !== '') {
            $validator->positiveInt('limit', 'تعداد آیتم‌ها', $body['limit']);
        }
        if (isset($body['worker_id']) && $body['worker_id'] !== null) {
            $validator->string('worker_id', 'شناسه کارگر', $body['worker_id']);
        }
        $validator->validateOrThrow();

        $limit = (int) ($body['limit'] ?? 10);
        $workerId = isset($body['worker_id']) ? (string) $body['worker_id'] : null;

        return ResponseHelper::json($response, $this->service->claim($limit, $workerId));
    }

    public function report(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('worker_id', 'شناسه کارگر', $body['worker_id'] ?? null)
            ->string('worker_id', 'شناسه کارگر', $body['worker_id'] ?? null);

        if (!array_key_exists('results', $body) || !is_array($body['results'])) {
            $validator->addError('results', 'فهرست نتایج نامعتبر است');
        }
        $validator->validateOrThrow();

        $workerId = trim((string) $body['worker_id']);
        $results = $body['results'];

        return ResponseHelper::json($response, $this->service->report($workerId, $results));
    }
}
