<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\EnvFile;
use PHPUnit\Framework\TestCase;

/**
 * DB_* read from a project's `.env` past its comments and blank lines, and a project that mentions no `.env.local`.
 */
class EnvFileDatabaseSettingsTest extends TestCase
{
    public function test_comments_and_blank_lines_are_skipped_while_reading_the_database_settings(): void
    {
        $files = ['/p/.env' => "# database\n\nDB_CONNECTION=mysql\n# DB_HOST=ignored\nDB_HOST=db\nDB_DATABASE=app\nAPP_NAME=x\n"];

        $settings = EnvFile::databaseSettings('/p/', static fn (string $path): ?string => $files[$path] ?? null);

        $this->assertSame('mysql', $settings['connection']);
        $this->assertSame('db', $settings['host']);
        $this->assertSame('app', $settings['database']);
        $this->assertSame('3306', $settings['port']);
    }

    public function test_a_project_that_is_not_there_mentions_no_env_local(): void
    {
        $this->assertFalse(EnvFile::projectMentionsEnvLocal('/nonexistent/pa-envfile'));
    }
}
