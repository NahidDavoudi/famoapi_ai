<?php

namespace App\Modules\Supporters;

use App\Core\PhoneNumber;
use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

class SupporterController
{
    private array $validGrades = [7, 8, 9, 10, 11, 12];
    private array $validFields = ['ریاضی', 'تجربی', 'انسانی', 'زبان', 'هنر', 'عمومی', 'راهنمایی'];

    public function __construct(private SupporterService $service)
    {
    }

    /**
     * @param mixed $scopes
     */
    private function scopeError($scopes): string
    {
        if (!is_array($scopes)) {
            return 'محدوده‌های پشتیبانی باید آرایه باشند';
        }

        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                return 'ساختار محدوده پشتیبانی معتبر نیست';
            }
            $field = trim((string) ($scope['field'] ?? ''));
            if ($field === '') {
                return 'رشته تحصیلی محدوده پشتیبانی معتبر نیست';
            }
            if (!in_array((int) ($scope['grade'] ?? 0), $this->validGrades, true)) {
                return 'پایه تحصیلی محدوده پشتیبانی معتبر نیست';
            }
        }

        return '';
    }

    /**
     * استخراج فایل عکس پروفایل از درخواست multipart (پشتیبانی از نام فیلدهای avatar و image مثل اساتید).
     * Extracts the profile photo file from a multipart request (supports both 'avatar' and 'image' field names).
     *
     * @param Request $request
     * @return UploadedFileInterface|null
     */
    private function avatarFileFromRequest(Request $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        foreach (['avatar', 'image', 'photo', 'profile_image'] as $key) {
            if (!isset($files[$key])) {
                continue;
            }
            $candidate = $files[$key];
            // پشتیبانی از آرایه‌ای از فایل‌ها: اولین مورد استفاده می‌شود
            if (is_array($candidate)) {
                $candidate = $candidate[0] ?? null;
            }
            if ($candidate instanceof UploadedFileInterface && $candidate->getError() !== UPLOAD_ERR_NO_FILE) {
                return $candidate;
            }
        }
        foreach ($files as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate[0] ?? null;
            }
            if ($candidate instanceof UploadedFileInterface && $candidate->getError() !== UPLOAD_ERR_NO_FILE) {
                return $candidate;
            }
        }
        return null;
    }

    private function normalizeAvatarPath(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('#/uploads/(.+)$#i', $trimmed, $m)) {
            return ltrim($m[1], '/');
        }
        return ltrim($trimmed, '/');
    }

    /**
     * نرمال‌سازی فیلد scopes در صورتی که از طریق FormData به صورت رشته JSON ارسال شده باشد.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeBodyPayload(array $body): array
    {
        if (isset($body['scopes']) && is_string($body['scopes'])) {
            $trimmed = trim($body['scopes']);
            if ($trimmed === '') {
                unset($body['scopes']);
            } else {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $body['scopes'] = $decoded;
                }
            }
        }
        if (!array_key_exists('description', $body) && array_key_exists('bio', $body)) {
            $body['description'] = $body['bio'];
        }
        return $body;
    }

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 20);

        $result = $this->service->list($page, $perPage);

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result['items'],
            'pagination' => $result['pagination'],
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function missingPhone(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 20);

        $result = $this->service->missingPhone($page, $perPage);

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result['items'],
            'pagination' => $result['pagination'],
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function create(Request $request, Response $response): Response
    {
        $body = $this->normalizeBodyPayload((array) ($request->getParsedBody() ?? []));

        $hasScopes = isset($body['scopes']) && is_array($body['scopes']) && $body['scopes'] !== [];

        $validator = new Validator();
        $validator->required('name', 'نام و نام خانوادگی', $body['name'] ?? null);
        if (array_key_exists('description', $body) && $body['description'] !== null) {
            $validator->string('description', 'توضیحات', $body['description']);
        }

        if ($hasScopes) {
            $message = $this->scopeError($body['scopes']);
        } else {
            $validator
                ->required('grade', 'پایه تحصیلی', $body['grade'] ?? null)
                ->inArray('grade', 'پایه تحصیلی', isset($body['grade']) && $body['grade'] !== '' ? (int) $body['grade'] : null, $this->validGrades)
                ->required('field', 'رشته تحصیلی', $body['field'] ?? null)
                ->inArray('field', 'رشته تحصیلی', $body['field'] ?? null, $this->validFields);
            $message = $validator->firstError();
        }

        $validator->required('phone', 'شماره تلفن', $body['phone'] ?? null);
        if ($message === '') {
            $message = $validator->firstError();
        }
        if ($message === '' && !PhoneNumber::isValid($body['phone'] ?? '')) {
            $message = 'شماره تلفن پشتیبان معتبر نیست';
        }

        if ($message !== '') {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $data = [
                'name'        => $body['name'],
                'grade'       => isset($body['grade']) && $body['grade'] !== '' ? (int) $body['grade'] : null,
                'field'       => $body['field'] ?? null,
                'phone'       => $body['phone'],
                'chat_id'     => $body['chat_id'] ?? null,
                'description' => $body['description'] ?? null,
                'is_active'   => $body['is_active'] ?? 1,
            ];
            if (array_key_exists('scopes', $body)) {
                $data['scopes'] = $body['scopes'];
            }
            foreach (['avatar', 'image_url', 'avatar_url', 'image', 'photo'] as $imgKey) {
                if (isset($body[$imgKey]) && is_string($body[$imgKey]) && trim($body[$imgKey]) !== '') {
                    $data['avatar'] = $this->normalizeAvatarPath($body[$imgKey]);
                    break;
                }
            }

            $avatarFile = $this->avatarFileFromRequest($request);
            $result = $this->service->create($data, $avatarFile);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'CREATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus() ?: 500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->service->get((int) $args['id']);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus() ?: 404)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $body = $this->normalizeBodyPayload((array) ($request->getParsedBody() ?? []));
        $avatarFile = $this->avatarFileFromRequest($request);

        $validator = new Validator();
        $data = [];
        if (array_key_exists('name', $body)) {
            $validator
                ->required('name', 'نام و نام خانوادگی', $body['name'])
                ->string('name', 'نام و نام خانوادگی', $body['name'])
                ->maxLength('name', 'نام و نام خانوادگی', $body['name'], 150);
            $data['name'] = $body['name'];
        }
        if (array_key_exists('grade', $body) && $body['grade'] !== null && $body['grade'] !== '') {
            $validator->inArray('grade', 'پایه تحصیلی', (int) $body['grade'], $this->validGrades);
            $data['grade'] = (int) $body['grade'];
        }
        if (array_key_exists('field', $body) && $body['field'] !== null && $body['field'] !== '') {
            $validator->inArray('field', 'رشته تحصیلی', $body['field'], $this->validFields);
            $data['field'] = $body['field'];
        }
        if (array_key_exists('description', $body)) {
            if ($body['description'] !== null) {
                $validator->string('description', 'توضیحات', $body['description']);
            }
            $data['description'] = $body['description'];
        }
        if (array_key_exists('phone', $body)) $data['phone'] = $body['phone'];
        if (isset($body['chat_id'])) $data['chat_id'] = $body['chat_id'];
        if (array_key_exists('is_active', $body)) $data['is_active'] = (int) $body['is_active'];
        if ($avatarFile === null) {
            $explicitDeleteAvatar = !empty($body['remove_avatar']) || !empty($body['delete_avatar']);
            if ($explicitDeleteAvatar) {
                $data['avatar'] = null;
            } else {
                foreach (['avatar', 'image_url', 'avatar_url', 'image', 'photo'] as $imgKey) {
                    if (isset($body[$imgKey]) && is_string($body[$imgKey]) && trim($body[$imgKey]) !== '') {
                        $data['avatar'] = $this->normalizeAvatarPath($body[$imgKey]);
                        break;
                    }
                }
            }
        }
        if (isset($body['password']) && $body['password'] !== '') {
            $validator
                ->string('password', 'رمز عبور', $body['password'])
                ->minLength('password', 'رمز عبور', $body['password'], 4);
            $data['password'] = $body['password'];
        }
        if (array_key_exists('scopes', $body)) {
            $scopeError = $this->scopeError($body['scopes']);
            if ($scopeError !== '') {
                $response->getBody()->write(json_encode([
                    'success'    => false,
                    'data'       => null,
                    'pagination' => null,
                    'error'      => [
                        'code'    => 'VALIDATION_ERROR',
                        'message' => $scopeError,
                    ],
                ], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $data['scopes'] = $body['scopes'];
        }

        if (empty($data) && $avatarFile === null) {
            $validator->addError('body', 'حداقل یک فیلد معتبر برای ویرایش پشتیبان الزامی است');
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->update((int) $args['id'], $data, $avatarFile);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'UPDATE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus() ?: 500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function uploadAvatar(Request $request, Response $response, array $args): Response
    {
        $avatarFile = $this->avatarFileFromRequest($request);
        if ($avatarFile === null) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => 'فایل عکس پروفایل (avatar یا image) ارسال نشده است',
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        return $this->update($request, $response, $args);
    }

    public function deleteAvatar(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->service->update((int) $args['id'], ['avatar' => null], null);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'UPDATE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus() ?: 500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->delete((int) $args['id']);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'DELETE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus() ?: 500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => null,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
