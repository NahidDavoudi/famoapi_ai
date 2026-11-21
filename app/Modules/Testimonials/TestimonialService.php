<?php
declare(strict_types=1);

namespace App\Modules\Testimonials;

use App\Core\ApiException;
use App\Core\Cache;
use App\Core\Pagination;
use App\Core\RedisClient;
use App\Core\Sorting;

class TestimonialService
{
    public const VALID_ROLES = ['student', 'parent', 'graduate', 'other'];
    public const VALID_STATUSES = ['pending', 'approved', 'rejected'];
    public const VALID_SOURCES = ['public_form', 'admin'];
    public const MIN_CONTENT_LENGTH = 10;
    public const MAX_CONTENT_LENGTH = 1500;
    public const MAX_BULK_REJECT_IDS = 100;
    public const DEDUP_TTL_SECONDS = 600;

    private const SORT_WHITELIST = [
        'created_at'    => ['expr' => 't.created_at'],
        'rating'        => ['expr' => 't.rating'],
        'display_order' => ['expr' => 't.display_order'],
        'status'        => ['expr' => 't.status'],
        'author_name'   => ['expr' => 't.author_name', 'collate' => 'utf8mb4_persian_ci'],
    ];

    /**
     * Public listing of approved testimonials only.
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<string, mixed>, pagination: array<string, mixed>}
     */
    public function listPublic(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $normalizedFilters = $this->normalizeFilters($filters, true);

        $total = Testimonial::countPublic($normalizedFilters);
        $pagination = Pagination::build($page, $perPage, $total);
        $rows = Testimonial::findPublic($normalizedFilters, $pagination['page'], $pagination['per_page']);
        $summary = Testimonial::publicSummary($normalizedFilters);

        return [
            'data' => [
                'items'   => array_map([$this, 'hydratePublic'], $rows),
                'summary' => $summary,
            ],
            'pagination' => $pagination,
        ];
    }

