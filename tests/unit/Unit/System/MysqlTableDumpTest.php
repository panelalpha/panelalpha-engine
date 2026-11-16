<?php

namespace Tests\Unit\System;

use App\System\Services\Mysql;
use Tests\Support\LocalRoot;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

class MysqlTableDumpTest extends TestCase
{
    public function test_a_single_table_dump_names_the_table_as_a_plain_argument(): void
    {
        $system = new RecordingSystem();
        $system->output = "-- Dump completed on 2026-01-01\n";

        (new Mysql($system))->dumpToGzip('alice_wp', '/tmp/alice.sql.gz', 'wp_posts');

        $this->assertSame(
            LocalRoot::CLIENT.' compose.sitesDbDump -- /opt/panelalpha/shared-hosting alice_wp wp_posts',
            self::asked($system),
        );
    }

    public function test_a_whole_database_dump_names_no_table(): void
    {
        $system = new RecordingSystem();
        $system->output = "-- Dump completed on 2026-01-01\n";

        (new Mysql($system))->dumpToGzip('alice_wp', '/tmp/alice.sql.gz');

        $this->assertSame(
            LocalRoot::CLIENT." compose.sitesDbDump -- /opt/panelalpha/shared-hosting alice_wp ''",
            self::asked($system),
        );
    }

    public function test_a_table_name_outside_the_grammar_is_refused(): void
    {
        $system = new RecordingSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid table name');

        (new Mysql($system))->dumpToGzip('alice_wp', '/tmp/alice.sql.gz', "wp_posts`; DROP TABLE x");
    }

    public function test_the_table_name_grammar_accepts_a_dollar_sign(): void
    {
        $this->assertTrue(Mysql::isValidTableName('wp_$posts'));
        $this->assertFalse(Mysql::isValidTableName('wp posts'));
        $this->assertFalse(Mysql::isValidTableName('wp_posts;'));
    }

    /** The broker request the first recorded line pipes into gzip. */
    private static function asked(RecordingSystem $system): string
    {
        return explode(' | ', substr($system->firstScript(), strlen('set -o pipefail; ')))[0];
    }
}
