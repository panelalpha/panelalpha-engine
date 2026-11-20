<?php

namespace Tests\Integration\Console;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * `system:nat:build --lookup-url` is validated like the API's `lookup_url`,
 * so a typo fails rather than finding nothing and exiting 0.
 */
class BuildIpv4NatMapCommandTest extends TestCase
{
    public function test_an_invalid_lookup_url_is_refused_before_the_host_is_asked(): void
    {
        $tester = $this->runCommand(['--lookup-url' => 'not-a-url']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('The --lookup-url must be a URL.', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->app->make(Kernel::class)->all()['system:nat:build']);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
