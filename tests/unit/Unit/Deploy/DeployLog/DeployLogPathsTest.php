<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogPaths;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Where one account's deploy logs live.
 *
 * Every path here is built from a username, so a traversal in any one of them
 * is the same bug: an account reading, or a deploy overwriting, files in
 * another account's directory. The name is validated once, in the
 * constructor, which is what makes the rest of the class safe by construction.
 */
class DeployLogPathsTest extends TestCase
{
    public function test_each_file_sits_under_the_accounts_own_directory(): void
    {
        $paths = new DeployLogPaths('acme');
        $dir = $paths->directory();

        $this->assertStringEndsWith('/deploy/acme', $dir);
        $this->assertSame($dir . '/dep_123.log', $paths->log('dep_123'));
        $this->assertSame($dir . '/latest.json', $paths->latest());
        $this->assertSame($dir . '/.deploy.lock', $paths->lock());
    }

    public function test_ordinary_account_names_are_accepted(): void
    {
        foreach (['acme', 'acme-shop', 'acme_shop', 'acme.shop', 'a1'] as $name) {
            $this->assertStringEndsWith('/' . $name, (new DeployLogPaths($name))->directory(), $name);
        }
    }

    public function test_a_name_that_could_escape_the_directory_is_refused(): void
    {
        foreach (['../other', 'acme/../root', 'acme/sub', '/etc/passwd', '..'] as $name) {
            try {
                new DeployLogPaths($name);
                $this->fail("accepted {$name}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid username', $e->getMessage());
            }
        }
    }

    public function test_an_empty_or_exotic_name_is_refused(): void
    {
        foreach (['', ' ', "acme\n", 'acme;rm -rf /', 'acme$(id)'] as $name) {
            $this->expectingRejection($name);
        }
    }

    public function test_the_shorthand_validates_the_name_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DeployLogPaths::userDir('../other');
    }

    public function test_the_shared_validator_names_what_it_rejected(): void
    {
        // Used for deploy ids as well as usernames, so the subject has to
        // come from the caller.
        $this->expectExceptionMessage('Invalid deploy id: ../x');

        DeployLogPaths::assertSafeName('../x', 'deploy id');
    }

    public function test_listing_logs_for_an_account_with_none_returns_nothing(): void
    {
        $this->assertSame([], (new DeployLogPaths('nobody-here'))->logFiles());
    }

    private function expectingRejection(string $name): void
    {
        try {
            new DeployLogPaths($name);
            $this->fail('accepted ' . var_export($name, true));
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
