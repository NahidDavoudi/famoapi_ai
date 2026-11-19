<?php

namespace Tests\Core;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

class MigratorTest extends TestCase
{
    public function testSplitStatementsPreservesQuotedSemicolonsAndDropsComments(): void
    {
        $statements = Migrator::splitStatements(<<<'SQL'
            -- first statement
            INSERT INTO notes (body) VALUES ('one;two'); /* inline ; comment */
            INSERT INTO notes (body) VALUES ("three;four");
            # final statement
            SELECT `column;name` FROM notes;
            SQL);

        self::assertSame([
            "INSERT INTO notes (body) VALUES ('one;two')",
            'INSERT INTO notes (body) VALUES ("three;four")',
            'SELECT `column;name` FROM notes',
        ], $statements);
    }

    public function testSplitStatementsHandlesEscapedQuotes(): void
    {
        $statements = Migrator::splitStatements("INSERT INTO notes VALUES ('it\\'s; fine'); SELECT 1;");

        self::assertSame(["INSERT INTO notes VALUES ('it\\'s; fine')", 'SELECT 1'], $statements);
    }

    public function testSplitStatementsPreservesPostgresDollarQuotedFunctions(): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION calc_response() RETURNS TRIGGER AS $$
            BEGIN
                NEW.response_time_minutes := 10;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_calc BEFORE UPDATE ON reports_status FOR EACH ROW EXECUTE FUNCTION calc_response();
            SQL;

        $statements = Migrator::splitStatements($sql);
        self::assertCount(2, $statements);
        self::assertStringContainsString('NEW.response_time_minutes := 10;', $statements[0]);
        self::assertStringStartsWith('CREATE TRIGGER trg_calc', $statements[1]);
    }
}
