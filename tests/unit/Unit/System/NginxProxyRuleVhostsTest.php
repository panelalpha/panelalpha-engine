<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver\NginxProxy;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A proxy rule named exactly after a domain or alias is rendered into that
 * domain's own vhost by the DinD template. Every other HTTP rule -- one with
 * no name, `_`, or a wildcard such as *.example.com -- has to get its own
 * server block in proxy-rule-*.conf. Skipping every non-`_` name rendered a
 * wildcard rule nowhere at all.
 */
class NginxProxyRuleVhostsTest extends TestCase
{
    use InMemoryDatabase;

    /** @var \ArrayObject<string, string> */
    private \ArrayObject $written;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeMainDomain($this->makeUser('alice'), 'alice.test');
    }

    public function test_a_wildcard_rule_is_rendered_though_no_domain_owns_it(): void
    {
        $this->render([$this->rule(3, 80, '*.alice.test')]);

        $files = $this->files();
        $this->assertCount(1, $files, 'the wildcard rule must be rendered somewhere');
        $conf = reset($files);
        $this->assertStringContainsString('server_name *.alice.test;', $conf);
        $this->assertStringContainsString('listen 80;', $conf);
    }

    public function test_a_rule_named_exactly_after_a_domain_is_left_to_the_domain_vhost(): void
    {
        $this->render([$this->rule(4, 8081, 'alice.test', '10.0.0.5')]);

        $this->assertSame([], $this->files(), 'a domain-owned name belongs in vhosts/alice.test.conf');
    }

    public function test_a_nameless_rule_is_still_rendered_as_a_catch_all(): void
    {
        $this->render([$this->rule(5, 8081, null)]);

        $files = $this->files();
        $this->assertCount(1, $files);
        $this->assertStringContainsString('server_name _;', reset($files));
    }

    public function test_a_generated_rule_is_never_copied_into_a_standalone_vhost(): void
    {
        $this->render([['is_generated' => true] + $this->rule(6, 8082, null)]);

        $this->assertSame([], $this->files());
    }

    /** @param list<array<string, mixed>> $rules */
    private function render(array $rules): void
    {
        $this->written = new \ArrayObject();
        $dir = sys_get_temp_dir() . '/pa-nginx-proxy-vhosts-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);

        $proxy = new class ($this->system($dir, $this->written), $dir) extends NginxProxy {
            public function __construct(System $system, private string $dir)
            {
                parent::__construct($system);
            }

            public function domainsConfigsDirPath(): string
            {
                return $this->dir;
            }

            /** @param list<array<string, mixed>> $rules */
            public function render(array $rules): void
            {
                $this->renderProxyRuleVhosts($rules, false);
            }
        };
        $proxy->render($rules);
    }

    /** @return array<string, string> */
    private function files(): array
    {
        return $this->written->getArrayCopy();
    }

    /** @param \ArrayObject<string, string> $written */
    private function system(string $dir, \ArrayObject $written): System
    {
        return new class ($dir, $written) extends System {
            /** @param \ArrayObject<string, string> $written */
            public function __construct(private string $dir, private \ArrayObject $written)
            {
            }

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->written) extends Filesystem {
                    /** @param \ArrayObject<string, string> $written */
                    public function __construct(System $system, private \ArrayObject $written)
                    {
                        parent::__construct($system);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->written[basename($path)] = $contents;
                    }
                };
            }
        };
    }

    /** @return array<string, mixed> */
    private function rule(int $id, int $port, ?string $serverName, string $upstream = 'alice'): array
    {
        return [
            'id' => $id,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => $port,
            'server_name' => $serverName,
            'server_names' => [],
            'upstream_host' => $upstream,
            'upstream_port' => 3000,
            'upstream_protocol' => 'http',
            'is_generated' => false,
        ];
    }
}
