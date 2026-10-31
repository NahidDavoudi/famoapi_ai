<?php

namespace App\Modules\Blog;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BlogController
{
    private BlogService $service;

    public function __construct()
    {
        $this->service = new BlogService();
    }

    public function getPosts(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $validator = new \App\Core\Validator();
        if (isset($params['page']) && $params['page'] !== '') {
            $validator->positiveInt('page', 'شماره صفحه', $params['page']);
        }
        if (isset($params['per_page']) && $params['per_page'] !== '') {
            $validator->intRange('per_page', 'تعداد در هر صفحه', $params['per_page'], 1, 50);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422);
        }

        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);

        $result = $this->service->getPublishedPosts($page, $perPage);

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => ['posts' => $result['posts']],
            'pagination' => $result['pagination'],
            'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getPostsByCategory(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $category = isset($args['category']) ? trim((string) $args['category']) : '';

        $validator = new \App\Core\Validator();
        $validator
            ->required('category', 'دسته‌بندی', $category)
            ->string('category', 'دسته‌بندی', $category)
            ->maxLength('category', 'دسته‌بندی', $category, 100);

        if (isset($params['page']) && $params['page'] !== '') {
            $validator->positiveInt('page', 'شماره صفحه', $params['page']);
        }
        if (isset($params['per_page']) && $params['per_page'] !== '') {
            $validator->intRange('per_page', 'تعداد در هر صفحه', $params['per_page'], 1, 50);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422);
        }

        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);

        $result = $this->service->getPostsByCategory($category, $page, $perPage);

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => ['posts' => $result['posts']],
            'pagination' => $result['pagination'],
            'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getPost(Request $request, Response $response, array $args): Response
    {
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : '';

        $validator = new \App\Core\Validator();
        $validator
            ->required('slug', 'نامک پست', $slug)
            ->string('slug', 'نامک پست', $slug)
            ->maxLength('slug', 'نامک پست', $slug, 255);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422);
        }

        $post = $this->service->getPost($slug);

        if (!$post) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'پست مورد نظر یافت نشد'],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => ['post' => $post],
            'pagination' => null,
            'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getCategories(Request $request, Response $response): Response
    {
        $result = $this->service->getCategories();

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $result['categories'],
            'pagination' => null,
            'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getAllPosts(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);

        $result = $this->service->getAllPosts($page, $perPage);

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => ['posts' => $result['posts']],
            'pagination' => $result['pagination'],
            'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function getPostById(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $post = $this->service->getPostById($id);

        if (!$post) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'پست مورد نظر یافت نشد'],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => ['post' => $post],
            'pagination' => null,
            'error' => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function createPost(Request $request, Response $response): Response
    {
        $data  = $request->getParsedBody() ?? [];
        $files = $request->getUploadedFiles();

        $validator = new \App\Core\Validator();
        $validator
            ->required('title', 'عنوان پست', $data['title'] ?? null)
            ->string('title', 'عنوان پست', $data['title'] ?? null)
            ->maxLength('title', 'عنوان پست', $data['title'] ?? null, 255)
            ->string('slug', 'نامک (Slug)', $data['slug'] ?? null)
            ->string('content', 'محتوای پست', $data['content'] ?? null)
            ->string('excerpt', 'خلاصه پست', $data['excerpt'] ?? null)
            ->string('category', 'دسته‌بندی', $data['category'] ?? null)
            ->string('status', 'وضعیت انتشار', $data['status'] ?? null);

        if (isset($data['status']) && $data['status'] !== '') {
            $validator->inArray('status', 'وضعیت انتشار', $data['status'], ['draft', 'published']);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422);
        }

        if (($files['cover_image_file'] ?? null) instanceof \Psr\Http\Message\UploadedFileInterface) {
            $data['cover_image_file'] = $files['cover_image_file'];
        }

        try {
            $result = $this->service->createPost($data);
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $result,
                'pagination' => null,
                'error' => null,
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(201);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus());
        }
    }

    public function updatePost(Request $request, Response $response, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $data  = $request->getParsedBody() ?? [];
        $files = $request->getUploadedFiles();

        if (($files['cover_image_file'] ?? null) instanceof \Psr\Http\Message\UploadedFileInterface) {
            $data['cover_image_file'] = $files['cover_image_file'];
        }

        $validator = new \App\Core\Validator();
        if (array_key_exists('title', $data)) {
            $validator
                ->required('title', 'عنوان پست', $data['title'])
                ->string('title', 'عنوان پست', $data['title'])
                ->maxLength('title', 'عنوان پست', $data['title'], 255);
        }
        if (array_key_exists('status', $data) && $data['status'] !== null && $data['status'] !== '') {
            $validator->inArray('status', 'وضعیت انتشار', $data['status'], ['draft', 'published']);
        }
        if (empty($data)) {
            $validator->addError('body', 'حداقل یک فیلد برای ویرایش پست الزامی است');
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->firstError()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422);
        }

        try {
            $result = $this->service->updatePost($id, $data);
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $result,
                'pagination' => null,
                'error' => null,
            ], JSON_UNESCAPED_UNICODE));
            return $response;
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus());
        }
    }

    public function deletePost(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        try {
            $this->service->deletePost($id);
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => null,
                'pagination' => null,
                'error' => null,
            ], JSON_UNESCAPED_UNICODE));
            return $response;
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus());
        }
    }
}