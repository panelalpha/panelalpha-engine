<?php

namespace Tests\Unit\System;

use App\System\Services\Mysql;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

class MysqlTableDumpTest extends TestCase
{
    public function test_a_single_table_dump_names_the_table_as_a_plain_argument(): void
    {
        $system = new RecordingSystem();
        $system->output = "-- Dump completed on 2026-01-01\n";

        (new Mysql($system))->dumpToGzip('alice_wp', '/tmp/alice.sql.gz', 'wp_posts');

        $script = $system->firstScript();
        $this->assertStringContainsString('mariadb-dump --single-transaction -uroot', $script);
        $this->assertStringContainsString("'alice_wp'", $script);
        $this->assertStringContainsString("'wp_posts'", $script);
        $this->assertStringNotContainsString('--routines', $script);
        $this->assertStringNotContainsString('--events', $script);
    }

    public function test_a_whole_database_dump_keeps_the_database_level_flags(): void
    {
        $system = new RecordingSystem();
        $system->output = "-- Dump completed on 2026-01-01\n";

        (new Mysql($system))->dumpToGzip('alice_wp', '/tmp/alice.sql.gz');

        $this->assertStringContainsString('--routines --triggers --events', $system->firstScript());
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
}
