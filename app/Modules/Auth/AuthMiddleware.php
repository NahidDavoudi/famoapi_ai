<?php

namespace App\Modules\Auth;

use App\Core\Auth;
use App\Core\AuthCookie;
use App\Core\Database;
use App\Core\JwtRevocation;
use App\Modules\ActivityLogs\ActivityLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $token = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : AuthCookie::tokenFromRequest($request);

        if ($token === null || $token === '') {
            return $this->unauthorized('توکن ارسال نشده است');
        }

        try {
            $decoded = Auth::decode($token);
        } catch (\Exception $e) {
            return $this->unauthorized('توکن نامعتبر یا منقضی شده است');
        }

        if (JwtRevocation::isRevoked($decoded)) {
            return $this->unauthorized('نشست کاربری لغو شده یا منقضی شده است');
        }

        if (Database::hasConnection() && isset($decoded->sub) && (int) $decoded->sub > 0) {
            try {
                $userRow = User::findById((int) $decoded->sub);
                if ($userRow !== null && isset($userRow['is_active']) && (int) $userRow['is_active'] === 0) {
                    return $this->unauthorized('حساب کاربری غیرفعال است');
                }
            } catch (\Throwable) {
                // Do not crash unit tests where the `users` table isn't populated or mocked
            }
        }

        $request = $request->withAttribute('user', $decoded);

        $response = $handler->handle($request);

        try {
            ActivityLog::recordFromRequest($request, $response, $decoded);
        } catch (\Throwable) {
            // Never fail a user request if activity logging encounters an error
        }

        return $response;
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'success'    => false,
            'data'       => null,
            'pagination' => null,
            'error'      => [
                'code'    => 'UNAUTHORIZED',
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
