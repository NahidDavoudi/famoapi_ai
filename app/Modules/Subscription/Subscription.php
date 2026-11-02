<?php

namespace App\Modules\Subscription;

use App\Core\Database;

class Subscription
{
    public static function findAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM subscriptions ORDER BY id DESC');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO subscriptions (course_id, student_id, note, end_at)
             VALUES (:course_id, :student_id, :note, :end_at)'
        );
        $stmt->execute([
            'course_id'  => (int) $data['course_id'],
            'student_id' => (int) $data['student_id'],
            'note'       => $data['note'] ?? null,
            'end_at'     => $data['end_at'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }
}