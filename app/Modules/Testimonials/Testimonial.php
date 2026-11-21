<?php
declare(strict_types=1);

namespace App\Modules\Testimonials;

use App\Core\Database;
use PDO;

class Testimonial
{
    private const BASE_SELECT = '
        SELECT
            t.id,
            t.author_name,
            t.author_role,
            t.author_meta,
            t.rating,
            t.content,
            t.course_id,
            t.instructor_id,
            t.status,
            t.is_featured,
            t.display_order,
            t.source,
            t.moderated_by,
            t.moderated_at,
            t.rejection_reason,
            t.created_at,
            t.updated_at,
            c.name AS course_name,
            i.name AS instructor_name
        FROM testimonials t
        LEFT JOIN courses c ON c.id = t.course_id
        LEFT JOIN instructors i ON i.id = t.instructor_id
    ';

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'ORDER BY t.created_at DESC, t.id DESC'
    ): array {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, false);

        $offset = max(0, ($page - 1) * $perPage);
        $orderClause = str_starts_with(strtoupper(ltrim($orderBy)), 'ORDER BY')
            ? $orderBy
            : "ORDER BY {$orderBy}";
        $sql = self::BASE_SELECT . " {$whereSql} {$orderClause} LIMIT :limit OFFSET :offset";

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

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM testimonials t {$whereSql}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Publicly visible records strictly require status = 'approved'.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function findPublic(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, true);

        $offset = max(0, ($page - 1) * $perPage);
        $sql = self::BASE_SELECT
            . " {$whereSql} ORDER BY t.is_featured DESC, t.display_order ASC, t.id DESC LIMIT :limit OFFSET :offset";

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
    public static function countPublic(array $filters = []): int
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, true);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM testimonials t {$whereSql}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Aggregate stats for approved testimonials matching optional public filters.
     *
     * @param array<string, mixed> $filters
     * @return array{total_approved: int, average_rating: float}
     */
    public static function publicSummary(array $filters = []): array
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters, true);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_approved, AVG(t.rating) AS avg_rating FROM testimonials t {$whereSql}"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int) ($row['total_approved'] ?? 0);
        $avg = $total > 0 && $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 2) : 0.0;

        return [
            'total_approved' => $total,
            'average_rating' => $avg,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(self::BASE_SELECT . ' WHERE t.id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function courseExists(int $courseId): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT 1 FROM courses WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $courseId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public static function instructorExists(int $instructorId): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT 1 FROM instructors WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $instructorId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO testimonials (
                author_name,
                author_role,
                author_meta,
                rating,
                content,
                course_id,
                instructor_id,
                status,
                is_featured,
                display_order,
                source,
                moderated_by,
                moderated_at,
                rejection_reason,
                created_at,
                updated_at
            ) VALUES (
                :author_name,
                :author_role,
                :author_meta,
                :rating,
                :content,
                :course_id,
                :instructor_id,
                :status,
                :is_featured,
                :display_order,
                :source,
                :moderated_by,
                :moderated_at,
                :rejection_reason,
                :created_at,
                :updated_at
            )'
        );

        $stmt->execute([
            ':author_name'      => $data['author_name'],
            ':author_role'      => $data['author_role'] ?? 'student',
            ':author_meta'      => $data['author_meta'] ?? null,
            ':rating'           => (int) ($data['rating'] ?? 5),
            ':content'          => $data['content'],
            ':course_id'        => $data['course_id'] ?? null,
            ':instructor_id'    => $data['instructor_id'] ?? null,
            ':status'           => $data['status'] ?? 'pending',
            ':is_featured'      => (int) ($data['is_featured'] ?? 0),
            ':display_order'    => (int) ($data['display_order'] ?? 0),
            ':source'           => $data['source'] ?? 'public_form',
            ':moderated_by'     => $data['moderated_by'] ?? null,
            ':moderated_at'     => $data['moderated_at'] ?? null,
            ':rejection_reason' => $data['rejection_reason'] ?? null,
            ':created_at'       => $now,
            ':updated_at'       => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): int
    {
        $allowed = [
            'author_name',
            'author_role',
            'author_meta',
            'rating',
            'content',
            'course_id',
            'instructor_id',
            'status',
            'is_featured',
            'display_order',
            'moderated_by',
            'moderated_at',
            'rejection_reason',
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

        $sql = 'UPDATE testimonials SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param list<int> $ids
     */
    public static function bulkReject(array $ids, ?string $rejectionReason, ?int $moderatorUserId): int
    {
        if ($ids === []) {
            return 0;
        }

        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $sql = "UPDATE testimonials
                SET status = 'rejected',
                    rejection_reason = ?,
                    moderated_by = ?,
                    moderated_at = ?,
                    updated_at = ?
                WHERE id IN ({$placeholders})";

        $stmt = $pdo->prepare($sql);
        $params = array_merge([$rejectionReason, $moderatorUserId, $now, $now], $ids);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('DELETE FROM testimonials WHERE id = :id');
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
            $conditions[] = "t.status = 'approved'";
        } else {
            if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
                $conditions[] = 't.status = :status';
                $params[':status'] = (string) $filters['status'];
            }
            if (isset($filters['source']) && $filters['source'] !== '' && $filters['source'] !== null) {
                $conditions[] = 't.source = :source';
                $params[':source'] = (string) $filters['source'];
            }
            if (isset($filters['rating']) && $filters['rating'] !== '' && $filters['rating'] !== null) {
                $conditions[] = 't.rating = :rating';
                $params[':rating'] = (int) $filters['rating'];
            }
            if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
                $q = '%' . trim((string) $filters['q']) . '%';
                $conditions[] = '(t.author_name LIKE :q_name OR t.content LIKE :q_content OR COALESCE(t.author_meta, \'\') LIKE :q_meta)';
                $params[':q_name'] = $q;
                $params[':q_content'] = $q;
                $params[':q_meta'] = $q;
            }
        }

        if (isset($filters['author_role']) && $filters['author_role'] !== '' && $filters['author_role'] !== null) {
            $conditions[] = 't.author_role = :author_role';
            $params[':author_role'] = (string) $filters['author_role'];
        }

        if (isset($filters['is_featured']) && $filters['is_featured'] !== '' && $filters['is_featured'] !== null) {
            $conditions[] = 't.is_featured = :is_featured';
            $params[':is_featured'] = (int) $filters['is_featured'];
        }

        if (isset($filters['course_id']) && $filters['course_id'] !== '' && $filters['course_id'] !== null) {
            $conditions[] = 't.course_id = :course_id';
            $params[':course_id'] = (int) $filters['course_id'];
        }

        if (isset($filters['instructor_id']) && $filters['instructor_id'] !== '' && $filters['instructor_id'] !== null) {
            $conditions[] = 't.instructor_id = :instructor_id';
            $params[':instructor_id'] = (int) $filters['instructor_id'];
        }

        $whereSql = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        return [$whereSql, $params];
    }
}
