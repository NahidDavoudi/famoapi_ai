<?php

declare(strict_types=1);

namespace App\Modules\ActivityLogs;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ActivityLogController
{
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $result = ActivityLog::list($params);

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result['items'],
            'pagination' => $result['pagination'],
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function stats(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $stats = ActivityLog::stats($params);

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $stats,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
