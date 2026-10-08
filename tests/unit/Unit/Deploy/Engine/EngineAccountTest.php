<?php

namespace Tests\Unit\Deploy\Engine;

use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;

/**
 * Every engine command is built from these fields, so a malformed one must be
 * rejected at construction rather than reaching a command line.
 */
class EngineAccountTest extends TestCase
{
    public function test_project_dir_is_derived_from_the_home_directory(): void
    {
        $account = new EngineAccount('alice', '/home/alice/');

        $this->assertSame('/home/alice/project', $account->projectDir());
        $this->assertSame('33:33', $account->identity);
    }

    public function test_a_username_that_could_reach_a_shell_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EngineAccount('alice; rm -rf /', '/home/alice');
    }

    public function test_an_unexpected_home_directory_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EngineAccount('alice', '/home/alice/../../etc');
    }

    public function test_a_non_numeric_identity_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EngineAccount('alice', '/home/alice', 'www-data:www-data');
    }

    public function test_a_missing_control_file_is_reported_where_it_is_needed(): void
    {
        $account = new EngineAccount('alice', '/home/alice');

        $this->expectException(\LogicException::class);
        $account->controlFileOrFail();
    }
}
