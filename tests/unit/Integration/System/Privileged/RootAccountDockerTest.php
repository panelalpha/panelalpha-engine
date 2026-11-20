<?php

namespace Tests\Integration\System\Privileged;

use App\System\Privileged;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\RootAccountDockerFixtures;

final class RootAccountDockerTest extends TestCase
{
    use RootAccountDockerFixtures;

    /** @param  \Closure(): list<string>  $request */
    #[DataProvider('requests')]
    public function test_the_broker_takes_each_request_as_an_engine_api_call_it_makes_itself(\Closure $request, array $expected): void
    {
        $this->assertSame(['pa-root', ...array_slice($request(), 1)], LocalRoot::plain($request()));
    }

    public function test_the_broker_refuses_a_mount_outside_the_account_s_home(): void
    {
        $this->expectExceptionMessage('is not in the home of alice');

        LocalRoot::plain(Privileged::accountDocker('alice')->run('busybox', [['/etc', '/x', 'ro']], ['true']));
    }

    public function test_the_broker_refuses_a_login_directory_that_is_not_the_account_s(): void
    {
        $this->expectExceptionMessage('is not a registry login directory');

        LocalRoot::plain(Privileged::accountDocker('alice')->pull('ghcr.io/a/b', '/home/bob/.panelalpha-registry-0123456789abcdef'));
    }

    public function test_the_broker_refuses_a_digest_as_the_tag_to_set(): void
    {
        $this->expectExceptionMessage('refused');

        LocalRoot::plain(Privileged::accountDocker('alice')->tag('a:1', 'b@sha256:'.str_repeat('a', 64)));
    }
}
