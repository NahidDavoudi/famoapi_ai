<?php
declare(strict_types=1);

namespace App\Modules\Admissions;

use App\Core\Database;
use PDO;

class Admission
{
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'ORDER BY jalali_year DESC, display_order ASC, id DESC'
    ): array {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, false);

        $offset = max(0, ($page - 1) * $perPage);
        $orderClause = str_starts_with(strtoupper(ltrim($orderBy)), 'ORDER BY')
            ? $orderBy
            : "ORDER BY {$orderBy}";
        $sql = "SELECT * FROM admissions {$whereSql} {$orderClause} LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function countAll(array $filters = []): int
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, false);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions {$whereSql}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Publicly visible records strictly require status = 'published' AND consent_confirmed = 1.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function findPublic(array $filters = [], ?int $page = null, ?int $perPage = null): array
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, true);

        $sql = "SELECT id, student_name, jalali_year, exam_type, field, result_title,
                       rank_value, quote, image_url, display_order, is_featured,
                       published_at, created_at
                FROM admissions
                {$whereSql}
                ORDER BY jalali_year DESC, is_featured DESC, display_order ASC, id DESC";

        if ($page !== null && $perPage !== null) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if ($page !== null && $perPage !== null) {
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function countPublic(array $filters = []): int
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, true);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions {$whereSql}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Distinct Jalali years that have at least one published + consented admission.
     *
     * @return list<int>
     */
    public static function findPublishedYears(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT DISTINCT jalali_year
             FROM admissions
             WHERE status = 'published' AND consent_confirmed = 1
             ORDER BY jalali_year DESC"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('intval', $rows));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM admissions WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO admissions (
                student_name,
                jalali_year,
                exam_type,
                field,
                result_title,
                rank_value,
                quote,
                image_url,
                display_order,
                is_featured,
                consent_confirmed,
                consent_confirmed_at,
                consent_confirmed_by,
                status,
                published_at,
                created_by,
                updated_by,
                created_at,
                updated_at
            ) VALUES (
                :student_name,
                :jalali_year,
                :exam_type,
                :field,
                :result_title,
                :rank_value,
                :quote,
                :image_url,
                :display_order,
                :is_featured,
                :consent_confirmed,
                :consent_confirmed_at,
                :consent_confirmed_by,
                :status,
                :published_at,
                :created_by,
                :updated_by,
                :created_at,
                :updated_at
            )'
        );

        $stmt->execute([
            ':student_name'         => $data['student_name'],
            ':jalali_year'          => (int) $data['jalali_year'],
            ':exam_type'            => $data['exam_type'] ?? 'konkur',
            ':field'                => $data['field'] ?? null,
            ':result_title'         => $data['result_title'],
            ':rank_value'           => $data['rank_value'] ?? null,
            ':quote'                => $data['quote'] ?? null,
            ':image_url'            => $data['image_url'] ?? null,
            ':display_order'        => (int) ($data['display_order'] ?? 0),
            ':is_featured'          => (int) ($data['is_featured'] ?? 0),
            ':consent_confirmed'    => (int) ($data['consent_confirmed'] ?? 0),
            ':consent_confirmed_at' => $data['consent_confirmed_at'] ?? null,
            ':consent_confirmed_by' => $data['consent_confirmed_by'] ?? null,
            ':status'               => $data['status'] ?? 'draft',
            ':published_at'         => $data['published_at'] ?? null,
            ':created_by'           => $data['created_by'] ?? null,
            ':updated_by'           => $data['updated_by'] ?? null,
            ':created_at'           => $now,
            ':updated_at'           => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): int
    {
        $allowed = [
            'student_name',
            'jalali_year',
            'exam_type',
            'field',
            'result_title',
            'rank_value',
            'quote',
            'image_url',
            'display_order',
            'is_featured',
            'consent_confirmed',
            'consent_confirmed_at',
            'consent_confirmed_by',
            'status',
            'published_at',
            'updated_by',
        ];

        $sets = [];
        $params = [':id' => $id];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }

        if ($sets === []) {
            return 0;
        }

        $sets[] = 'updated_at = :updated_at';
        $params[':updated_at'] = gmdate('Y-m-d H:i:s');

        $sql = 'UPDATE admissions SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('DELETE FROM admissions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildWhere(array $filters, bool $publicOnly): array
    {
        $conditions = [];
        $params = [];

        if ($publicOnly) {
            $conditions[] = "status = 'published'";
            $conditions[] = 'consent_confirmed = 1';
        } else {
            if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
                $conditions[] = 'status = :status';
                $params[':status'] = (string) $filters['status'];
            }
            if (isset($filters['consent_confirmed']) && $filters['consent_confirmed'] !== '' && $filters['consent_confirmed'] !== null) {
                $conditions[] = 'consent_confirmed = :consent_confirmed';
                $params[':consent_confirmed'] = (int) $filters['consent_confirmed'];
            }
        }

        if (isset($filters['year']) && $filters['year'] !== '' && $filters['year'] !== null) {
            $conditions[] = 'jalali_year = :jalali_year';
            $params[':jalali_year'] = (int) $filters['year'];
        }

        if (isset($filters['exam_type']) && $filters['exam_type'] !== '' && $filters['exam_type'] !== null) {
            $conditions[] = 'exam_type = :exam_type';
            $params[':exam_type'] = (string) $filters['exam_type'];
        }

        if (isset($filters['field']) && trim((string) $filters['field']) !== '') {
            $conditions[] = 'field = :field';
            $params[':field'] = trim((string) $filters['field']);
        }

        if (isset($filters['is_featured']) && $filters['is_featured'] !== '' && $filters['is_featured'] !== null) {
            $conditions[] = 'is_featured = :is_featured';
            $params[':is_featured'] = (int) $filters['is_featured'];
        }

        if (!$publicOnly && isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $q = '%' . trim((string) $filters['q']) . '%';
            $conditions[] = '(student_name LIKE :q_name OR result_title LIKE :q_title OR COALESCE(rank_value, \'\') LIKE :q_rank)';
            $params[':q_name'] = $q;
            $params[':q_title'] = $q;
            $params[':q_rank'] = $q;
        }

        $whereSql = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        return [$whereSql, $params];
    }
}
