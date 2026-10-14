<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Lib\Project\ApiAccessFirewall;
use App\System;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * The file the tenant firewall reads: the projects allowed to call the engine's
 * API, one name per line, root-owned and replaced whole.
 */
class ApiAccessFirewallTest extends TestCase
{
    private function repo(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/' . $path);
    }

    public function test_it_writes_the_names_the_script_reads_and_applies_the_firewall(): void
    {
        $system = $this->recordingSystem();

        (new ApiAccessFirewall($system))->apply(['alice', 'bob']);

        $this->assertSame(
            [ApiAccessFirewall::FILE => ["alice\nbob\n", 'root:root', '0644']],
            $system->writes,
        );
        $this->assertSame([TenantNetwork::firewallArgv()], $system->commands);
    }

    public function test_nothing_allowed_writes_an_empty_list(): void
    {
        $system = $this->recordingSystem();

        (new ApiAccessFirewall($system))->apply([]);

        $this->assertSame([ApiAccessFirewall::FILE => ['', 'root:root', '0644']], $system->writes);
        $this->assertSame('', ApiAccessFirewall::contents([]));
    }

    /** A name the list cannot carry would become a second line, or a second rule. */
    public function test_only_names_reach_the_file(): void
    {
        $this->assertSame("alice\n", ApiAccessFirewall::contents(['alice', '', 7]));
    }

    public function test_the_file_is_the_one_the_firewall_script_reads(): void
    {
        $script = $this->repo('scripts/tenant-network-firewall.sh');

        $this->assertSame(
            1,
            preg_match('/^API_ACCESS_FILE="\$\{PA_API_ACCESS_FILE:-([^}]*)\}"/m', $script, $matches),
        );
        $this->assertSame($matches[1], ApiAccessFirewall::FILE);
    }

    private function recordingSystem(): System
    {
        return new class extends System {
            /** @var array<string, array{0: string, 1: ?string, 2: ?string}> */
            public array $writes = [];

            /** @var list<string|list<string>> */
            public array $commands = [];

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;

                return '';
            }

            public function filesystem(): Filesystem
            {
                $system = $this;

                return new class ($system) extends Filesystem {
                    public function __construct(private System $system)
                    {
                        parent::__construct($system);
                    }

                    public function writeFileReplacingPath(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $this->system->writes[$path] = [$contents, $chown, $chmod];
                    }
                };
            }
        };
    }
}
