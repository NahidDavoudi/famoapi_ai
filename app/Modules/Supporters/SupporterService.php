<?php

namespace App\Modules\Supporters;

use App\Core\ApiException;
use App\Core\AssetUrl;
use App\Core\Cache;
use App\Core\Database;
use App\Core\Pagination;
use App\Core\PhoneNumber;
use App\Core\Storage;
use Psr\Http\Message\UploadedFileInterface;

class SupporterService
{
    private const VALID_GRADES = [7, 8, 9, 10, 11, 12];

    /**
     * @param array<int,array{field:mixed,grade:mixed}> $scopes
     * @return array<int,array{field:string,grade:int}>
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                throw new ApiException('ساختار محدوده پشتیبانی معتبر نیست', 422, 'VALIDATION_ERROR');
            }
            $field = trim((string) ($scope['field'] ?? ''));
            $grade = (int) ($scope['grade'] ?? 0);
            if ($field === '' || !in_array($grade, self::VALID_GRADES, true)) {
                throw new ApiException('رشته یا پایه تحصیلی محدوده پشتیبانی معتبر نیست', 422, 'VALIDATION_ERROR');
            }
            $normalized[$field . '|' . $grade] = ['field' => $field, 'grade' => $grade];
        }

        return array_values($normalized);
    }

    private function normalizePhoneOrFail(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        return PhoneNumber::normalizeOrFail($phone, 'شماره تلفن پشتیبان معتبر نیست');
    }

    /**
     * افزودن avatar_url، image_url و description به هر ردیف پشتیبان.
     * Appends public avatar_url, image_url (alias for instructor compatibility), and description to each supporter row.
     *
     * @param array<int,array> $supporters
     * @return array<int,array>
     */
    private function withAvatarUrl(array $supporters): array
    {
        foreach ($supporters as &$row) {
            $resolvedUrl = AssetUrl::fromStorage($row['avatar'] ?? null);
            $row['avatar_url'] = $resolvedUrl;
            $row['image_url'] = $resolvedUrl;
            $row['description'] = $row['description'] ?? null;
        }
        unset($row);
        return $supporters;
    }

    public function list(int $page, int $perPage): array
    {
        $total = Supporter::countAll();
        $pagination = Pagination::build($page, $perPage, $total);

        $items = Supporter::findAll($pagination['page'], $pagination['per_page']);
        $items = $this->withAvatarUrl($items);

        return [
            'items'      => $items,
            'pagination' => $pagination,
        ];
    }

