<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\LinkedSource;
use PHPUnit\Framework\TestCase;

/** A source below a plain file cannot be looked into, so where it leads cannot be told and it is refused. */
class LinkedSourceThroughFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/linked-through-file-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/acct/project', 0755, true);
        file_put_contents($this->root . '/acct/project/notes.txt', "plain\n");
        chmod($this->root . '/acct/project/notes.txt', 0644);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_path_under_a_plain_file_is_refused(): void
    {
        $this->assertTrue(LinkedSource::escapes('./notes.txt/data', $this->root . '/acct/project'));
        $this->assertFalse(LinkedSource::escapes('./missing/data', $this->root . '/acct/project'));
    }
}
