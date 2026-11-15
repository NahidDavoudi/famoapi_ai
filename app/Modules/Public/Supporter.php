<?php

namespace App\Modules\Public;

use App\Core\Database;

class Supporter
{
    public static function findAllPublished(): array
    {
        \App\Modules\Supporters\Supporter::ensureProfileColumns();
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id, name, grade, field, description, avatar FROM supporters'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}