    /**
     * @param array $data
     * @param UploadedFileInterface|null $avatarFile
     * @return array
     */
    public function create(array $data, ?UploadedFileInterface $avatarFile = null): array
    {
        Supporter::ensureProfileColumns();

        if (array_key_exists('phone', $data)) {
            $data['phone'] = $this->normalizePhoneOrFail($data['phone']);
        }

        $scopes = null;
        if (array_key_exists('scopes', $data) && is_array($data['scopes']) && $data['scopes'] !== []) {
            $scopes = $this->normalizeScopes($data['scopes']);
        } elseif (array_key_exists('grade', $data) && array_key_exists('field', $data)
            && $data['grade'] !== null && $data['field'] !== null && $data['field'] !== '') {
            $scopes = $this->normalizeScopes([['field' => $data['field'], 'grade' => $data['grade']]]);
        }

        if ($scopes !== null && $scopes !== []) {
            if (empty($data['field'])) {
                $data['field'] = $scopes[0]['field'];
            }
            if (empty($data['grade'])) {
                $data['grade'] = $scopes[0]['grade'];
            }
        }

        // اگر فایل آواتار ارسال شده باشد، ابتدا NULL ذخیره شده و بعد از ساخت رکورد آپلود می‌شود تا مسیر با id ذخیره گردد
        if ($avatarFile !== null && $avatarFile->getError() === UPLOAD_ERR_OK) {
            $data['avatar'] = null;
        } else {
            $data['avatar'] = $data['avatar'] ?? null;
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $supporterId = Supporter::create($data);

            // آپلود آواتار اگر فایلی ارسال شده باشد
            if ($avatarFile !== null && $avatarFile->getError() === UPLOAD_ERR_OK) {
                $avatarPath = Storage::upload($avatarFile, 'supporters', $supporterId);
                Supporter::update($supporterId, ['avatar' => $avatarPath]);
            }

            $stmt = $db->prepare(
                'INSERT INTO users (username, phone, full_name, password_hash, role, linked_id)
                 VALUES (:username, :phone, :full_name, :password_hash, :role, :linked_id)'
            );
            $stmt->execute([
                'username'      => $data['phone'] ?? $data['name'],
                'phone'         => $data['phone'] ?? null,
                'full_name'     => $data['name'] ?? null,
                'password_hash' => password_hash('1234', PASSWORD_DEFAULT),
                'role'          => 'supporter',
                'linked_id'     => $supporterId,
            ]);

            if ($scopes !== null) {
                Supporter::replaceScopes($supporterId, $scopes);
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        Cache::flushGroup('public');

        return $this->get($supporterId);
    }

    public function get(int $id): array
    {
        $supporter = Supporter::findById($id);
        if (!$supporter) {
            throw new \App\Core\ApiException('پشتیبان یافت نشد', 404, 'NOT_FOUND');
        }
        $resolvedUrl = AssetUrl::fromStorage($supporter['avatar'] ?? null);
        $supporter['avatar_url'] = $resolvedUrl;
        $supporter['image_url'] = $resolvedUrl;
        $supporter['description'] = $supporter['description'] ?? null;
        return $supporter;
    }

    public function missingPhone(int $page, int $perPage): array
    {
        $total = Supporter::countMissingPhone();
        $pagination = Pagination::build($page, $perPage, $total);

        $items = Supporter::findMissingPhone($pagination['page'], $pagination['per_page']);
        $items = $this->withAvatarUrl($items);

        return [
            'items'      => $items,
            'pagination' => $pagination,
        ];
    }

    /**
     * @param int $id
     * @param array $data
     * @param UploadedFileInterface|null $avatarFile
     * @return array
     */
    public function update(int $id, array $data, ?UploadedFileInterface $avatarFile = null): array
    {
        Supporter::ensureProfileColumns();

        $supporter = Supporter::findById($id);
        if (!$supporter) {
            throw new \App\Core\ApiException('پشتیبان یافت نشد', 404, 'NOT_FOUND');
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = $this->normalizePhoneOrFail($data['phone']);
        }

        $scopes = null;
        if (array_key_exists('scopes', $data) && is_array($data['scopes'])) {
            $scopes = $this->normalizeScopes($data['scopes']);
        } elseif (array_key_exists('grade', $data) || array_key_exists('field', $data)) {
            $field = array_key_exists('field', $data) ? $data['field'] : ($supporter['field'] ?? '');
            $grade = array_key_exists('grade', $data) ? $data['grade'] : ($supporter['grade'] ?? 0);
            $scopes = $this->normalizeScopes([['field' => $field, 'grade' => $grade]]);
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // اگر آواتار به صورت رشته خالی یا null صراحتاً برای حذف ارسال شده باشد (و فایل جدیدی نباشد)
            if ($avatarFile === null && array_key_exists('avatar', $data) && ($data['avatar'] === null || $data['avatar'] === '')) {
                if (!empty($supporter['avatar'])) {
                    Storage::delete($supporter['avatar']);
                }
                $data['avatar'] = null;
            }

            Supporter::update($id, $data);

            // آپلود آواتار جدید و جایگزینی با قبلی
            if ($avatarFile !== null && $avatarFile->getError() === UPLOAD_ERR_OK) {
                // حذف آواتار قبلی در صورت وجود
                if (!empty($supporter['avatar'])) {
                    Storage::delete($supporter['avatar']);
                }
                $avatarPath = Storage::upload($avatarFile, 'supporters', $id);
                Supporter::update($id, ['avatar' => $avatarPath]);
            }

            if (array_key_exists('name', $data) && $data['name'] !== null && $data['name'] !== '') {
                $stmtName = $db->prepare(
                    'UPDATE users SET full_name = :full_name WHERE linked_id = :linked_id AND role = :role'
                );
                $stmtName->execute([
                    'full_name' => $data['name'],
                    'linked_id' => $id,
                    'role'      => 'supporter',
                ]);
            }

            if (array_key_exists('phone', $data) && $data['phone'] !== null) {
                $stmt = $db->prepare(
                    'UPDATE users SET username = :username, phone = :phone WHERE linked_id = :linked_id AND role = :role'
                );
                $stmt->execute([
                    'username'  => $data['phone'],
                    'phone'     => $data['phone'],
                    'linked_id' => $id,
                    'role'      => 'supporter',
                ]);
            }

            if (isset($data['password'])) {
                $stmt = $db->prepare(
                    'UPDATE users SET password_hash = :password WHERE linked_id = :linked_id AND role = :role'
                );
                $stmt->execute([
                    'password'  => password_hash($data['password'], PASSWORD_DEFAULT),
                    'linked_id' => $id,
                    'role'      => 'supporter',
                ]);
            }

            if ($scopes !== null) {
                Supporter::replaceScopes($id, $scopes);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Cache::flushGroup('public');

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $supporter = Supporter::findById($id);
        if (!$supporter) {
            throw new \App\Core\ApiException('پشتیبان یافت نشد', 404, 'NOT_FOUND');
        }

        // حذف فایل آواتار در صورت وجود
        if (!empty($supporter['avatar'])) {
            Storage::delete($supporter['avatar']);
        }

        Supporter::delete($id);

        Cache::flushGroup('public');
    }
}
