<?php

namespace App\Modules\Broadcasts;

use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Module 5: supporter broadcast endpoints for the bot host. Service-key
 * protected and acting on behalf of the linked supporter.
 */
class BroadcastController
{
    public function __construct(private BroadcastService $service)
    {
    }

    public function preview(Request $request, Response $response): Response
    {
        $actor = $request->getAttribute('bot_actor');
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('audience', 'جامعه هدف', $body['audience'] ?? null)
            ->string('audience', 'جامعه هدف', $body['audience'] ?? null);

        if (isset($body['day']) && $body['day'] !== '') {
            $validator->date('day', 'تاریخ روز', $body['day']);
        }
        $validator->validateOrThrow();

        return ResponseHelper::json($response, $this->service->preview($actor, $body));
    }

    public function confirm(Request $request, Response $response): Response
    {
        $actor = $request->getAttribute('bot_actor');
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('audience', 'جامعه هدف', $body['audience'] ?? null)
            ->string('audience', 'جامعه هدف', $body['audience'] ?? null);

        if (isset($body['day']) && $body['day'] !== '') {
            $validator->date('day', 'تاریخ روز', $body['day']);
        }
        if (!isset($body['content']) || !is_array($body['content'])) {
            $validator->addError('content', 'محتوای پیام همگانی الزامی است');
        }
        $validator->validateOrThrow();

        return ResponseHelper::json($response, $this->service->confirm($actor, $body), null, 201);
    }

    public function list(Request $request, Response $response): Response
    {
        $actor = $request->getAttribute('bot_actor');
        $query = $request->getQueryParams();
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['perPage'] ?? 20);

        $result = $this->service->list($actor, $page, $perPage);

        return ResponseHelper::paginated($response, $result['items'], $result['pagination']);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $actor = $request->getAttribute('bot_actor');

        return ResponseHelper::json($response, $this->service->get($actor, (int) $args['id']));
    }
}
