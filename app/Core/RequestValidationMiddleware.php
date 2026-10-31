<?php
namespace App\Core;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Validates mandatory server environment variables and incoming API request bodies
 * before requests reach route controllers.
 */
class RequestValidationMiddleware implements MiddlewareInterface
{
    /**
     * Write routes where an empty JSON/request body is legitimately allowed.
     */
    private const OPTIONAL_BODY_ROUTE_PATTERNS = [
        '#^/api/v1/auth/logout$#',
        '#^/api/v1/students/\d+/(create-account|reset-password|toggle-status)$#',
        '#^/api/v1/assignments/initial-fill$#',
        '#^/api/v1/bot/outbox/claim$#',
        '#^/api/v1/bot/threads/mark-read$#',
        '#^/api/v1/tutoring/sessions/\d+/end$#',
    ];

    public function process(Request $request, RequestHandler $handler): Response
    {
        // 1. Validate mandatory environment variables & formats for this request
        EnvValidator::validateForRequest($request);

        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $handler->handle($request);
        }

        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        $isJsonRequest = str_contains($contentType, 'application/json');
        $parsedBody = $request->getParsedBody();

        // 2. If Content-Type is application/json and parsedBody was not populated yet,
        // inspect the raw body stream for malformed JSON or non-object payloads.
        if ($isJsonRequest && $parsedBody === null) {
            $rawBody = trim((string) $request->getBody());
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new ApiException(
                        'فرمت JSON بدنه درخواست نامعتبر است',
                        422,
                        'VALIDATION_ERROR',
                        ['body' => ['فرمت JSON بدنه درخواست نامعتبر است']]
                    );
                }
                if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                    throw new ApiException(
                        'بدنه درخواست باید یک شیء JSON معتبر باشد',
                        422,
                        'VALIDATION_ERROR',
                        ['body' => ['بدنه درخواست باید یک شیء JSON معتبر باشد']]
                    );
                }
                $parsedBody = $decoded;
                $request = $request->withParsedBody($parsedBody);
            }
        }

        // 3. Reject non-array parsed bodies or sequential list arrays
        if ($parsedBody !== null && !is_array($parsedBody)) {
            throw new ApiException(
                'بدنه درخواست باید یک شیء معتبر باشد',
                422,
                'VALIDATION_ERROR',
                ['body' => ['بدنه درخواست باید یک شیء معتبر باشد']]
            );
        }

        if (is_array($parsedBody) && $parsedBody !== [] && array_is_list($parsedBody)) {
            throw new ApiException(
                'بدنه درخواست باید یک شیء کلید-مقدار معتبر باشد',
                422,
                'VALIDATION_ERROR',
                ['body' => ['بدنه درخواست باید یک شیء کلید-مقدار معتبر باشد']]
            );
        }

        // Normalize null parsedBody to empty array for downstream controllers
        if ($parsedBody === null) {
            $parsedBody = [];
            $request = $request->withParsedBody($parsedBody);
        }

        // 4. Enforce non-empty body on write endpoints that require input data
        $uploadedFiles = $request->getUploadedFiles();
        if ($parsedBody === [] && empty($uploadedFiles) && !$this->isOptionalBodyRoute($request->getUri()->getPath())) {
            throw new ApiException(
                'بدنه درخواست الزامی است و نمی‌تواند خالی باشد',
                422,
                'VALIDATION_ERROR',
                ['body' => ['بدنه درخواست الزامی است و نمی‌تواند خالی باشد']]
            );
        }

        return $handler->handle($request);
    }

    private function isOptionalBodyRoute(string $path): bool
    {
        foreach (self::OPTIONAL_BODY_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