    /**
     * Public submission from the academy website.
     * - Never stores phone numbers or IP hashes in the database.
     * - Ignores course_id, instructor_id, status, is_featured, and display_order.
     * - Enforces short-TTL Redis deduplication keyed with server-secret HMAC-SHA256.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submitPublic(array $data, ?string $clientIp = null): array
    {
        // Silent honeypot drop for automated spam bots.
        if (isset($data['website']) && is_scalar($data['website']) && trim((string) $data['website']) !== '') {
            return [
                'id'          => null,
                'status'      => 'pending',
                'accepted'    => true,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ];
        }

        $authorName = $this->requireTrimmedString($data['author_name'] ?? null, 'نام و نام خانوادگی', 2, 120);
        $authorRole = $this->parseRole($data['author_role'] ?? 'student');
        $authorMeta = $this->optionalTrimmedString($data['author_meta'] ?? null, 'عنوان یا پایه تحصیلی', 150);
        $rating = $this->requireRating($data['rating'] ?? null);
        $content = $this->requireTrimmedString(
            $data['content'] ?? null,
            'متن نظر',
            self::MIN_CONTENT_LENGTH,
            self::MAX_CONTENT_LENGTH
        );

        $dedupKey = $this->buildDedupRedisKey($clientIp ?? 'unknown', $content);
        if (RedisClient::isAvailable()) {
            if (RedisClient::get($dedupKey) !== null) {
                throw new ApiException(
                    'این نظر اخیراً ثبت شده است؛ لطفاً چند دقیقه دیگر مجدداً تلاش کنید',
                    409,
                    'DUPLICATE_TESTIMONIAL'
                );
            }
        }

        $id = Testimonial::create([
            'author_name'      => $authorName,
            'author_role'      => $authorRole,
            'author_meta'      => $authorMeta,
            'rating'           => $rating,
            'content'          => $content,
            'course_id'        => null,
            'instructor_id'    => null,
            'status'           => 'pending',
            'is_featured'      => 0,
            'display_order'    => 0,
            'source'           => 'public_form',
            'moderated_by'     => null,
            'moderated_at'     => null,
            'rejection_reason' => null,
        ]);

        if (RedisClient::isAvailable()) {
            RedisClient::setex($dedupKey, self::DEDUP_TTL_SECONDS, '1');
        }

        $created = Testimonial::findById($id);
        return [
            'id'          => $id,
            'author_name' => $authorName,
            'author_role' => $authorRole,
            'author_meta' => $authorMeta,
            'rating'      => $rating,
            'content'     => $content,
            'status'      => 'pending',
            'created_at'  => $created['created_at'] ?? gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Admin paginated list with filters and server-side sorting.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $sortParams
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listAdmin(array $filters = [], int $page = 1, int $perPage = 20, array $sortParams = []): array
    {
        $normalizedFilters = $this->normalizeFilters($filters, false);
        $sort = Sorting::resolve($sortParams, self::SORT_WHITELIST, 'created_at', 'DESC', 't.id');

        $total = Testimonial::countAll($normalizedFilters);
        $pagination = Pagination::build($page, $perPage, $total);
        $rows = Testimonial::findAll($normalizedFilters, $pagination['page'], $pagination['per_page'], $sort['order_by']);

        return [
            'items'      => array_map([$this, 'hydrateAdmin'], $rows),
            'pagination' => Sorting::attachToPagination($pagination, $sort),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = Testimonial::findById($id);
        if ($row === null) {
            throw new ApiException('نظر مورد نظر یافت نشد', 404, 'NOT_FOUND');
        }

        return $this->hydrateAdmin($row);
    }

    /**
     * Admin direct creation (defaults to status = 'approved' for migrating legacy testimonials).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createByAdmin(array $data, ?int $actorUserId = null): array
    {
        $authorName = $this->requireTrimmedString($data['author_name'] ?? null, 'نام نویسنده', 2, 120);
        $authorRole = $this->parseRole($data['author_role'] ?? 'student');
        $authorMeta = $this->optionalTrimmedString($data['author_meta'] ?? null, 'عنوان یا پایه تحصیلی', 150);
        $rating = $this->requireRating($data['rating'] ?? 5);
        $content = $this->requireTrimmedString(
            $data['content'] ?? null,
            'متن نظر',
            self::MIN_CONTENT_LENGTH,
            self::MAX_CONTENT_LENGTH
        );
        $courseId = $this->resolveOptionalCourseId($data['course_id'] ?? null);
        $instructorId = $this->resolveOptionalInstructorId($data['instructor_id'] ?? null);
        $status = $this->parseStatus($data['status'] ?? 'approved');
        $isFeatured = $this->parseBooleanFlag($data['is_featured'] ?? false, 'نمایش ویژه');
        $displayOrder = $this->parseNonNegativeInt($data['display_order'] ?? 0, 'ترتیب نمایش');
        $rejectionReason = $status === 'rejected'
            ? $this->optionalTrimmedString($data['rejection_reason'] ?? null, 'دلیل رد', 300)
            : null;

        $isModerated = ($status !== 'pending');
        $id = Testimonial::create([
            'author_name'      => $authorName,
            'author_role'      => $authorRole,
            'author_meta'      => $authorMeta,
            'rating'           => $rating,
            'content'          => $content,
            'course_id'        => $courseId,
            'instructor_id'    => $instructorId,
            'status'           => $status,
            'is_featured'      => $isFeatured,
            'display_order'    => $displayOrder,
            'source'           => 'admin',
            'moderated_by'     => $isModerated ? $actorUserId : null,
            'moderated_at'     => $isModerated ? gmdate('Y-m-d H:i:s') : null,
            'rejection_reason' => $rejectionReason,
        ]);

        Cache::flushGroup('public');

        return $this->get($id);
    }

    /**
     * Admin update (can edit fields, link/unlink course_id or instructor_id, feature/order, or change status).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data, ?int $actorUserId = null): array
    {
        $existing = Testimonial::findById($id);
        if ($existing === null) {
            throw new ApiException('نظر مورد نظر یافت نشد', 404, 'NOT_FOUND');
        }

        $update = [];

        if (array_key_exists('author_name', $data)) {
            $update['author_name'] = $this->requireTrimmedString($data['author_name'], 'نام نویسنده', 2, 120);
        }
        if (array_key_exists('author_role', $data)) {
            $update['author_role'] = $this->parseRole($data['author_role']);
        }
        if (array_key_exists('author_meta', $data)) {
            $update['author_meta'] = $this->optionalTrimmedString($data['author_meta'], 'عنوان یا پایه تحصیلی', 150);
        }
        if (array_key_exists('rating', $data)) {
            $update['rating'] = $this->requireRating($data['rating']);
        }
        if (array_key_exists('content', $data)) {
            $update['content'] = $this->requireTrimmedString(
                $data['content'],
                'متن نظر',
                self::MIN_CONTENT_LENGTH,
                self::MAX_CONTENT_LENGTH
            );
        }
        if (array_key_exists('course_id', $data)) {
            $update['course_id'] = $this->resolveOptionalCourseId($data['course_id']);
        }
        if (array_key_exists('instructor_id', $data)) {
            $update['instructor_id'] = $this->resolveOptionalInstructorId($data['instructor_id']);
        }
        if (array_key_exists('is_featured', $data)) {
            $update['is_featured'] = $this->parseBooleanFlag($data['is_featured'], 'نمایش ویژه');
        }
        if (array_key_exists('display_order', $data)) {
            $update['display_order'] = $this->parseNonNegativeInt($data['display_order'], 'ترتیب نمایش');
        }

        $effectiveStatus = (string) ($existing['status'] ?? 'pending');
        if (array_key_exists('status', $data)) {
            $effectiveStatus = $this->parseStatus($data['status']);
            $update['status'] = $effectiveStatus;
            $update['moderated_by'] = $effectiveStatus === 'pending' ? null : $actorUserId;
            $update['moderated_at'] = $effectiveStatus === 'pending' ? null : gmdate('Y-m-d H:i:s');

            if ($effectiveStatus !== 'rejected') {
                $update['rejection_reason'] = null;
            }
        }

        if (array_key_exists('rejection_reason', $data)) {
            $update['rejection_reason'] = $effectiveStatus === 'rejected'
                ? $this->optionalTrimmedString($data['rejection_reason'], 'دلیل رد', 300)
                : null;
        }

        if ($update === []) {
            throw new ApiException('حداقل یک فیلد معتبر برای ویرایش نظر الزامی است', 422, 'VALIDATION_ERROR');
        }

        Testimonial::update($id, $update);
        Cache::flushGroup('public');

        return $this->get($id);
    }

    /**
     * Dedicated moderation endpoint (approve / reject / revert to pending).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function moderate(int $id, array $data, ?int $actorUserId = null): array
    {
        if (!isset($data['status']) || !is_string($data['status']) || trim($data['status']) === '') {
            throw new ApiException('وضعیت بررسی نظر (status) الزامی است', 422, 'VALIDATION_ERROR');
        }

        $allowed = ['status', 'rejection_reason', 'is_featured', 'display_order', 'course_id', 'instructor_id'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        return $this->update($id, $filtered, $actorUserId);
    }

    /**
     * Bulk reject up to 100 testimonials in one parameterized query.
     *
     * @param mixed $rawIds
     * @return array{rejected_count: int, requested_ids: list<int>, rejection_reason: ?string}
     */
    public function bulkReject(mixed $rawIds, mixed $rawReason = null, ?int $actorUserId = null): array
    {
        if (!is_array($rawIds) || $rawIds === [] || !array_is_list($rawIds)) {
            throw new ApiException('آرایه شناسه‌ها (ids) الزامی و غیرخالی است', 422, 'VALIDATION_ERROR');
        }

        if (count($rawIds) > self::MAX_BULK_REJECT_IDS) {
            throw new ApiException(
                'حداکثر ' . self::MAX_BULK_REJECT_IDS . ' نظر در هر درخواست رد دسته‌جمعی مجاز است',
                422,
                'VALIDATION_ERROR'
            );
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            if (is_bool($rawId) || !is_scalar($rawId) || filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
                throw new ApiException('تمام شناسه‌های آرایه ids باید عدد صحیح مثبت باشند', 422, 'VALIDATION_ERROR');
            }
            $ids[(int) $rawId] = (int) $rawId;
        }
        $uniqueIds = array_values($ids);

        $reason = $this->optionalTrimmedString($rawReason, 'دلیل رد', 300);

        $rejectedCount = Testimonial::bulkReject($uniqueIds, $reason, $actorUserId);
        Cache::flushGroup('public');

        return [
            'rejected_count'   => $rejectedCount,
            'requested_ids'    => $uniqueIds,
            'rejection_reason' => $reason,
        ];
    }

