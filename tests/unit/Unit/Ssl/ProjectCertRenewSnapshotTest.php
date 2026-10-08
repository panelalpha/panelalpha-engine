<?php

namespace Tests\Unit\Ssl;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The renewal writes details.ssl while it walks the domains. On SQLite an open
 * cursor pins the read snapshot, and a write from any other process meanwhile
 * (the HTTP-01 check is served by core) makes that write fail as "database is
 * locked". The renewal walks the domains in pages instead.
 */
class ProjectCertRenewSnapshotTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'renew') . '.sqlite';
        touch($this->file);
        foreach (['renew_a', 'renew_b'] as $name) {
            config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'busy_timeout' => 100]]);
        }
        DB::connection('renew_a')->statement('PRAGMA journal_mode = wal');
        DB::connection('renew_a')->statement('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT)');
        DB::connection('renew_a')->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, details TEXT)');
        DB::connection('renew_a')->table('domains')->insert([['id' => 1, 'domain' => 'a.example'], ['id' => 2, 'domain' => 'b.example']]);
        DB::connection('renew_a')->table('users')->insert(['id' => 1, 'details' => '{}']);
    }

    protected function tearDown(): void
    {
        DB::purge('renew_a');
        DB::purge('renew_b');
        @unlink($this->file);
        @unlink($this->file . '-wal');
        @unlink($this->file . '-shm');
        parent::tearDown();
    }

    public function test_a_cursor_cannot_write_after_another_process_did(): void
    {
        $this->expectExceptionMessage('database is locked');

        foreach (DB::connection('renew_a')->table('domains')->orderBy('id')->cursor() as $row) {
            $this->writeAsAnotherProcess();
            DB::connection('renew_a')->table('users')->where('id', 1)->update(['details' => '{"ssl":1}']);
        }
    }

    public function test_pages_can(): void
    {
        foreach (DB::connection('renew_a')->table('domains')->lazyById() as $row) {
            $this->writeAsAnotherProcess();
            DB::connection('renew_a')->table('users')->where('id', 1)->update(['details' => '{"ssl":' . $row->id . '}']);
        }

        $this->assertSame('{"ssl":2}', DB::connection('renew_a')->table('users')->value('details'));
    }

    public function test_the_renewal_walks_the_domains_in_pages(): void
    {
        $renew = (string) file_get_contents(app_path('Console/Commands/Ssl/ProjectCertRenew.php'));

        $this->assertStringNotContainsString('->cursor()', $renew);
        $this->assertStringContainsString('->lazyById()', $renew);
    }

    private function writeAsAnotherProcess(): void
    {
        DB::connection('renew_b')->table('domains')->where('id', 1)->update(['domain' => uniqid('a', true)]);
    }
}
