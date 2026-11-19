<?php

namespace App\Modules\Instructors;

use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InstructorController
{
    public function __construct(private InstructorService $service)
    {
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

    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('name', 'نام استاد', $body['name'] ?? null)
            ->string('name', 'نام استاد', $body['name'] ?? null)
            ->maxLength('name', 'نام استاد', $body['name'] ?? null, 150)
            ->string('title', 'عنوان استاد', $body['title'] ?? null)
            ->maxLength('title', 'عنوان استاد', $body['title'] ?? null, 150)
            ->string('description', 'توضیحات', $body['description'] ?? null)
            ->integer('display_order', 'ترتیب نمایش', $body['display_order'] ?? null, 0);

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

        $files = $request->getUploadedFiles();
        $image = null;
        if (($files['image'] ?? null) instanceof \Psr\Http\Message\UploadedFileInterface) {
            $image = $files['image'];
        }

        try {
            $result = $this->service->create([
                'name'          => $body['name'],
                'title'         => $body['title'] ?? null,
                'description'   => $body['description'] ?? null,
                'image'         => $image,
                'display_order' => isset($body['display_order']) && $body['display_order'] !== '' ? (int) $body['display_order'] : 0,
            ]);
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
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
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
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
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
        $body = $request->getParsedBody() ?? [];
        $files = $request->getUploadedFiles();

        $validator = new Validator();
        $data = [];

        if (array_key_exists('name', $body)) {
            $validator
                ->required('name', 'نام استاد', $body['name'])
                ->string('name', 'نام استاد', $body['name'])
                ->maxLength('name', 'نام استاد', $body['name'], 150);
            $data['name'] = $body['name'];
        }
        if (array_key_exists('title', $body)) {
            $validator->string('title', 'عنوان استاد', $body['title'])->maxLength('title', 'عنوان استاد', $body['title'], 150);
            $data['title'] = $body['title'];
        }
        if (array_key_exists('description', $body)) {
            $validator->string('description', 'توضیحات', $body['description']);
            $data['description'] = $body['description'];
        }
        if (array_key_exists('display_order', $body) && $body['display_order'] !== null && $body['display_order'] !== '') {
            $validator->integer('display_order', 'ترتیب نمایش', $body['display_order'], 0);
            $data['display_order'] = (int) $body['display_order'];
        }
        if (($files['image'] ?? null) instanceof \Psr\Http\Message\UploadedFileInterface) {
            $data['image'] = $files['image'];
        }

        if (empty($data)) {
            $validator->addError('body', 'حداقل یک فیلد معتبر برای ویرایش استاد الزامی است');
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
            $result = $this->service->update((int) $args['id'], $data);
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
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
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
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
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