    public function delete(int $id): void
    {
        $existing = Testimonial::findById($id);
        if ($existing === null) {
            throw new ApiException('نظر مورد نظر یافت نشد', 404, 'NOT_FOUND');
        }

        Testimonial::delete($id);
        Cache::flushGroup('public');
    }

    /**
     * Compute Redis dedup key using server-keyed HMAC-SHA256 (never plain SHA-256).
     */
    public static function buildDedupRedisKey(string $clientIp, string $content): string
    {
        $normalizedIp = strtolower(trim($clientIp));
        $normalizedContent = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($content)));
        $mac = hash_hmac('sha256', $normalizedIp . '|' . $normalizedContent, self::dedupSigningKey());

        return 'testimonial:dedup:' . $mac;
    }

    private static function dedupSigningKey(): string
    {
        $secret = trim((string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: ''));
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is required for testimonial dedup HMAC key', 500);
        }

        return hash_hmac('sha256', 'famo:testimonial:dedup:v1', $secret);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateAdmin(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'author_name'      => (string) $row['author_name'],
            'author_role'      => (string) ($row['author_role'] ?? 'student'),
            'author_meta'      => $row['author_meta'] !== null ? (string) $row['author_meta'] : null,
            'rating'           => (int) ($row['rating'] ?? 5),
            'content'          => (string) $row['content'],
            'course_id'        => isset($row['course_id']) && $row['course_id'] !== null ? (int) $row['course_id'] : null,
            'course_name'      => $row['course_name'] !== null ? (string) $row['course_name'] : null,
            'instructor_id'    => isset($row['instructor_id']) && $row['instructor_id'] !== null ? (int) $row['instructor_id'] : null,
            'instructor_name'  => $row['instructor_name'] !== null ? (string) $row['instructor_name'] : null,
            'status'           => (string) ($row['status'] ?? 'pending'),
            'is_featured'      => (bool) ((int) ($row['is_featured'] ?? 0)),
            'display_order'    => (int) ($row['display_order'] ?? 0),
            'source'           => (string) ($row['source'] ?? 'public_form'),
            'moderated_by'     => isset($row['moderated_by']) && $row['moderated_by'] !== null ? (int) $row['moderated_by'] : null,
            'moderated_at'     => $row['moderated_at'] ?? null,
            'rejection_reason' => $row['rejection_reason'] !== null ? (string) $row['rejection_reason'] : null,
            'created_at'       => $row['created_at'] ?? null,
            'updated_at'       => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePublic(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'author_name'     => (string) $row['author_name'],
            'author_role'     => (string) ($row['author_role'] ?? 'student'),
            'author_meta'     => $row['author_meta'] !== null ? (string) $row['author_meta'] : null,
            'rating'          => (int) ($row['rating'] ?? 5),
            'content'         => (string) $row['content'],
            'course_id'       => isset($row['course_id']) && $row['course_id'] !== null ? (int) $row['course_id'] : null,
            'course_name'     => $row['course_name'] !== null ? (string) $row['course_name'] : null,
            'instructor_id'   => isset($row['instructor_id']) && $row['instructor_id'] !== null ? (int) $row['instructor_id'] : null,
            'instructor_name' => $row['instructor_name'] !== null ? (string) $row['instructor_name'] : null,
            'is_featured'     => (bool) ((int) ($row['is_featured'] ?? 0)),
            'display_order'   => (int) ($row['display_order'] ?? 0),
            'created_at'      => $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters, bool $publicOnly): array
    {
        $out = [];

        $roleRaw = $filters['role'] ?? $filters['author_role'] ?? null;
        if ($roleRaw !== null && trim((string) $roleRaw) !== '') {
            $out['author_role'] = $this->parseRole($roleRaw);
        }

        if (array_key_exists('featured', $filters) && $filters['featured'] !== '' && $filters['featured'] !== null) {
            $out['is_featured'] = $this->parseBooleanFlag($filters['featured'], 'نمایش ویژه');
        } elseif (array_key_exists('is_featured', $filters) && $filters['is_featured'] !== '' && $filters['is_featured'] !== null) {
            $out['is_featured'] = $this->parseBooleanFlag($filters['is_featured'], 'نمایش ویژه');
        }

        if (isset($filters['course_id']) && $filters['course_id'] !== '' && $filters['course_id'] !== null) {
            $out['course_id'] = $this->parsePositiveInt($filters['course_id'], 'شناسه دوره');
        }

        if (isset($filters['instructor_id']) && $filters['instructor_id'] !== '' && $filters['instructor_id'] !== null) {
            $out['instructor_id'] = $this->parsePositiveInt($filters['instructor_id'], 'شناسه استاد');
        }

        if (!$publicOnly) {
            if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
                $out['status'] = $this->parseStatus($filters['status']);
            }
            if (isset($filters['source']) && trim((string) $filters['source']) !== '') {
                $source = strtolower(trim((string) $filters['source']));
                if (!in_array($source, self::VALID_SOURCES, true)) {
                    throw new ApiException('منبع ثبت نظر نامعتبر است', 422, 'VALIDATION_ERROR');
                }
                $out['source'] = $source;
            }
            if (isset($filters['rating']) && $filters['rating'] !== '' && $filters['rating'] !== null) {
                $out['rating'] = $this->requireRating($filters['rating']);
            }
            if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
                $out['q'] = trim($filters['q']);
            }
        }

        return $out;
    }

    private function resolveOptionalCourseId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $courseId = $this->parsePositiveInt($value, 'شناسه دوره');
        if (!Testimonial::courseExists($courseId)) {
            throw new ApiException('دوره انتخاب‌شده یافت نشد', 422, 'VALIDATION_ERROR');
        }
        return $courseId;
    }

    private function resolveOptionalInstructorId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $instructorId = $this->parsePositiveInt($value, 'شناسه استاد');
        if (!Testimonial::instructorExists($instructorId)) {
            throw new ApiException('استاد انتخاب‌شده یافت نشد', 422, 'VALIDATION_ERROR');
        }
        return $instructorId;
    }

    private function requireTrimmedString(mixed $value, string $label, int $minLength, int $maxLength): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new ApiException("{$label} الزامی است", 422, 'VALIDATION_ERROR');
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            throw new ApiException("{$label} الزامی است", 422, 'VALIDATION_ERROR');
        }
        $len = mb_strlen($trimmed);
        if ($len < $minLength) {
            throw new ApiException("{$label} باید حداقل {$minLength} کاراکتر باشد", 422, 'VALIDATION_ERROR');
        }
        if ($len > $maxLength) {
            throw new ApiException("{$label} نمی‌تواند بیشتر از {$maxLength} کاراکتر باشد", 422, 'VALIDATION_ERROR');
        }
        return $trimmed;
    }

    private function optionalTrimmedString(mixed $value, string $label, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value) || is_bool($value)) {
            throw new ApiException("{$label} باید رشته متنی باشد", 422, 'VALIDATION_ERROR');
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw new ApiException("{$label} نمی‌تواند بیشتر از {$maxLength} کاراکتر باشد", 422, 'VALIDATION_ERROR');
        }
        return $trimmed;
    }

    private function requireRating(mixed $value): int
    {
        if ($value === null || $value === '' || is_bool($value) || !is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('امتیاز باید عدد صحیح بین 1 تا 5 باشد', 422, 'VALIDATION_ERROR');
        }
        $rating = (int) $value;
        if ($rating < 1 || $rating > 5) {
            throw new ApiException('امتیاز باید بین 1 تا 5 باشد', 422, 'VALIDATION_ERROR');
        }
        return $rating;
    }

    private function parseRole(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'student';
        }
        $role = is_string($value) ? strtolower(trim($value)) : '';
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new ApiException('نقش نویسنده نظر نامعتبر است', 422, 'VALIDATION_ERROR');
        }
        return $role;
    }

    private function parseStatus(mixed $value): string
    {
        $status = is_string($value) ? strtolower(trim($value)) : '';
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new ApiException('وضعیت نظر باید pending، approved یا rejected باشد', 422, 'VALIDATION_ERROR');
        }
        return $status;
    }

    private function parsePositiveInt(mixed $value, string $label): int
    {
        if (is_bool($value) || !is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new ApiException("{$label} باید یک عدد صحیح مثبت باشد", 422, 'VALIDATION_ERROR');
        }
        return (int) $value;
    }

    private function parseNonNegativeInt(mixed $value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_bool($value) || !is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new ApiException("{$label} باید عدد صحیح نامنفی باشد", 422, 'VALIDATION_ERROR');
        }
        return (int) $value;
    }

    private function parseBooleanFlag(mixed $value, string $label): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return 1;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === null || $value === '') {
            return 0;
        }
        throw new ApiException("{$label} باید مقدار بولی معتبر باشد", 422, 'VALIDATION_ERROR');
    }
}
