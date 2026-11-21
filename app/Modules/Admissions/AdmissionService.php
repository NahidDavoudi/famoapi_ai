<?php
declare(strict_types=1);

namespace App\Modules\Admissions;

use App\Core\ApiException;
use App\Core\AssetUrl;
use App\Core\Cache;
use App\Core\Pagination;
use App\Core\Sorting;
use App\Core\Storage;
use Psr\Http\Message\UploadedFileInterface;

class AdmissionService
{
    public const VALID_STATUSES = ['draft', 'published'];
    public const VALID_EXAM_TYPES = ['konkur', 'tizhooshan', 'nemoone', 'olympiad', 'other'];
    public const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'jfif', 'png', 'webp'];
    public const MIN_JALALI_YEAR = 1380;
    public const MAX_JALALI_YEAR = 1450;

    private const SORT_WHITELIST = [
        'jalali_year' => [
            'expr'      => 'jalali_year',
            'secondary' => [
                ['expr' => 'display_order', 'dir' => 'ASC'],
            ],
        ],
        'student_name'  => ['expr' => 'student_name', 'collate' => 'utf8mb4_persian_ci'],
        'display_order' => ['expr' => 'display_order'],
        'status'        => ['expr' => 'status'],
        'created_at'    => ['expr' => 'created_at'],
        'published_at'  => ['expr' => 'published_at'],
    ];

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $sortParams
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20, array $sortParams = []): array
    {
        $normalizedFilters = $this->normalizeListFilters($filters, false);
        $sort = Sorting::resolve($sortParams, self::SORT_WHITELIST, 'jalali_year', 'DESC', 'id');

        $total = Admission::countAll($normalizedFilters);
        $pagination = Pagination::build($page, $perPage, $total);
        $items = Admission::findAll($normalizedFilters, $pagination['page'], $pagination['per_page'], $sort['order_by']);

        return [
            'items'      => array_map([$this, 'hydrateAdmin'], $items),
            'pagination' => Sorting::attachToPagination($pagination, $sort),
        ];
    }

    /**
     * Public listing of published + consent-confirmed admissions, optionally grouped by Jalali year.
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<string, mixed>, pagination: ?array<string, mixed>}
     */
    public function listPublic(
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
        bool $groupByYear = false
    ): array {
        $normalizedFilters = $this->normalizeListFilters($filters, true);
        $years = Admission::findPublishedYears();

        if ($groupByYear) {
            $rows = Admission::findPublic($normalizedFilters, null, null);
            $byYear = [];
            foreach ($rows as $row) {
                $hydrated = $this->hydratePublic($row);
                $year = (int) $hydrated['jalali_year'];
                if (!isset($byYear[$year])) {
                    $byYear[$year] = [
                        'jalali_year' => $year,
                        'count'       => 0,
                        'items'       => [],
                    ];
                }
                $byYear[$year]['items'][] = $hydrated;
                $byYear[$year]['count']++;
            }

            krsort($byYear, SORT_NUMERIC);

            return [
                'data' => [
                    'years'  => $years,
                    'groups' => array_values($byYear),
                ],
                'pagination' => null,
            ];
        }

        $total = Admission::countPublic($normalizedFilters);
        $pagination = Pagination::build($page, $perPage, $total);
        $rows = Admission::findPublic($normalizedFilters, $pagination['page'], $pagination['per_page']);

        return [
            'data' => [
                'years' => $years,
                'items' => array_map([$this, 'hydratePublic'], $rows),
            ],
            'pagination' => $pagination,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = Admission::findById($id);
        if ($row === null) {
            throw new ApiException('رکورد قبولی یافت نشد', 404, 'NOT_FOUND');
        }

        return $this->hydrateAdmin($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, ?int $actorUserId = null): array
    {
        $studentName = $this->requireTrimmedString($data['student_name'] ?? null, 'نام شاگرد', 150);
        $jalaliYear = $this->requireJalaliYear($data['jalali_year'] ?? null);
        $resultTitle = $this->requireTrimmedString($data['result_title'] ?? null, 'عنوان قبولی (رشته/دانشگاه)', 255);

        $examType = $this->parseExamType($data['exam_type'] ?? 'konkur');
        $field = $this->optionalTrimmedString($data['field'] ?? null, 'رشته تحصیلی', 60);
        $rankValue = $this->optionalTrimmedString($data['rank_value'] ?? null, 'رتبه', 50);
        $quote = $this->optionalTrimmedString($data['quote'] ?? null, 'توضیح یا نقل‌قول', 500);
        $displayOrder = $this->parseNonNegativeInt($data['display_order'] ?? 0, 'ترتیب نمایش');
        $isFeatured = $this->parseBooleanFlag($data['is_featured'] ?? false, 'نمایش ویژه');
        $consentConfirmed = $this->parseBooleanFlag($data['consent_confirmed'] ?? false, 'تأیید رضایت شاگرد');
        $status = $this->parseStatus($data['status'] ?? 'draft');

        if ($status === 'published' && $consentConfirmed !== 1) {
            throw new ApiException(
                'برای انتشار رکورد قبولی، تأیید رضایت شاگرد (consent_confirmed) الزامی است',
                422,
                'CONSENT_REQUIRED'
            );
        }

        $uploadedPath = null;
        if (isset($data['image']) && $data['image'] !== null) {
            $uploadedPath = $this->uploadImage($data['image']);
        }

        $now = gmdate('Y-m-d H:i:s');
        $payload = [
            'student_name'         => $studentName,
            'jalali_year'          => $jalaliYear,
            'exam_type'            => $examType,
            'field'                => $field,
            'result_title'         => $resultTitle,
            'rank_value'           => $rankValue,
            'quote'                => $quote,
            'image_url'            => $uploadedPath,
            'display_order'        => $displayOrder,
            'is_featured'          => $isFeatured,
            'consent_confirmed'    => $consentConfirmed,
            'consent_confirmed_at' => $consentConfirmed === 1 ? $now : null,
            'consent_confirmed_by' => $consentConfirmed === 1 ? $actorUserId : null,
            'status'               => $status,
            'published_at'         => $status === 'published' ? $now : null,
            'created_by'           => $actorUserId,
            'updated_by'           => $actorUserId,
        ];

        try {
            $id = Admission::create($payload);
        } catch (\Throwable $e) {
            if ($uploadedPath !== null) {
                Storage::delete($uploadedPath);
            }
            throw $e;
        }

        Cache::flushGroup('public');

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data, ?int $actorUserId = null): array
    {
        $existing = Admission::findById($id);
        if ($existing === null) {
            throw new ApiException('رکورد قبولی یافت نشد', 404, 'NOT_FOUND');
        }

        $update = [];

        if (array_key_exists('student_name', $data)) {
            $update['student_name'] = $this->requireTrimmedString($data['student_name'], 'نام شاگرد', 150);
        }
        if (array_key_exists('jalali_year', $data)) {
            $update['jalali_year'] = $this->requireJalaliYear($data['jalali_year']);
        }
        if (array_key_exists('exam_type', $data)) {
            $update['exam_type'] = $this->parseExamType($data['exam_type']);
        }
        if (array_key_exists('field', $data)) {
            $update['field'] = $this->optionalTrimmedString($data['field'], 'رشته تحصیلی', 60);
        }
        if (array_key_exists('result_title', $data)) {
            $update['result_title'] = $this->requireTrimmedString($data['result_title'], 'عنوان قبولی (رشته/دانشگاه)', 255);
        }
        if (array_key_exists('rank_value', $data)) {
            $update['rank_value'] = $this->optionalTrimmedString($data['rank_value'], 'رتبه', 50);
        }
        if (array_key_exists('quote', $data)) {
            $update['quote'] = $this->optionalTrimmedString($data['quote'], 'توضیح یا نقل‌قول', 500);
        }
        if (array_key_exists('display_order', $data)) {
            $update['display_order'] = $this->parseNonNegativeInt($data['display_order'], 'ترتیب نمایش');
        }
        if (array_key_exists('is_featured', $data)) {
            $update['is_featured'] = $this->parseBooleanFlag($data['is_featured'], 'نمایش ویژه');
        }

        $currentConsent = (int) ($existing['consent_confirmed'] ?? 0);
        $effectiveConsent = $currentConsent;
        $consentChanged = false;
        if (array_key_exists('consent_confirmed', $data)) {
            $effectiveConsent = $this->parseBooleanFlag($data['consent_confirmed'], 'تأیید رضایت شاگرد');
            $update['consent_confirmed'] = $effectiveConsent;
            $consentChanged = ($effectiveConsent !== $currentConsent);
        }

        $currentStatus = (string) ($existing['status'] ?? 'draft');
        $effectiveStatus = $currentStatus;
        if (array_key_exists('status', $data)) {
            $effectiveStatus = $this->parseStatus($data['status']);
            $update['status'] = $effectiveStatus;
        }

        if ($effectiveStatus === 'published' && $effectiveConsent !== 1) {
            throw new ApiException(
                'برای انتشار رکورد قبولی، تأیید رضایت شاگرد (consent_confirmed) الزامی است',
                422,
                'CONSENT_REQUIRED'
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($consentChanged) {
            if ($effectiveConsent === 1) {
                $update['consent_confirmed_at'] = $now;
                $update['consent_confirmed_by'] = $actorUserId;
            } else {
                $update['consent_confirmed_at'] = null;
                $update['consent_confirmed_by'] = null;
            }
        }

        if ($effectiveStatus !== $currentStatus) {
            $update['published_at'] = $effectiveStatus === 'published' ? $now : null;
        }

        $oldImagePath = isset($existing['image_url']) && is_string($existing['image_url']) && $existing['image_url'] !== ''
            ? $existing['image_url']
            : null;
        $newImagePath = null;
        $removeImage = array_key_exists('remove_image', $data)
            && $this->parseBooleanFlag($data['remove_image'], 'حذف تصویر') === 1;

        if (isset($data['image']) && $data['image'] !== null) {
            $newImagePath = $this->uploadImage($data['image']);
            $update['image_url'] = $newImagePath;
        } elseif ($removeImage) {
            $update['image_url'] = null;
        }

        if ($update === []) {
            throw new ApiException('حداقل یک فیلد معتبر برای ویرایش رکورد قبولی الزامی است', 422, 'VALIDATION_ERROR');
        }

        $update['updated_by'] = $actorUserId;

        try {
            Admission::update($id, $update);
        } catch (\Throwable $e) {
            if ($newImagePath !== null) {
                Storage::delete($newImagePath);
            }
            throw $e;
        }

        if ($oldImagePath !== null && ($newImagePath !== null || $removeImage)) {
            Storage::delete($oldImagePath);
        }

        Cache::flushGroup('public');

        return $this->get($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $id, string $status, ?bool $consentConfirmed = null, ?int $actorUserId = null): array
    {
        $payload = ['status' => $status];
        if ($consentConfirmed !== null) {
            $payload['consent_confirmed'] = $consentConfirmed;
        }

        return $this->update($id, $payload, $actorUserId);
    }

    public function delete(int $id): void
    {
        $existing = Admission::findById($id);
        if ($existing === null) {
            throw new ApiException('رکورد قبولی یافت نشد', 404, 'NOT_FOUND');
        }

        if (!empty($existing['image_url']) && is_string($existing['image_url'])) {
            Storage::delete($existing['image_url']);
        }

        Admission::delete($id);

        Cache::flushGroup('public');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateAdmin(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'student_name'         => (string) $row['student_name'],
            'jalali_year'          => (int) $row['jalali_year'],
            'exam_type'            => (string) ($row['exam_type'] ?? 'konkur'),
            'field'                => $row['field'] !== null ? (string) $row['field'] : null,
            'result_title'         => (string) $row['result_title'],
            'rank_value'           => $row['rank_value'] !== null ? (string) $row['rank_value'] : null,
            'quote'                => $row['quote'] !== null ? (string) $row['quote'] : null,
            'image_url'            => AssetUrl::fromStorage(is_string($row['image_url'] ?? null) ? $row['image_url'] : null),
            'display_order'        => (int) ($row['display_order'] ?? 0),
            'is_featured'          => (bool) ((int) ($row['is_featured'] ?? 0)),
            'consent_confirmed'    => (bool) ((int) ($row['consent_confirmed'] ?? 0)),
            'consent_confirmed_at' => $row['consent_confirmed_at'] ?? null,
            'consent_confirmed_by' => isset($row['consent_confirmed_by']) && $row['consent_confirmed_by'] !== null ? (int) $row['consent_confirmed_by'] : null,
            'status'               => (string) ($row['status'] ?? 'draft'),
            'published_at'         => $row['published_at'] ?? null,
            'created_by'           => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'updated_by'           => isset($row['updated_by']) && $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'created_at'           => $row['created_at'] ?? null,
            'updated_at'           => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePublic(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'student_name'  => (string) $row['student_name'],
            'jalali_year'   => (int) $row['jalali_year'],
            'exam_type'     => (string) ($row['exam_type'] ?? 'konkur'),
            'field'         => $row['field'] !== null ? (string) $row['field'] : null,
            'result_title'  => (string) $row['result_title'],
            'rank_value'    => $row['rank_value'] !== null ? (string) $row['rank_value'] : null,
            'quote'         => $row['quote'] !== null ? (string) $row['quote'] : null,
            'image_url'     => AssetUrl::fromStorage(is_string($row['image_url'] ?? null) ? $row['image_url'] : null),
            'display_order' => (int) ($row['display_order'] ?? 0),
            'is_featured'   => (bool) ((int) ($row['is_featured'] ?? 0)),
            'published_at'  => $row['published_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeListFilters(array $filters, bool $publicOnly): array
    {
        $out = [];

        if (isset($filters['year']) && $filters['year'] !== '' && $filters['year'] !== null) {
            if (filter_var($filters['year'], FILTER_VALIDATE_INT) === false) {
                throw new ApiException('سال شمسی نامعتبر است', 422, 'VALIDATION_ERROR');
            }
            $year = (int) $filters['year'];
            if ($year < self::MIN_JALALI_YEAR || $year > self::MAX_JALALI_YEAR) {
                throw new ApiException('سال شمسی باید بین 1380 تا 1450 باشد', 422, 'VALIDATION_ERROR');
            }
            $out['year'] = $year;
        }

        if (isset($filters['exam_type']) && trim((string) $filters['exam_type']) !== '') {
            $out['exam_type'] = $this->parseExamType($filters['exam_type']);
        }

        if (isset($filters['field']) && trim((string) $filters['field']) !== '') {
            $out['field'] = mb_substr(trim((string) $filters['field']), 0, 60);
        }

        if (array_key_exists('featured', $filters) && $filters['featured'] !== '' && $filters['featured'] !== null) {
            $out['is_featured'] = $this->parseBooleanFlag($filters['featured'], 'نمایش ویژه');
        } elseif (array_key_exists('is_featured', $filters) && $filters['is_featured'] !== '' && $filters['is_featured'] !== null) {
            $out['is_featured'] = $this->parseBooleanFlag($filters['is_featured'], 'نمایش ویژه');
        }

        if (!$publicOnly) {
            if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
                $out['status'] = $this->parseStatus($filters['status']);
            }
            if (array_key_exists('consent_confirmed', $filters) && $filters['consent_confirmed'] !== '' && $filters['consent_confirmed'] !== null) {
                $out['consent_confirmed'] = $this->parseBooleanFlag($filters['consent_confirmed'], 'تأیید رضایت');
            }
            if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
                $out['q'] = trim($filters['q']);
            }
        }

        return $out;
    }

    private function uploadImage(mixed $file): string
    {
        if (!($file instanceof UploadedFileInterface) && !is_array($file)) {
            throw new ApiException('فایل تصویر نامعتبر است', 422, 'UPLOAD_ERROR');
        }

        $name = $file instanceof UploadedFileInterface
            ? (string) ($file->getClientFilename() ?? '')
            : (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            throw new ApiException('فقط فایل‌های تصویری (JPG, PNG, WebP) مجاز هستند', 422, 'UPLOAD_ERROR');
        }

        return Storage::upload($file, 'admissions');
    }

    private function requireTrimmedString(mixed $value, string $label, int $maxLength): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new ApiException("{$label} الزامی است", 422, 'VALIDATION_ERROR');
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            throw new ApiException("{$label} الزامی است", 422, 'VALIDATION_ERROR');
        }
        if (mb_strlen($trimmed) > $maxLength) {
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

    private function requireJalaliYear(mixed $value): int
    {
        if ($value === null || $value === '' || is_bool($value) || !is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('سال شمسی الزامی و باید عدد صحیح باشد', 422, 'VALIDATION_ERROR');
        }
        $year = (int) $value;
        if ($year < self::MIN_JALALI_YEAR || $year > self::MAX_JALALI_YEAR) {
            throw new ApiException('سال شمسی باید بین 1380 تا 1450 باشد', 422, 'VALIDATION_ERROR');
        }
        return $year;
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

    private function parseStatus(mixed $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';
        if (!in_array($normalized, self::VALID_STATUSES, true)) {
            throw new ApiException('وضعیت باید draft یا published باشد', 422, 'VALIDATION_ERROR');
        }
        return $normalized;
    }

    private function parseExamType(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'konkur';
        }
        $normalized = is_string($value) ? strtolower(trim($value)) : '';
        if (!in_array($normalized, self::VALID_EXAM_TYPES, true)) {
            throw new ApiException('نوع آزمون نامعتبر است', 422, 'VALIDATION_ERROR');
        }
        return $normalized;
    }
}
